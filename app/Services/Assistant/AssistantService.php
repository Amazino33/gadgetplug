<?php

namespace App\Services\Assistant;

use App\Models\Assistant\AssistantConversation;
use App\Models\Assistant\AssistantMessage;
use App\Models\Assistant\AssistantQuestion;
use App\Models\Assistant\AssistantUnansweredQuestion;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\Llm\LanguageModel;
use App\Services\Assistant\Llm\LanguageModelException;
use App\Services\Assistant\Llm\ModelTurn;
use App\Services\Assistant\Tools\FlagUnansweredTool;
use App\Services\Assistant\Tools\ToolRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Ask GadgetPlug: one question in, one answer out.
 *
 * Channel-agnostic on purpose. The panel chat (Phase 1B) and WhatsApp
 * (Phase 12) both call ask() with a user and vendor they have already
 * authenticated; nothing here reads the session, the request or Filament's
 * tenant, so neither channel can be more permissive than the other.
 *
 * Order of checks, each before any model call:
 *   1. use_ai_assistant          (not counted toward the cap)
 *   2. conversation ownership    (an exception: a forged id is not a question)
 *   3. the vendor's daily cap    (not counted toward the cap)
 * then the tool loop, with every tool call going through ToolRegistry::run().
 */
final class AssistantService
{
    public const MAX_QUESTION_LENGTH = 2000;

    public function __construct(
        private LanguageModel $model,
        private ToolRegistry $tools,
        private DailyCap $cap,
    ) {}

    /**
     * @throws AuthorizationException the conversation belongs to someone else
     * @throws InvalidArgumentException the question is empty or too long
     */
    public function ask(User $user, Vendor $vendor, string $question, ?AssistantConversation $conversation = null, string $channel = 'panel'): AssistantReply
    {
        $question = trim($question);

        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
            throw new InvalidArgumentException('A question must be between 1 and '.self::MAX_QUESTION_LENGTH.' characters.');
        }

        if ($conversation && ! $conversation->isOwnedBy($user, $vendor)) {
            throw new AuthorizationException('That conversation is not yours.');
        }

        $context = AssistantContext::for($vendor, $user, $channel);

        if (! $context->can('use_ai_assistant')) {
            return $this->shortCircuit($context, $question, $conversation,
                AssistantQuestion::STATUS_NO_ACCESS, "You don't have access to the assistant. Ask the business owner if you need it.");
        }

        if ($this->cap->reached($vendor)) {
            return $this->shortCircuit($context, $question, $conversation,
                AssistantQuestion::STATUS_CAPPED, $this->cap->limitMessage($vendor));
        }

        $conversation ??= AssistantConversation::create([
            'vendor_id' => $vendor->id,
            'user_id' => $user->id,
            'channel' => $channel,
            'title' => mb_substr($question, 0, 80),
        ]);

        $history = $this->history($conversation);

        $conversation->messages()->create(['role' => AssistantMessage::ROLE_USER, 'content' => $question]);

        $log = AssistantQuestion::create([
            'vendor_id' => $vendor->id,
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'channel' => $channel,
            'question' => $question,
            'status' => AssistantQuestion::STATUS_PENDING,
            'model' => config('assistant.model'),
        ]);

        $outcome = $this->run($context, $log, [...$history, ['role' => 'user', 'content' => $question]]);

        $conversation->messages()->create(['role' => AssistantMessage::ROLE_ASSISTANT, 'content' => $outcome['answer']]);
        $conversation->update(['last_message_at' => now()]);

        $log->update([
            'answer' => $outcome['answer'],
            'status' => $outcome['status'],
            'tools_called' => $outcome['tools'],
            'rounds' => $outcome['rounds'],
            'input_tokens' => $outcome['usage']['input'],
            'output_tokens' => $outcome['usage']['output'],
            'cache_read_tokens' => $outcome['usage']['cache_read'],
            'cache_write_tokens' => $outcome['usage']['cache_write'],
            'latency_ms' => $outcome['latency_ms'],
            'error' => $outcome['error'],
            'model' => $outcome['model'] ?? $log->model,
        ]);

        if ($outcome['unanswered']) {
            AssistantUnansweredQuestion::create([
                'question_id' => $log->id,
                'vendor_id' => $vendor->id,
                'user_id' => $user->id,
                'question' => $question,
                'reason' => $outcome['unanswered']['reason'],
                'detail' => $outcome['unanswered']['detail'],
            ]);
        }

