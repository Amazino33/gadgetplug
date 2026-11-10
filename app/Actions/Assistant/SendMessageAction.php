<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Enums\Assistant\AnswerStatus;
use App\Exceptions\Assistant\AiProviderException;
use App\Exceptions\Assistant\AssistantCapExceeded;
use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\AssistantUnansweredQuestion;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendMessageAction
{
    public function __construct(
        protected ?AskAssistantAction $askAssistantAction = null,
    ) {
        $this->askAssistantAction = $askAssistantAction ?? app(AskAssistantAction::class);
    }

    /**
     * @return array{user_message: AssistantMessage, assistant_message: AssistantMessage, thread: AssistantThread}
     */
    /**
     * @param  Vendor|null  $vendor  The business the question is asked in — the
     *                               panel tenant. Null: no business context, and data is limited to
     *                               stores the user owns (AssistantScope).
     * @param  string  $channel  where the question came from: 'panel', or 'cli' (assistant:ask)
     */
    public function execute(User $user, ?AssistantThread $thread, string $content, ?int $storeId = null, ?Vendor $vendor = null, string $channel = 'panel'): array
    {
        $started = hrtime(true);

        // A thread belongs to one business. Continuing it from another
        // business's panel is refused, not quietly answered with either's data.
        if ($vendor && $thread && $thread->vendor_id !== null && (int) $thread->vendor_id !== (int) $vendor->id) {
            throw new AuthorizationException('That conversation belongs to a different business.');
        }

        $vendor ??= $thread?->vendor;
        $scope = AssistantScope::for($user, $vendor);

        // 1. Guard access
        if (! AssistantAccess::isEnabledFor($user, $vendor)) {
            throw new AuthorizationException('AI Assistant is currently disabled.');
        }

        // 2. Validate content BEFORE creating any thread or messages
        $trimmed = trim($content);
        if ($trimmed === '' || mb_strlen($trimmed) > 2000) {
            throw ValidationException::withMessages([
                'content' => 'Message content must not be empty and must not exceed 2000 characters.',
            ]);
        }

        // 3. Thread owner check if existing thread is provided
        if ($thread !== null && (int) $thread->user_id !== (int) $user->id) {
            throw new AuthorizationException('You do not own this assistant thread.');
        }

        // Snapshot current active store: thread.store_id is the primary store anchor
        $effectiveStoreId = $thread?->store_id ?? $storeId ?? ActiveStore::currentId() ?? $user->assistantSetting?->active_store_id;

        // Only a store this user may see in this business is remembered or
        // answered for; anything else (another business's active store, a
        // revoked branch) is dropped here rather than stored as context.
        if ($effectiveStoreId !== null && ! $scope->allows((int) $effectiveStoreId)) {
            $effectiveStoreId = null;
        }

        $activeStore = $effectiveStoreId ? Store::query()->find($effectiveStoreId) : null;
        $storeNameSnapshot = $activeStore?->name;

        // Step 1: Short transaction to persist user message and update store snapshot
        [$currentThread, $userMessage] = DB::transaction(function () use ($user, $thread, $trimmed, $effectiveStoreId, $storeNameSnapshot, $vendor) {
            $currentThread = $thread ?? AssistantThread::create([
                'user_id' => $user->id,
                'vendor_id' => $vendor?->id,
                'last_message_at' => now(),
            ]);

            if ($effectiveStoreId !== null && $user->assistantSetting) {
                $user->assistantSetting()->update(['active_store_id' => $effectiveStoreId]);
            }

            $userMessage = AssistantMessage::create([
                'thread_id' => $currentThread->id,
                'user_id' => $user->id,
                'store_id' => $effectiveStoreId,
                'store_name_snapshot' => $storeNameSnapshot,
                'role' => 'user',
                'content' => $trimmed,
            ]);

            return [$currentThread, $userMessage];
        });

        // Step 2: Ensure thread store is assigned
        $ownedStores = $scope->stores();

        if ($currentThread->store_id === null) {
            if ($ownedStores->count() === 1) {
                app(SetThreadStoreAction::class)->execute($currentThread, $ownedStores->first(), 'auto');
                $activeStore = $ownedStores->first();
                $effectiveStoreId = $activeStore->id;
                $userMessage->update([
                    'store_id' => $activeStore->id,
                    'store_name_snapshot' => $activeStore->name,
                ]);
            } elseif ($ownedStores->count() === 0) {
                $assistantMessage = AssistantMessage::create([
                    'thread_id' => $currentThread->id,
                    'user_id' => $user->id,
                    'store_id' => null,
                    'role' => 'assistant',
                    'content' => 'You have no stores assigned yet.',
                    'status' => AnswerStatus::NoStores,
                ]);
                $currentThread->update(['last_message_at' => now()]);

                return $this->finish($userMessage, $assistantMessage, $currentThread, $vendor, $started, $channel);
            } else {
                $assistantMessage = AssistantMessage::create([
                    'thread_id' => $currentThread->id,
                    'user_id' => $user->id,
                    'store_id' => null,
                    'role' => 'assistant',
                    'content' => 'Pick a store to start.',
                    'is_prompt' => true,
                    'status' => AnswerStatus::NeedsStore,
                ]);
                $currentThread->update(['last_message_at' => now()]);

                return $this->finish($userMessage, $assistantMessage, $currentThread, $vendor, $started, $channel);
            }
        } else {
            $activeStore = Store::query()->find($currentThread->store_id) ?? $currentThread->store ?? $activeStore;
        }

        // Pre-flight cap check (runs before brand-new store short-circuit and before AI calls)
        try {
            app(PreflightCheckAction::class)->execute($user, vendor: $vendor);
        } catch (AssistantCapExceeded $e) {
            $assistantMessage = DB::transaction(function () use ($currentThread, $user, $effectiveStoreId, $activeStore, $e) {
                $msg = AssistantMessage::create([
                    'thread_id' => $currentThread->id,
                    'user_id' => $user->id,
                    'store_id' => $effectiveStoreId,
                    'store_name_snapshot' => $activeStore?->name,
                    'role' => 'assistant',
                    'content' => $e->getMessage(),
                    'ai_meta' => [
                        'short_circuit' => 'cap_exceeded',
                        'failure_reason' => $e->reason,
                    ],
                    'status' => AnswerStatus::CapReached,
                ]);

                $currentThread->update([
                    'last_message_at' => now(),
                ]);

                return $msg;
            });

            return $this->finish($userMessage, $assistantMessage, $currentThread, $vendor, $started, $channel);
        }

        // Pre-flight short-circuit for brand-new stores (< 24h old, zero orders, zero stock, zero cash-ups)
        if ($activeStore !== null
            && $activeStore->created_at >= now()->subHours(24)
            && $activeStore->orders()->count() === 0
            && $activeStore->productStoreStocks()->where('quantity', '>', 0)->count() === 0
            && $activeStore->posSessions()->count() === 0
        ) {
            $assistantMessage = DB::transaction(function () use ($currentThread, $user, $effectiveStoreId, $activeStore) {
                $msg = AssistantMessage::create([
                    'thread_id' => $currentThread->id,
                    'user_id' => $user->id,
                    'store_id' => $effectiveStoreId,
                    'store_name_snapshot' => $activeStore?->name,
                    'role' => 'assistant',
                    'content' => 'This store has no activity yet. Once sales, stock, or cash-ups start coming in, ask me again.',
                    'ai_meta' => [
                        'short_circuit' => true,
                    ],
                    'status' => AnswerStatus::NewStore,
                ]);

                $currentThread->update([
                    'last_message_at' => now(),
                ]);

                return $msg;
            });

            return $this->finish($userMessage, $assistantMessage, $currentThread, $vendor, $started, $channel);
        }

        // Step 3: AI pipeline executes OUTSIDE DB transaction (no row locks held during network I/O)
        try {
            $assistantMessage = $this->askAssistantAction->execute($currentThread, $userMessage, $activeStore);

            if ($assistantMessage->content === '__failed__') {
                $category = $assistantMessage->ai_meta['failure_category'] ?? 'unknown';
                $fallbackMap = [
                    'credit_exhausted' => 'The assistant is out of credit right now. Please contact your administrator.',
                    'rate_limited' => 'The assistant is busy right now. Try again in a moment.',
                    'network_timeout' => 'The assistant took too long to respond.',
                    'provider_error' => 'The assistant is having trouble. Try again in a moment.',
                    'unknown' => 'The assistant is having trouble. Try again in a moment.',
                ];
                $assistantMessage->update([
                    'content' => $fallbackMap[$category] ?? $fallbackMap['unknown'],
                ]);
            }
        } catch (AuthorizationException|UnauthorizedStoreAccess $authException) {
            // If mid-session security check fails, rollback saved user message
            $userMessage->delete();
            if ($thread === null) {
                $currentThread->delete();
            }
            throw $authException;
        } catch (Throwable $e) {
            Log::error('Assistant AI invocation failed: '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $user->id,
                'thread_id' => $currentThread->id,
            ]);

            $failureCategory = ($e instanceof AiProviderException) ? $e->getFailureCategory() : 'unknown';
            $fallbackMap = [
                'credit_exhausted' => 'The assistant is out of credit right now. Please contact your administrator.',
                'rate_limited' => 'The assistant is busy right now. Try again in a moment.',
                'network_timeout' => 'The assistant took too long to respond.',
                'provider_error' => 'The assistant is having trouble. Try again in a moment.',
                'unknown' => 'The assistant is having trouble. Try again in a moment.',
            ];
            $fallbackContent = $fallbackMap[$failureCategory] ?? $fallbackMap['unknown'];

            $assistantMessage = DB::transaction(function () use ($currentThread, $user, $effectiveStoreId, $activeStore, $e, $failureCategory, $fallbackContent) {
                $msg = AssistantMessage::create([
                    'thread_id' => $currentThread->id,
                    'user_id' => $user->id,
                    'store_id' => $effectiveStoreId,
                    'store_name_snapshot' => $activeStore?->name,
                    'role' => 'assistant',
                    'content' => $fallbackContent,
                    'ai_meta' => [
                        'error' => $e->getMessage(),
                        'failure_category' => $failureCategory,
                    ],
                    'status' => $e instanceof AiProviderException ? AnswerStatus::ProviderError : AnswerStatus::InternalError,
                ]);

                $currentThread->update([
                    'last_message_at' => now(),
                ]);

                return $msg;
            });
        }

        return $this->finish($userMessage, $assistantMessage, $currentThread, $vendor, $started, $channel);
    }

    /**
     * The last step on every path: how long the whole question took, where it
     * came from, and, when it went unanswered, a copy for the admin to review.
     * Logging never stops the answer reaching the user.
     *
     * @return array{user_message: AssistantMessage, assistant_message: AssistantMessage, thread: AssistantThread}
     */
    private function finish(AssistantMessage $userMessage, AssistantMessage $assistantMessage, AssistantThread $thread, ?Vendor $vendor, int $started, string $channel): array
    {
        try {
            $assistantMessage->forceFill([
                'latency_ms' => (int) round((hrtime(true) - $started) / 1e6),
                'ai_meta' => [...($assistantMessage->ai_meta ?? []), 'channel' => $channel],
            ])->save();

            $status = $assistantMessage->status;

            if ($status instanceof AnswerStatus && $status->isUnanswered()) {
                $meta = $assistantMessage->ai_meta ?? [];
                $failedTools = $assistantMessage->queryRuns()->where('success', false)->pluck('query_key')->all();

                AssistantUnansweredQuestion::create([
                    'vendor_id' => $vendor?->id ?? $thread->vendor_id,
                    'user_id' => $userMessage->user_id,
                    'thread_id' => $thread->id,
                    'answer_message_id' => $assistantMessage->id,
                    'question' => (string) $userMessage->content,
                    'answer' => (string) $assistantMessage->content,
                    'reason' => $status,
                    'detail' => collect([
                        isset($meta['failure_category']) ? 'category: '.$meta['failure_category'] : null,
                        $failedTools !== [] ? 'failed tools: '.implode(', ', $failedTools) : null,
                        isset($meta['error']) ? 'error: '.mb_substr((string) $meta['error'], 0, 500) : null,
                    ])->filter()->implode("\n") ?: null,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Assistant answer logging failed: '.$e->getMessage(), ['message_id' => $assistantMessage->id]);
        }

        return [
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'thread' => $thread,
        ];
    }
}
