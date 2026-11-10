<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Enums\Assistant\AnswerStatus;
use App\Exceptions\Assistant\AiProviderException;
use App\Exceptions\Assistant\AiRateLimitException;
use App\Exceptions\Assistant\InvalidPeriod;
use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Models\AssistantDailyUsage;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantQueryRun;
use App\Models\AssistantThread;
use App\Models\AssistantVendorDailyUsage;
use App\Models\Store;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantPeriod;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\AssistantScope;
use App\Services\Assistant\CostCalculator;
use App\Services\Assistant\PromptBuilder;
use App\Support\Pos\BusinessDate;
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

        // The question is answered for the business the thread belongs to,
        // and every query the model calls reads this same scope (see
        // BaseAssistantQuery::accessScope()), not whatever auth() says.
        $scope = AssistantScope::for($user, $thread->vendor);

        return $scope->run(fn () => $this->answer($thread, $userMessage, $activeStore, $scope));
    }

    private function answer(
        AssistantThread $thread,
        AssistantMessage $userMessage,
        ?Store $activeStore,
        AssistantScope $scope,
    ): AssistantMessage {
        $user = $userMessage->user;

        // 1. Guard AssistantAccess and thread ownership
        if (! AssistantAccess::isEnabledFor($user, $thread->vendor)) {
            throw new AuthorizationException('Assistant access is currently disabled for this user.');
        }

        if ((int) $thread->user_id !== (int) $user->id) {
            throw new AuthorizationException('You do not own this assistant thread.');
        }

        // 2. Active store defense-in-depth: one this user may see in this business
        if ($activeStore !== null && ! $scope->allows((int) $activeStore->id)) {
            throw new UnauthorizedStoreAccess("Store [{$activeStore->id}] is not accessible to this user in this business.");
        }

        // 3. Load global settings at runtime (not cached)
        $settings = AssistantGlobalSetting::current();
        $historyWindow = (int) ($settings->history_window ?? 20);
        $modelName = (string) ($settings->model_name ?? 'openai/gpt-4o-mini');
        $maxTokens = (int) ($settings->max_tokens ?? 800);

        // 4. Build prompt & message history
        $ownedStoreNames = $scope->storeNames();

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
        $phrasingFailed = false;
        $costMicros = null;

        // Usage across every model call this question makes (Phase 1.3).
        $cacheRead = 0;
        $cacheWrite = 0;
        $providerCostUsd = null;
        $modelMs = 0;
        $rounds = 0;
        $absorb = function (array $usage) use (&$cacheRead, &$cacheWrite, &$providerCostUsd): void {
            $cacheRead += (int) ($usage['cache_read_tokens'] ?? 0);
            $cacheWrite += (int) ($usage['cache_write_tokens'] ?? 0);

            if (isset($usage['cost_usd']) && is_numeric($usage['cost_usd'])) {
                $providerCostUsd = ($providerCostUsd ?? 0.0) + (float) $usage['cost_usd'];
            }
        };

        try {
            $aiCallStarted = true;

            try {
                $rounds++;
                $callStarted = hrtime(true);

                try {
                    $response = $this->aiClient->chat($messages, $tools, $modelName, $maxTokens);
                } finally {
                    $modelMs += (int) round((hrtime(true) - $callStarted) / 1e6);
                }

                $tokensIn = (int) ($response['usage']['prompt_tokens'] ?? 0);
                $tokensOut = (int) ($response['usage']['completion_tokens'] ?? 0);
                $absorb($response['usage'] ?? []);
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
                        $resolved = app(SetThreadStoreAction::class)->resolveStoreByName((int) $user->id, $targetStoreName, $scope);

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
                        // The stores this call asks about. Its own name: $scope
                        // is the asker's access, which a later switch_store in
                        // the same reply still needs.
                        if (isset($args['store_ids']) && is_array($args['store_ids'])) {
                            $queryScope = ['store_ids' => $args['store_ids']];
                        } elseif (isset($args['store_id'])) {
                            $queryScope = ['store_id' => (int) $args['store_id']];
                        } elseif ($activeStore !== null) {
                            $queryScope = ['store_id' => $activeStore->id];
                        } else {
                            $queryScope = [];
                        }

                        // Dates are worked out in code on the Lagos day
                        // (AssistantPeriod): a named period or plain dates, and
                        // without either, the tool's own default, never a
                        // silent "last 7 days". Tools that take no dates get none.
                        $defaultPeriod = $this->registry->defaultPeriod($queryKey);
                        $period = null;
                        $periodError = null;

                        try {
                            $period = $defaultPeriod === null ? null : AssistantPeriod::resolve($args, $defaultPeriod);
                        } catch (InvalidPeriod $e) {
                            $periodError = $e->getMessage();
                        }

                        $from = $period?->from() ?? BusinessDate::boundsFor(BusinessDate::today())[0];
                        $to = $period?->to() ?? now();
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
                            if ($periodError !== null) {
                                throw new InvalidPeriod($periodError);
                            }

                            $queryAction = $this->registry->get($queryKey);
                            $queryResult = $queryAction->execute($queryScope, $from, $to, $options);

                            // The answer states which dates it covered. A refusal
                            // stays bare: nothing about it but that it was refused.
                            if ($period !== null && ($queryResult['reason'] ?? null) !== 'no_access') {
                                $queryResult['period'] = $period->toArray();
                            }

                            $querySuccess = true;
                            $atLeastOneQuerySuccess = true;
                        } catch (Throwable $e) {
                            $queryError = $e->getMessage();
                            $querySuccess = false;
                        }

                        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

                        $queryRunsData[] = [
                            'query_key' => $queryKey,
                            'scope_json' => $queryScope,
                            // Lagos calendar dates the tool covered.
                            'date_from' => $period?->fromDate->toDateString() ?? BusinessDate::today(),
                            'date_to' => $period?->toDate->toDateString() ?? BusinessDate::today(),
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
                        // The real ₦ sign, not its JSON escape: the model copied the escape into a reply once.
                        'content' => (string) json_encode($toolPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }

                $secondMessages = array_merge($messages, [$assistantTurn], $toolTurns);

                try {
                    $rounds++;
                    $callStarted = hrtime(true);

                    try {
                        $secondResponse = $this->aiClient->chat($secondMessages, [], $modelName, $maxTokens);
                    } finally {
                        $modelMs += (int) round((hrtime(true) - $callStarted) / 1e6);
                    }

                    $tokensIn += (int) ($secondResponse['usage']['prompt_tokens'] ?? 0);
                    $tokensOut += (int) ($secondResponse['usage']['completion_tokens'] ?? 0);
                    $absorb($secondResponse['usage'] ?? []);
                    $finalContent = $secondResponse['content'] ?? null;
                } catch (Throwable $e) {
                    Log::error('Second AI call failed: '.$e->getMessage(), [
                        'exception' => $e,
                    ]);
                    $phrasingFailed = true;
                    $errorMessage ??= $e->getMessage();
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
                    $phrasingFailed = true;
                } elseif (str_starts_with($finalContent, '{') && str_ends_with($finalContent, '}') && json_decode($finalContent) !== null) {
                    // Reject raw JSON leaks
                    $finalContent = 'Sorry, I had trouble answering that.';
                    $phrasingFailed = true;
                }
            }

            $status = self::statusFor($failureCategory, $phrasingFailed, $toolUsed, $queryRunsData, is_string($finalContent) ? $finalContent : '');

            // What the question cost: OpenRouter's billed figure when it gave
            // one (it prices cache reads and writes properly), else our rate table.
            $costMicros = $providerCostUsd !== null
                ? (int) round($providerCostUsd * 1_000_000)
                : CostCalculator::costMicros($modelName, $tokensIn, $tokensOut);

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
                $queryRunsData,
                $status,
                $cacheRead,
                $cacheWrite,
                $costMicros,
                $providerCostUsd,
                $modelMs,
                $rounds
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
                    // Phase 1.3. Cache tokens are part of tokens_in.
                    'cache_read_tokens' => $cacheRead,
                    'cache_write_tokens' => $cacheWrite,
                    'cost_micros' => $costMicros,
                    'cost_source' => $providerCostUsd !== null ? 'provider' : 'rate_table',
                    'model_ms' => $modelMs,
                    'rounds' => $rounds,
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
                    'status' => $status,
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
                $costMicros ??= CostCalculator::costMicros($modelName, $tokensIn, $tokensOut);

                // Counted on the Lagos trading day, so every cap resets at Lagos midnight.
                DB::transaction(function () use ($user, $tokensIn, $tokensOut, $costMicros) {
                    $today = AssistantDailyUsage::day();
                    $usage = AssistantDailyUsage::query()
                        ->where('user_id', $user->id)
                        ->where('date', $today)
                        ->lockForUpdate()
                        ->first();

                    if (! $usage) {
                        try {
                            $usage = AssistantDailyUsage::create([
                                'user_id' => $user->id,
                                'date' => $today,
                                'message_count' => 0,
                                'input_tokens' => 0,
                                'output_tokens' => 0,
                                'cost_micros' => 0,
                            ]);
                        } catch (Throwable) {
                            $usage = AssistantDailyUsage::query()
                                ->where('user_id', $user->id)
                                ->where('date', $today)
                                ->lockForUpdate()
                                ->first();
                        }
                    }

                    $usage->increment('message_count', 1);
                    $usage->increment('input_tokens', $tokensIn);
                    $usage->increment('output_tokens', $tokensOut);
                    $usage->increment('cost_micros', $costMicros);
                });

                // And against the business's daily question cap.
                if ($thread->vendor_id !== null) {
                    AssistantVendorDailyUsage::record((int) $thread->vendor_id, [
                        'input_tokens' => $tokensIn,
                        'output_tokens' => $tokensOut,
                        'cache_read_tokens' => $cacheRead,
                        'cache_write_tokens' => $cacheWrite,
                        'cost_micros' => $costMicros,
                    ]);
                }
            }
        }
    }

    /**
     * How the question went, from what happened rather than what the reply
     * says, except "I can't check that yet": the exact phrase the prompt
     * makes the model use when no tool covers the question.
     *
     * @param  array<int, array<string, mixed>>  $runs  the query runs (switch_store is not one)
     */
    public static function statusFor(?string $failureCategory, bool $phrasingFailed, bool $toolUsed, array $runs, string $reply): AnswerStatus
    {
        if ($failureCategory !== null) {
            return AnswerStatus::ProviderError;
        }

        if ($runs !== [] && collect($runs)->every(fn (array $r) => ! $r['success'])) {
            return AnswerStatus::ToolError;
        }

        if ($phrasingFailed) {
            return AnswerStatus::PhrasingFailed;
        }

        if ($runs !== []) {
            $succeeded = collect($runs)->where('success', true);

            if ($succeeded->contains(fn (array $r) => ($r['failure_reason'] ?? null) === 'no_access')) {
                return AnswerStatus::NoAccess;
            }

            if ($succeeded->every(fn (array $r) => ! ($r['has_data'] ?? true))) {
                return AnswerStatus::NoData;
            }

            return AnswerStatus::Answered;
        }

        if (! $toolUsed && str_contains(mb_strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", $reply)), "i can't check that yet")) {
            return AnswerStatus::CannotAnswer;
        }

        return AnswerStatus::Answered;
    }
}
