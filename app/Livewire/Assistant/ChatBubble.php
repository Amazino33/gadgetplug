<?php

declare(strict_types=1);

namespace App\Livewire\Assistant;

use App\Actions\Assistant\CreateThreadAction;
use App\Actions\Assistant\DeleteThreadAction;
use App\Actions\Assistant\PreflightCheckAction;
use App\Actions\Assistant\RenameThreadAction;
use App\Actions\Assistant\SendMessageAction;
use App\Actions\Assistant\SetThreadStoreAction;
use App\Models\AssistantDailyUsage;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\AssistantVendorDailyUsage;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantScope;
use App\Support\Assistant\AnswerMarkdown;
use App\Support\Pos\BusinessDate;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class ChatBubble extends Component
{
    public ?int $activeThreadId = null;

    public string $messageInput = '';

    public bool $isOpen = false;

    public bool $sidebarOpen = false;

    public ?int $editingThreadId = null;

    public string $editingTitle = '';

    public ?string $errorMessage = null;

    public bool $thinking = false;

    /** The question that last failed before an answer was saved, for Retry. */
    public ?string $failedQuestion = null;

    /** Shown in the header only from this many questions left today. */
    public const QUESTIONS_LEFT_WARNING = 10;

    /**
     * The business this bubble is open in: the panel tenant at mount. Locked,
     * so a Livewire request cannot point the bubble at another business; every
     * use is re-checked against the user's access (AssistantScope).
     */
    #[Locked]
    public ?int $vendorId = null;

    public function mount(): void
    {
        $tenant = filament()->getTenant();
        $this->vendorId = $tenant instanceof Vendor ? (int) $tenant->id : null;

        if (! auth()->check() || ! AssistantAccess::isEnabledFor(auth()->user(), $this->vendor())) {
            return;
        }

        $latestThread = $this->ownThreads()
            ->orderByDesc('last_message_at')
            ->first();

        if ($latestThread) {
            $this->activeThreadId = $latestThread->id;
        }
    }

    public function getIsEnabledProperty(): bool
    {
        return auth()->check() && AssistantAccess::isEnabledFor(auth()->user(), $this->vendor());
    }

    private function vendor(): ?Vendor
    {
        return $this->vendorId ? Vendor::find($this->vendorId) : null;
    }

    private function scope(): AssistantScope
    {
        return AssistantScope::for(auth()->user(), $this->vendor());
    }

    /**
     * This user's threads in this business. Threads with no business yet are
     * listed only where there is no business to filter by.
     */
    private function ownThreads(): Builder
    {
        return AssistantThread::query()
            ->where('user_id', auth()->id())
            ->when($this->vendorId, fn (Builder $q) => $q->where('vendor_id', $this->vendorId));
    }

    /** A thread this user may touch from this bubble, or a 403. */
    private function ownThreadOrAbort(int $threadId): AssistantThread
    {
        $thread = $this->ownThreads()->whereKey($threadId)->first();

        if (! $thread) {
            abort(403, 'Unauthorized thread access.');
        }

        return $thread;
    }

    /** In a business: its daily question cap (the one users hit first). Else the per-user cap. */
    public function getDailyMessageCapProperty(): int
    {
        if ($vendor = $this->vendor()) {
            return PreflightCheckAction::vendorCap($vendor);
        }

        return (int) (AssistantGlobalSetting::current()->daily_message_cap ?? 0);
    }

    /** In a business: questions everyone in it has asked today (Lagos day). Else this user's. */
    public function getTodayMessageCountProperty(): int
    {
        if (! auth()->check()) {
            return 0;
        }

        if ($vendor = $this->vendor()) {
            return AssistantVendorDailyUsage::questionsToday((int) $vendor->id);
        }

        return (int) (AssistantDailyUsage::forToday(auth()->user())->message_count ?? 0);
    }

    /** Questions left today, or null when there is no limit. */
    public function getQuestionsLeftProperty(): ?int
    {
        $cap = $this->dailyMessageCap;

        return $cap > 0 ? max(0, $cap - $this->todayMessageCount) : null;
    }

    /** The header shows the count only when it is getting low. */
    public function getShowQuestionsLeftProperty(): bool
    {
        return $this->questionsLeft !== null && $this->questionsLeft <= self::QUESTIONS_LEFT_WARNING;
    }

    /** A stored time on the trading-day clock (BusinessDate's timezone), not UTC. */
    public function localTime(?CarbonInterface $at): ?CarbonInterface
    {
        return $at?->copy()->setTimezone(BusinessDate::timezone());
    }

    /** A time divider's label: "Today, 14:12", "Yesterday, 09:05", "Mon 9 Nov, 14:12". */
    public function dividerLabel(CarbonInterface $at): string
    {
        $local = $this->localTime($at);
        $today = BusinessDate::today();

        return match ($local->toDateString()) {
            $today => 'Today, '.$local->format('H:i'),
            BusinessDate::now()->subDay()->toDateString() => 'Yesterday, '.$local->format('H:i'),
            default => $local->format('D j M, H:i'),
        };
    }

    /** An answer as safe HTML (sanitized Markdown). */
    public function answerHtml(AssistantMessage $message): HtmlString
    {
        return AnswerMarkdown::toHtml($message->content);
    }

    /** The store a "[Store context: X]" note names, or null for any other system note. */
    public static function storeContextName(?string $content): ?string
    {
        return preg_match('/^\[Store context: (.+)\]$/u', trim((string) $content), $m) ? $m[1] : null;
    }

    /**
     * @return Collection<int, AssistantThread>
     */
    public function getThreadsProperty(): Collection
    {
        if (! auth()->check()) {
            return collect();
        }

        return $this->ownThreads()
            ->with('store')
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get();
    }

    public function getActiveThreadProperty(): ?AssistantThread
    {
        if (! auth()->check() || ! $this->activeThreadId) {
            return null;
        }

        return $this->ownThreads()
            ->with('store')
            ->whereKey($this->activeThreadId)
            ->first();
    }

    /**
     * @return Collection<int, Store>
     */
    public function getOwnedStoresProperty(): Collection
    {
        if (! auth()->check()) {
            return collect();
        }

        return $this->scope()->stores();
    }

    /**
     * @return Collection<int, AssistantMessage>
     */
    public function loadMessages(): Collection
    {
        if (! auth()->check() || ! $this->activeThreadId || ! $this->activeThread) {
            return collect();
        }

        return AssistantMessage::query()
            ->where('thread_id', $this->activeThreadId)
            ->where('user_id', auth()->id())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, AssistantMessage>
     */
    public function getMessagesProperty(): Collection
    {
        return $this->loadMessages();
    }

    public function loadThread(int $threadId): void
    {
        $this->selectThread($threadId);
    }

    public function selectThread(int $threadId): void
    {
        $thread = $this->ownThreadOrAbort($threadId);

        $this->activeThreadId = $thread->id;
        $this->sidebarOpen = false;
        $this->editingThreadId = null;
    }

    public function createNewThread(CreateThreadAction $createThreadAction): void
    {
        if (! $this->isEnabled) {
            return;
        }

        $user = auth()->user();
        $thread = $createThreadAction->execute($user, $this->vendor());

        $this->activeThreadId = $thread->id;
        $this->sidebarOpen = false;
        $this->messageInput = '';
        $this->editingThreadId = null;
    }

    public function startEditingTitle(int $threadId, string $currentTitle): void
    {
        $this->editingThreadId = $threadId;
        $this->editingTitle = $currentTitle;
    }

    public function cancelEditingTitle(): void
    {
        $this->editingThreadId = null;
        $this->editingTitle = '';
    }

    public function renameThread(int $threadId, string $title, ?RenameThreadAction $renameThreadAction = null): void
    {
        $this->editingTitle = $title;
        $this->saveThreadTitle($threadId, $renameThreadAction ?? app(RenameThreadAction::class));
    }

    public function saveThreadTitle(int $threadId, RenameThreadAction $renameThreadAction): void
    {
        $thread = $this->ownThreadOrAbort($threadId);

        $renameThreadAction->execute($thread, $this->editingTitle);
        $this->editingThreadId = null;
        $this->editingTitle = '';
    }

    public function deleteThread(int $threadId, ?DeleteThreadAction $deleteThreadAction = null): void
    {
        $thread = $this->ownThreadOrAbort($threadId);

        ($deleteThreadAction ?? app(DeleteThreadAction::class))->execute($thread);

        if ($this->activeThreadId === $threadId) {
            $this->activeThreadId = $this->ownThreads()
                ->orderByDesc('last_message_at')
                ->value('id');
        }

        $this->editingThreadId = null;
    }

    public function switchStore(int $threadId, int $storeId, ?SetThreadStoreAction $setThreadStoreAction = null): void
    {
        $thread = $this->ownThreadOrAbort($threadId);

        $this->activeThreadId = $thread->id;
        $this->selectStore($storeId, $setThreadStoreAction ?? app(SetThreadStoreAction::class), app(CreateThreadAction::class));
    }

    public function selectStore(int $storeId, SetThreadStoreAction $setThreadStoreAction, CreateThreadAction $createThreadAction): void
    {
        if (! $this->isEnabled) {
            return;
        }

        $user = auth()->user();

        $store = $this->scope()->allows($storeId) ? Store::query()->find($storeId) : null;

        if (! $store) {
            abort(403, 'Unauthorized store access.');
        }

        $thread = $this->activeThread;
        if ($thread && (int) $thread->user_id !== (int) $user->id) {
            abort(403, 'Unauthorized thread access.');
        }

        if (! $thread) {
            $thread = $createThreadAction->execute($user, $this->vendor());
            $this->activeThreadId = $thread->id;
        }

        $setThreadStoreAction->execute($thread, $store, 'manual');
    }

    public function toggleSidebar(): void
    {
        $this->sidebarOpen = ! $this->sidebarOpen;
    }

    public function sendMessage(SendMessageAction $sendMessageAction, ?string $message = null): void
    {
        if (! $this->isEnabled) {
            $this->thinking = false;

            return;
        }

        $this->errorMessage = null;
        $this->failedQuestion = null;

        $content = $message !== null && trim($message) !== '' ? trim($message) : trim($this->messageInput);
        if ($content === '') {
            $this->thinking = false;

            return;
        }

        $this->messageInput = '';

        try {
            $user = auth()->user();
            $thread = $this->activeThread;

            $result = $sendMessageAction->execute($user, $thread, $content, null, $this->vendor());

            if (isset($result['thread']) && $result['thread']) {
                $this->activeThreadId = $result['thread']->id;
            }

            // Explicitly unset cached computed properties so that messages, activeThread, and sidebar reload fresh
            unset($this->activeThread);
            unset($this->messages);
            unset($this->threads);

            // Re-run message loader to refresh component message collection
            $this->loadMessages();
        } catch (Throwable $e) {
            // Never the exception's own text: it can carry SQL, paths or other
            // internals. The full exception goes to the log, with the question.
            Log::error('Assistant chat: the question could not be answered', [
                'exception' => $e,
                'user_id' => auth()->id(),
                'vendor_id' => $this->vendorId,
                'thread_id' => $this->activeThreadId,
                'question_message_id' => $this->activeThreadId ? AssistantMessage::query()
                    ->where('thread_id', $this->activeThreadId)
                    ->where('user_id', auth()->id())
                    ->where('role', 'user')
                    ->latest('id')
                    ->value('id') : null,
            ]);

            $this->errorMessage = self::friendlyError($e);
            $this->failedQuestion = $content;
        } finally {
            $this->thinking = false;
        }
    }

    /** What the person is told when sending fails: plain words and a next step. */
    public static function friendlyError(Throwable $e): string
    {
        return match (true) {
            $e instanceof ValidationException => 'That question is too long. Keep it under 2,000 characters.',
            $e instanceof AuthorizationException => "The assistant isn't available to you here. Ask the shop owner if you need it.",
            default => "Couldn't get an answer. Check your connection and try again.",
        };
    }

    public function send(SendMessageAction $sendMessageAction, ?string $message = null): void
    {
        $this->sendMessage($sendMessageAction, $message);
    }

    public function render(): View
    {
        return view('livewire.assistant.chat-bubble');
    }
}
