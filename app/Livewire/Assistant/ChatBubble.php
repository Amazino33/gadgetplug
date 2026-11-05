<?php

declare(strict_types=1);

namespace App\Livewire\Assistant;

use App\Actions\Assistant\CreateThreadAction;
use App\Actions\Assistant\DeleteThreadAction;
use App\Actions\Assistant\RenameThreadAction;
use App\Actions\Assistant\SendMessageAction;
use App\Actions\Assistant\SetThreadStoreAction;
use App\Models\AssistantDailyUsage;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Store;
use App\Services\Assistant\AssistantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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

    public function mount(): void
    {
        if (! auth()->check() || ! AssistantAccess::isEnabledFor(auth()->user())) {
            return;
        }

        $latestThread = AssistantThread::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('last_message_at')
            ->first();

        if ($latestThread) {
            $this->activeThreadId = $latestThread->id;
        }
    }

    public function getIsEnabledProperty(): bool
    {
        return auth()->check() && AssistantAccess::isEnabledFor(auth()->user());
    }

    public function getDailyMessageCapProperty(): int
    {
        return (int) (AssistantGlobalSetting::current()->daily_message_cap ?? 0);
    }

    public function getTodayMessageCountProperty(): int
    {
        if (! auth()->check()) {
            return 0;
        }

        return (int) (AssistantDailyUsage::forToday(auth()->user())->message_count ?? 0);
    }

    public function getShowCapWarningProperty(): bool
    {
        $cap = $this->dailyMessageCap;
        if ($cap <= 0) {
            return false;
        }

        $count = $this->todayMessageCount;

        return $count >= (int) ceil($cap * 0.8);
    }

    /**
     * @return Collection<int, AssistantThread>
     */
    public function getThreadsProperty(): Collection
    {
        if (! auth()->check()) {
            return collect();
        }

        return AssistantThread::query()
            ->with('store')
            ->where('user_id', auth()->id())
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get();
    }

    public function getActiveThreadProperty(): ?AssistantThread
    {
        if (! auth()->check() || ! $this->activeThreadId) {
            return null;
        }

        return AssistantThread::query()
            ->with('store')
            ->where('id', $this->activeThreadId)
            ->where('user_id', auth()->id())
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

        return Store::query()
            ->whereHas('vendor', fn ($v) => $v->where('user_id', auth()->id()))
            ->get();
    }

    /**
     * @return Collection<int, AssistantMessage>
     */
    public function loadMessages(): Collection
    {
        if (! auth()->check() || ! $this->activeThreadId) {
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
        $thread = AssistantThread::find($threadId);
        if (! $thread || (int) $thread->user_id !== (int) auth()->id()) {
            abort(403, 'Unauthorized thread access.');
        }

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
        $thread = $createThreadAction->execute($user);

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
        $thread = AssistantThread::find($threadId);
        if (! $thread || (int) $thread->user_id !== (int) auth()->id()) {
            abort(403, 'Unauthorized thread access.');
        }

        $renameThreadAction->execute($thread, $this->editingTitle);
        $this->editingThreadId = null;
        $this->editingTitle = '';
    }

    public function deleteThread(int $threadId, ?DeleteThreadAction $deleteThreadAction = null): void
    {
        $thread = AssistantThread::find($threadId);
        if (! $thread || (int) $thread->user_id !== (int) auth()->id()) {
            abort(403, 'Unauthorized thread access.');
        }

        ($deleteThreadAction ?? app(DeleteThreadAction::class))->execute($thread);

        if ($this->activeThreadId === $threadId) {
            $this->activeThreadId = AssistantThread::query()
                ->where('user_id', auth()->id())
                ->orderByDesc('last_message_at')
                ->value('id');
        }

        $this->editingThreadId = null;
    }

    public function switchStore(int $threadId, int $storeId, ?SetThreadStoreAction $setThreadStoreAction = null): void
    {
        $thread = AssistantThread::find($threadId);
        if (! $thread || (int) $thread->user_id !== (int) auth()->id()) {
            abort(403, 'Unauthorized thread access.');
        }

        $this->activeThreadId = $thread->id;
        $this->selectStore($storeId, $setThreadStoreAction ?? app(SetThreadStoreAction::class), app(CreateThreadAction::class));
    }

    public function selectStore(int $storeId, SetThreadStoreAction $setThreadStoreAction, CreateThreadAction $createThreadAction): void
    {
        if (! $this->isEnabled) {
            return;
        }

        $user = auth()->user();

        $store = Store::query()
            ->where('id', $storeId)
            ->whereHas('vendor', fn ($v) => $v->where('user_id', $user->id))
            ->first();

        if (! $store) {
            abort(403, 'Unauthorized store access.');
        }

        $thread = $this->activeThread;
        if ($thread && (int) $thread->user_id !== (int) $user->id) {
            abort(403, 'Unauthorized thread access.');
        }

        if (! $thread) {
            $thread = $createThreadAction->execute($user);
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

        $content = $message !== null && trim($message) !== '' ? trim($message) : trim($this->messageInput);
        if ($content === '') {
            $this->thinking = false;

            return;
        }

        $this->messageInput = '';

        try {
            $user = auth()->user();
            $thread = $this->activeThread;

            $result = $sendMessageAction->execute($user, $thread, $content);

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
            $this->errorMessage = $e->getMessage();
        } finally {
            $this->thinking = false;
        }
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