        return new AssistantReply($outcome['answer'], $outcome['status'], $conversation, $log->fresh());
    }

    /**
     * The tool loop. Never throws: every way it can end becomes an answer the
     * user can read and a status the log can count.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{answer: string, status: string, tools: array<int, string>, rounds: int, usage: array<string, int>, latency_ms: int, error: ?string, unanswered: ?array{reason: string, detail: ?string}, model: ?string}
     */
    private function run(AssistantContext $context, AssistantQuestion $log, array $messages): array
    {
        $started = hrtime(true);
        $system = SystemPrompt::blocks($context);
        $definitions = $this->tools->definitions();
        $maxRounds = (int) config('assistant.max_tool_rounds', 6);

        $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];
        $toolsCalled = [];
        $unanswered = null;
        $model = null;
        $rounds = 0;

        $finish = function (string $answer, string $status, ?string $error = null) use (&$usage, &$toolsCalled, &$unanswered, &$rounds, &$model, $started): array {
            if ($status === AssistantQuestion::STATUS_ANSWERED && $unanswered) {
                $status = AssistantQuestion::STATUS_UNANSWERED;
            }

            return [
                'answer' => $answer,
                'status' => $status,
                'tools' => array_values(array_unique($toolsCalled)),
                'rounds' => $rounds,
                'usage' => $usage,
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error' => $error ? mb_substr($error, 0, 2000) : null,
                'unanswered' => $unanswered,
                'model' => $model,
            ];
        };

        try {
            while (true) {
                $turn = $this->model->respond($system, $definitions, $messages);
                $rounds++;
                $model = $turn->model ?? $model;
                $this->addUsage($usage, $turn);

                if ($turn->isRefusal()) {
                    $unanswered = ['reason' => 'refused', 'detail' => null];

                    return $finish("Sorry, I can't help with that one.", AssistantQuestion::STATUS_REFUSED);
                }

                if (! $turn->wantsTools()) {
                    $answer = $turn->text !== '' ? $turn->text : "Sorry, I couldn't put an answer together for that. Please try asking another way.";

                    return $finish($answer, $turn->text !== '' ? AssistantQuestion::STATUS_ANSWERED : AssistantQuestion::STATUS_UNANSWERED);
                }

                if ($rounds > $maxRounds) {
                    $unanswered = ['reason' => 'too_many_steps', 'detail' => "Stopped after {$maxRounds} rounds of look-ups."];

                    return $finish(
                        'Sorry, that needed more look-ups than I can do in one go. Try asking a narrower question, for one store or one period.',
                        AssistantQuestion::STATUS_UNANSWERED,
                    );
                }

                $results = [];

                foreach ($turn->toolUses as $use) {
                    $run = $this->tools->run($use['name'], $use['input'], $context, $log);

                    if ($use['name'] === FlagUnansweredTool::NAME) {
                        $unanswered ??= [
                            'reason' => in_array($use['input']['reason'] ?? null, ['no_tool', 'no_data', 'ambiguous'], true) ? $use['input']['reason'] : 'no_tool',
                            'detail' => isset($use['input']['detail']) ? mb_substr((string) $use['input']['detail'], 0, 500) : null,
                        ];
                    } elseif (! ($run['tool']?->isInternal() ?? false)) {
                        $toolsCalled[] = $use['name'];
                    }

                    $results[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $use['id'],
                        'content' => $run['content'],
                        'isError' => $run['is_error'],
                    ];
                }

                // The assistant turn goes back exactly as it came, then every
                // result in one user turn — splitting results across turns
                // teaches the model to stop calling tools in parallel.
                $messages[] = ['role' => 'assistant', 'content' => $turn->content];
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (LanguageModelException $e) {
            Log::warning('Assistant model call failed', ['question_id' => $log->id, 'error' => $e->getMessage()]);
            $unanswered = ['reason' => 'error', 'detail' => 'The model could not be reached.'];

            return $finish('Sorry, the assistant is not available right now. Please try again in a few minutes.', AssistantQuestion::STATUS_ERROR, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Assistant failed', ['question_id' => $log->id, 'exception' => $e]);
            $unanswered = ['reason' => 'error', 'detail' => 'Unexpected error.'];

            return $finish('Sorry, something went wrong answering that. Please try again.', AssistantQuestion::STATUS_ERROR, $e->getMessage());
        }
    }

    /**
     * Earlier turns of this conversation, text only and oldest first. Tool
     * calls are never replayed: a follow-up calls the tools again, so it
     * answers with today's figures, not ones remembered from an hour ago.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function history(AssistantConversation $conversation): array
    {
        $limit = (int) config('assistant.history_messages', 20);

        $turns = $conversation->messages()
            ->reorder('id', 'desc')
            ->limit($limit)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn (AssistantMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        // A request must open with the user. A window that starts on an
        // assistant turn loses that one turn rather than the whole history.
        while ($turns !== [] && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        return $turns;
    }

    /**
     * Refused before reaching the model: logged, so the admin can see it
     * happened, but never counted against the vendor's daily allowance.
     */
    private function shortCircuit(AssistantContext $context, string $question, ?AssistantConversation $conversation, string $status, string $answer): AssistantReply
    {
        $log = AssistantQuestion::create([
            'vendor_id' => $context->vendor->id,
            'user_id' => $context->user->id,
            'conversation_id' => $conversation?->id,
            'channel' => $context->channel,
            'question' => $question,
            'answer' => $answer,
            'status' => $status,
            'counts_toward_cap' => false,
            'latency_ms' => 0,
        ]);

        return new AssistantReply($answer, $status, $conversation, $log);
    }

    /** @param  array<string, int>  $usage */
    private function addUsage(array &$usage, ModelTurn $turn): void
    {
        $usage['input'] += $turn->inputTokens;
        $usage['output'] += $turn->outputTokens;
        $usage['cache_read'] += $turn->cacheReadTokens;
        $usage['cache_write'] += $turn->cacheWriteTokens;
    }
}
