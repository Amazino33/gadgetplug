<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Exceptions\Assistant\AiProviderException;
use App\Exceptions\Assistant\AiRateLimitException;
use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Models\AssistantDailyUsage;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantQueryRun;
use App\Models\AssistantThread;
use App\Models\Store;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\CostCalculator;
use App\Services\Assistant\PromptBuilder;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AskAssistantAction
{
    public function __construct(
        protected AiClient $aiClient,
        protected PromptBuilder $promptBuilder,
        protected AssistantQueryRegistry $registry,
    ) {}

    /**
     * Executes the AI query pipeline against OpenRouter and the query registry.
     *
     * @throws AuthorizationException
     * @throws UnauthorizedStoreAccess
     */
    public function execute(
        AssistantThread $thread,
        AssistantMessage $userMessage,
        ?Store $activeStore = null,
    ): AssistantMessage {
        $user = $userMessage->user;

        // 1. Guard AssistantAccess and thread ownership
        if (! AssistantAccess::isEnabledFor($user)) {
            throw new AuthorizationException('Assistant access is currently disabled for this user.');
        }

        if ((int) $thread->user_id !== (int) $user->id) {
            throw new AuthorizationException('You do not own this assistant thread.');
        }

        // 2. Active store defense-in-depth ownership verification
        if ($activeStore !== null && ! $user->isSuperAdmin()) {
            $isOwner = Store::query()
                ->where('id', $activeStore->id)
                ->whereHas('vendor', fn ($v) => $v->where('user_id', $user->id))
                ->exists();

            if (! $isOwner) {
                throw new UnauthorizedStoreAccess("Store [{$activeStore->id}] is not owned by the authenticated vendor.");
            }
        }

        // 3. Load global settings at runtime (not cached)
        $settings = AssistantGlobalSetting::current();
        $historyWindow = (int) ($settings->history_window ?? 20);
        $modelName = (string) ($settings->model_name ?? 'openai/gpt-4o-mini');
        $maxTokens = (int) ($settings->max_tokens ?? 800);

        // 4. Build prompt & message history
        $ownedStoreNames = Store::query()
            ->whereHas('vendor', fn ($v) => $v->where('user_id', $user->id))
            ->pluck('name')
            ->all();

        $systemPrompt = $this->promptBuilder->systemPrompt(now(), $activeStore, $ownedStoreNames);
        $historyMessages = $this->promptBuilder->history($thread, $historyWindow, $userMessage->id);

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $historyMessages,
            [['role' => 'user', 'content' => (string) $userMessage->content]]
        );

        // 5. Tool schemas from query registry + switch_store tool
        $tools = array_merge(
            $this->promptBuilder->toolSchema($this->registry),
            [$this->promptBuilder->switchStoreTool()]
        );

        // 6. First call to AI
        $aiCallStarted = false;
        $tokensIn = 0;
        $tokensOut = 0;
        $finishReason = 'stop';
        $failureCategory = null;
        $errorMessage = null;
        $queryRunsData = [];
        $toolUsed = false;
        $toolKey = null;
        $noToolUsed = false;
        $extraToolCalls = [];
        $switchedToStoreId = null;
        $finalContent = null;

        try {
            $aiCallStarted = true;

            try {
                $response = $this->aiClient->chat($messages, $tools, $modelName, $maxTokens);
                $tokensIn = (int) ($response['usage']['prompt_tokens'] ?? 0);
                $tokensOut = (int) ($response['usage']['completion_tokens'] ?? 0);
                $finishReason = (string) ($response['finish_reason'] ?? 'stop');
                $finalContent = $response['content'] ?? null;
            } catch (AiProviderException|AiRateLimitException $e) {
                $failureCategory = $e->getFailureCategory();
                $errorMessage = $e->getMessage();
                $finalContent = '__failed__';
            } catch (Throwable $e) {
                $failureCategory = 'unknown';
                $errorMessage = $e->getMessage();
                $finalContent = '__failed__';
            }

            // 7. Tool calling flow (only if no failure on first call)
            if ($failureCategory === null && $finishReason === 'tool_calls' && ! empty($response['tool_calls'])) {
                $assistantTurn = [
                    'role' => 'assistant',
                    'content' => $response['content'] ?? null,
                    'tool_calls' => $response['tool_calls'],
                ];

                $toolCalls = $response['tool_calls'];
                $extraToolCalls = array_slice($toolCalls, 1);
                $toolUsed = true;
                $toolKey = (string) ($toolCalls[0]['function']['name'] ?? '');

                $toolTurns = [];
                $atLeastOneQuerySuccess = false;
                $maxToolExecutions = 3;

                foreach ($toolCalls as $index => $toolCall) {
                    $toolCallId = (string) ($toolCall['id'] ?? 'call_'.($index + 1));
                    $queryKey = (string) ($toolCall['function']['name'] ?? '');
                    $rawArgs = $toolCall['function']['arguments'] ?? '{}';
                    $args = is_array($rawArgs) ? $rawArgs : (json_decode((string) $rawArgs, true) ?: []);

                    // Limit execution to a maximum of 3 tool calls
                    if ($index >= $maxToolExecutions) {
                        $toolTurns[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId,
                            'name' => $queryKey,
                            'content' => (string) json_encode([
                                'error' => 'Execution limit reached.',
                            ]),
                        ];

                        continue;
                    }

                    if ($queryKey === 'switch_store') {
                        $targetStoreName = trim((string) ($args['store_name'] ?? ''));
                        $resolved = app(SetThreadStoreAction::class)->resolveStoreByName((int) $user->id, $targetStoreName);

                        if ($resolved['store'] === null) {
                            $toolPayload = ['error' => $resolved['error'] ?? 'No store with that exact name, or multiple matches.'];
                            $querySuccess = false;
                        } else {
                            $matchedStore = $resolved['store'];
                            app(SetThreadStoreAction::class)->execute($thread, $matchedStore, 'ai');
                            $toolPayload = ['success' => true, 'store' => ['name' => $matchedStore->name]];
                            $activeStore = $matchedStore;
                            $switchedToStoreId = $matchedStore->id;
                            $querySuccess = true;
                            $atLeastOneQuerySuccess = true;
                        }
                    } else {
                        // Build scope
                        if (isset($args['store_ids']) && is_array($args['store_ids'])) {
                            $scope = ['store_ids' => $args['store_ids']];
                        } elseif (isset($args['store_id'])) {
                            $scope = ['store_id' => (int) $args['store_id']];
                        } elseif ($activeStore !== null) {
                            $scope = ['store_id' => $activeStore->id];
                        } else {
                            $scope = [];
                        }

                        // Build dates (defaults to last 7 days)
                        $from = isset($args['date_from'])
                            ? Carbon::parse((string) $args['date_from'])->startOfDay()
                            : now()->subDays(7)->startOfDay();

                        if (isset($args['date_to'])) {
                            $parsedTo = Carbon::parse((string) $args['date_to']);
                            $to = $parsedTo->isToday() ? now() : $parsedTo->endOfDay();
                        } else {
                            $to = now();
                        }
                        $options = isset($args['options']) && is_array($args['options']) ? $args['options'] : [];
                        if (isset($args['customer_name']) && ! isset($options['customer_name'])) {
                            $options['customer_name'] = $args['customer_name'];
                        }
                        if (isset($args['threshold']) && ! isset($options['threshold'])) {
                            $options['threshold'] = $args['threshold'];
                        }
                        if (isset($args['term']) && ! isset($options['term'])) {
                            $options['term'] = $args['term'];
                        }

                        // Execute query with duration measurement
                        $startTime = microtime(true);
                        $queryResult = null;
                        $queryError = null;
                        $querySuccess = false;

                        try {
                            $queryAction = $this->registry->get($queryKey);
                            $queryResult = $queryAction->execute($scope, $from, $to, $options);
                            $querySuccess = true;
                            $atLeastOneQuerySuccess = true;
                        } catch (Throwable $e) {
                            $queryError = $e->getMessage();
                            $querySuccess = false;
                        }

                        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

                        $queryRunsData[] = [
                            'query_key' => $queryKey,
                            'scope_json' => $scope,
                            'date_from' => $from->toDateString(),
                            'date_to' => $to->toDateString(),
                            'result_json' => $queryResult,
                            'success' => $querySuccess,
                            'has_data' => $querySuccess ? ($queryResult['has_data'] ?? true) : true,
                            'failure_reason' => $querySuccess ? ($queryResult['reason'] ?? null) : null,
                            'error_message' => $queryError,
                            'duration_ms' => $durationMs,
                        ];

                        $toolPayload = $querySuccess
                            ? $queryResult
                            : ['error' => $queryError];
                    }

                    $toolTurns[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'name' => $queryKey,
                        'content' => (string) json_encode($toolPayload),
                    ];
                }

                $secondMessages = array_merge($messages, [$assistantTurn], $toolTurns);

                try {
                    $secondResponse = $this->aiClient->chat($secondMessages, [], $modelName, $maxTokens);
                    $tokensIn += (int) ($secondResponse['usage']['prompt_tokens'] ?? 0);
                    $tokensOut += (int) ($secondResponse['usage']['completion_tokens'] ?? 0);
                    $finalContent = $secondResponse['content'] ?? null;
                } catch (Throwable $e) {
                    Log::error('Second AI call failed: '.$e->getMessage(), [
                        'exception' => $e,
                    ]);
                    $finalContent = $atLeastOneQuerySuccess
                        ? 'I fetched your data but had trouble phrasing it — try again.'
                        : "I couldn't fetch that right now.";
                }
            } elseif ($failureCategory === null) {
                // Direct answer without tool
                $noToolUsed = true;
            }

            // 8. Enforce content safety, length limits, and fallback
            if ($failureCategory === null) {
                $finalContent = is_string($finalContent) ? trim($finalContent) : '';

                if ($finalContent === '' || strlen($finalContent) > 4000) {
                    $finalContent = 'Sorry, I had trouble answering that.';
                } elseif (str_starts_with($finalContent, '{') && str_ends_with($finalContent, '}') && json_decode($finalContent) !== null) {
                    // Reject raw JSON leaks
                    $finalContent = 'Sorry, I had trouble answering that.';
                }
            }

            // 9. Persist inside short DB transaction
            return DB::transaction(function () use (
                $thread,
                $user,
                $activeStore,
                $finalContent,
                $modelName,
                $tokensIn,
                $tokensOut,
                $finishReason,
                $toolUsed,
                $toolKey,
                $switchedToStoreId,
                $noToolUsed,
                $extraToolCalls,
                $failureCategory,
                $errorMessage,
                $queryRunsData
            ) {
                $aiMeta = [
                    'model' => $modelName,
                    'tokens_in' => $tokensIn,
                    'tokens_out' => $tokensOut,
                    'finish_reason' => $finishReason,
                    'tool_used' => $toolUsed,
                    'tool_key' => $toolKey,
                    'switched_to_store_id' => $switchedToStoreId,
                    'no_tool_used' => $noToolUsed,
                    'extra_tool_calls' => $extraToolCalls,
                ];

                if ($failureCategory !== null) {
                    $aiMeta['failure_category'] = $failureCategory;
                }

                if ($errorMessage !== null) {
                    $aiMeta['error'] = $errorMessage;
                }

                $assistantMessage = AssistantMessage::create([
                    'thread_id' => $thread->id,
                    'user_id' => $user->id,
                    'store_id' => $activeStore?->id,
                    'store_name_snapshot' => $activeStore?->name,
                    'role' => 'assistant',
                    'content' => $finalContent,
                    'ai_meta' => $aiMeta,
                ]);

                foreach ($queryRunsData as $runData) {
                    $runData['message_id'] = $assistantMessage->id;
                    AssistantQueryRun::create($runData);
                }

                $thread->update(['last_message_at' => now()]);

                return $assistantMessage;
            });
        } finally {
            if ($aiCallStarted) {
                $costMicros = CostCalculator::costMicros($modelName, $tokensIn, $tokensOut);
                DB::transaction(function () use ($user, $tokensIn, $tokensOut, $costMicros) {
                    $usage = AssistantDailyUsage::query()
                        ->where('user_id', $user->id)
                        ->where('date', today())
                        ->lockForUpdate()
                        ->first();

                    if (! $usage) {
                        try {
                            $usage = AssistantDailyUsage::create([
                                'user_id' => $user->id,
                                'date' => today(),
                                'message_count' => 0,
                                'input_tokens' => 0,
                                'output_tokens' => 0,
                                'cost_micros' => 0,
                            ]);
                        } catch (Throwable) {
                            $usage = AssistantDailyUsage::query()
                                ->where('user_id', $user->id)
                                ->where('date', today())
                                ->lockForUpdate()
                                ->first();
                        }
                    }

                    $usage->increment('message_count', 1);
                    $usage->increment('input_tokens', $tokensIn);
                    $usage->increment('output_tokens', $tokensOut);
                    $usage->increment('cost_micros', $costMicros);
                });
            }
        }
    }
}
