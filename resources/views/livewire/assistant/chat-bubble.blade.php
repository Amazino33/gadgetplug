<div>
@if($this->isEnabled)
<div
    x-on:gp-open-assistant.window="open = true"
    x-data="{
        open: false,
        sidebarOpen: @entangle('sidebarOpen'),
        optimisticMessages: [],
        thinking: false,

        init() {
            this.scrollToBottom();
            this.$watch('open', (isOpen) => {
                if (isOpen) {
                    this.scrollToBottom();
                }
            });
            this.$wire.$watch('thinking', (isThinking) => {
                if (!isThinking) {
                    this.thinking = false;
                    this.scrollToBottom();
                }
            });
            if (this.$refs.messagesList) {
                const observer = new MutationObserver(() => this.scrollToBottom());
                observer.observe(this.$refs.messagesList, { childList: true, subtree: true });
            }
        },

        scrollToBottom() {
            this.$nextTick(() => {
                if (this.$refs.chatEnd) {
                    this.$refs.chatEnd.scrollIntoView({ behavior: 'smooth' });
                }
            });
        },

        selectChip(text) {
            const input = this.$refs.messageInput;
            if (input) {
                input.value = text;
            }
            $wire.messageInput = text;
            this.submitMessage();
        },

        submitMessage(e) {
            if (e) {
                e.preventDefault();
            }
            const input = this.$refs.messageInput;
            const text = (input ? input.value : ($wire.messageInput || '')).trim();
            if (!text) {
                return;
            }

            this.optimisticMessages.push({
                id: 'temp-' + Date.now(),
                content: text,
                role: 'user'
            });

            this.thinking = true;
            $wire.thinking = true;
            $wire.errorMessage = null;

            if (input) input.value = '';
            $wire.messageInput = '';

            this.scrollToBottom();

            $wire.sendMessage(text).then(() => {
                this.$nextTick(() => {
                    this.optimisticMessages = [];
                    this.thinking = false;
                });
            }).catch((err) => {
                this.thinking = false;
                $wire.thinking = false;
                if (!$wire.errorMessage) {
                    $wire.errorMessage = err?.message || 'The assistant took too long to respond.';
                }
                this.scrollToBottom();
            });
        }
    }"
    class="fixed bottom-6 right-6 z-[100] font-sans"
    id="assistant-chat-bubble-container"
    style="position: fixed; bottom: 24px; right: 24px; z-index: 100;"
>
    <!-- Chat Launcher Button -->
    <button
        type="button"
        @click="open = !open"
        class="flex items-center justify-center w-14 h-14 rounded-full bg-amber-500 hover:bg-amber-600 text-white shadow-xl focus:outline-none transition-transform duration-200 hover:scale-105 active:scale-95"
        title="AI Assistant"
        aria-label="Toggle Assistant Chat"
    >
        <template x-if="!open">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
            </svg>
        </template>
        <template x-if="open">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </template>
    </button>

    <!-- Chat Panel -->
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 scale-95"
        style="display: none;"
        class="absolute bottom-16 right-0 w-[840px] max-w-[calc(100vw-2rem)] h-[620px] max-h-[calc(100vh-6rem)] bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-2xl flex overflow-hidden"
    >
        <!-- Sidebar (Threads List) -->
        <div
            :class="sidebarOpen ? 'flex' : 'hidden md:flex'"
            class="w-64 border-r border-gray-200 dark:border-gray-800 flex-col bg-gray-50 dark:bg-gray-950/70 shrink-0 h-full"
        >
            <!-- Sidebar Header -->
            <div class="p-3 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between bg-white dark:bg-gray-900">
                <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Conversations</span>
                <button
                    type="button"
                    wire:click="createNewThread"
                    class="inline-flex items-center space-x-1 px-2.5 py-1 text-xs font-medium rounded-lg bg-amber-500 hover:bg-amber-600 text-white transition shadow-sm"
                    title="New conversation"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    <span>New</span>
                </button>
            </div>

            <!-- Thread Items List -->
            <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800/60 p-2 space-y-1">
                @forelse($this->threads as $t)
                    <div
                        wire:key="thread-item-{{ $t->id }}"
                        class="group relative rounded-xl p-2.5 text-xs transition cursor-pointer flex flex-col space-y-1 {{ $this->activeThreadId === $t->id ? 'bg-amber-500/10 border border-amber-500/30 text-amber-900 dark:text-amber-200' : 'hover:bg-gray-100 dark:hover:bg-gray-800/60 text-gray-700 dark:text-gray-300' }}"
                        wire:click="selectThread({{ $t->id }})"
                    >
                        @if($editingThreadId === $t->id)
                            <div class="flex items-center space-x-1" @click.stop>
                                <input
                                    type="text"
                                    wire:model="editingTitle"
                                    wire:keydown.enter="saveThreadTitle({{ $t->id }})"
                                    class="w-full text-xs px-2 py-1 bg-white dark:bg-gray-800 border border-amber-400 rounded focus:outline-none"
                                />
                                <button
                                    type="button"
                                    wire:click.stop="saveThreadTitle({{ $t->id }})"
                                    class="p-1 text-emerald-600 hover:text-emerald-700"
                                    title="Save"
                                >
                                    ✓
                                </button>
                                <button
                                    type="button"
                                    wire:click.stop="cancelEditingTitle"
                                    class="p-1 text-gray-400 hover:text-gray-600"
                                    title="Cancel"
                                >
                                    ✕
                                </button>
                            </div>
                        @else
                            <div class="flex items-center justify-between">
                                <span class="font-medium truncate flex-1 pr-1 flex items-center space-x-1" title="{{ $t->title }}">
                                    <span class="truncate">{{ $t->title ?: 'New conversation' }}</span>
                                    @if($t->hasDeletedStore())
                                        <svg class="w-3.5 h-3.5 text-amber-500 inline-block shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" title="Store removed" aria-label="Store removed">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                        </svg>
                                    @endif
                                </span>
                                <!-- Row action buttons -->
                                <div class="opacity-0 group-hover:opacity-100 transition-opacity flex items-center space-x-1" @click.stop>
                                    <button
                                        type="button"
                                        wire:click.stop="startEditingTitle({{ $t->id }}, '{{ addslashes($t->title ?: '') }}')"
                                        class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                        title="Rename"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click.stop="deleteThread({{ $t->id }})"
                                        wire:confirm="Are you sure you want to delete this conversation?"
                                        class="p-1 text-red-400 hover:text-red-600"
                                        title="Delete"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        @endif

                        <div class="flex items-center justify-between text-[10px] text-gray-400">
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded {{ $t->hasDeletedStore() ? 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300' : 'bg-gray-200 dark:bg-gray-800 text-gray-600 dark:text-gray-300' }}">
                                {{ $t->hasDeletedStore() ? ($t->store_removed_name . ' (removed)') : ($t->store?->name ?? 'No store') }}
                            </span>
                            <span>{{ $t->last_message_at?->diffForHumans(short: true) }}</span>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-8 px-4 text-gray-400 text-xs">
                        <p>No conversations yet.</p>
                        <button
                            type="button"
                            wire:click="createNewThread"
                            class="mt-2 text-amber-500 hover:underline font-medium"
                        >
                            Start your first conversation
                        </button>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Main Chat Area -->
        <div class="flex-1 flex flex-col h-full bg-white dark:bg-gray-900">
            <!-- Header -->
            <div class="px-4 py-3 bg-amber-500 text-white flex items-center justify-between shadow-sm shrink-0">
                <div class="flex items-center space-x-2">
                    <button
                        type="button"
                        wire:click="toggleSidebar"
                        class="md:hidden p-1 rounded hover:bg-amber-600 text-amber-100 hover:text-white transition"
                        title="Toggle Conversations"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <span class="p-1 rounded-md bg-amber-600 shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                    </span>
                    <div class="truncate max-w-[280px] sm:max-w-[360px]">
                        <h3 class="font-semibold text-sm leading-tight truncate">
                            {{ $this->activeThread?->title ?: 'AI Assistant' }}
                        </h3>
                        <p class="text-[11px] text-amber-100 truncate">
                            Context: {{ $this->activeThread?->store?->name ?? 'No store selected' }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center space-x-1.5">
                    @if($this->dailyMessageCap > 0)
                        <span class="text-[11px] font-medium text-amber-100 bg-amber-600/60 px-2 py-0.5 rounded-full" title="Daily message usage">
                            {{ $this->todayMessageCount }} / {{ $this->dailyMessageCap }} today
                        </span>
                    @endif
                    <button
                        type="button"
                        wire:click="createNewThread"
                        class="hidden sm:inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg text-amber-100 hover:text-white hover:bg-amber-600 transition text-xs font-medium"
                        title="New Conversation"
                    >
                        <span>+ New</span>
                    </button>
                    <button
                        type="button"
                        @click="open = false"
                        class="p-1.5 rounded-lg text-amber-100 hover:text-white hover:bg-amber-600 transition"
                        title="Close"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Store Switcher Chips Sub-Header -->
            @if($this->ownedStores->count() > 0)
                <div class="px-4 py-2 bg-gray-100 dark:bg-gray-800/80 border-b border-gray-200 dark:border-gray-800 flex items-center space-x-2 overflow-x-auto text-xs shrink-0">
                    <span class="text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap text-[11px]">Store Context:</span>
                    <div class="flex items-center space-x-1.5">
                        @foreach($this->ownedStores as $st)
                            <button
                                type="button"
                                wire:click="selectStore({{ $st->id }})"
                                class="px-2.5 py-1 rounded-full text-xs font-medium transition whitespace-nowrap {{ ($this->activeThread?->store_id === $st->id) ? 'bg-amber-500 text-white shadow-xs' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 border border-gray-200 dark:border-gray-600' }}"
                            >
                                {{ $st->name }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Prominent "Pick a Store" Prompt if active thread has no store assigned and user owns 2+ stores -->
            @if($this->activeThread && $this->activeThread->hasDeletedStore())
                <!-- Deleted Store Warning Banner -->
                <div class="m-3 p-3.5 bg-amber-50 dark:bg-amber-950/50 border border-amber-300 dark:border-amber-700/60 rounded-xl shadow-xs shrink-0">
                    <div class="flex items-start space-x-2.5">
                        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <div class="flex-1 text-xs">
                            <p class="font-semibold text-amber-900 dark:text-amber-200">
                                This conversation was about {{ $this->activeThread->store_removed_name }}, removed on {{ $this->activeThread->store_removed_at?->format('M j, Y') ?? 'recently' }}.
                            </p>
                            <p class="text-amber-700 dark:text-amber-300 mt-0.5">
                                Pick a new store to continue.
                            </p>
                            @if($this->ownedStores->count() > 0)
                                <div class="mt-2.5 flex flex-wrap gap-1.5">
                                    @foreach($this->ownedStores as $st)
                                        <button
                                            type="button"
                                            wire:click="selectStore({{ $st->id }})"
                                            class="px-2.5 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded-lg font-medium shadow-xs transition"
                                        >
                                            {{ $st->name }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @elseif($this->activeThread && ! $this->activeThread->store_id && $this->ownedStores->count() >= 2)
                <div class="m-3 p-3.5 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/50 rounded-xl text-center shrink-0">
                    <p class="text-xs font-semibold text-amber-900 dark:text-amber-200">
                        Pick a store context for this conversation:
                    </p>
                    <div class="mt-2.5 flex flex-wrap items-center justify-center gap-2">
                        @foreach($this->ownedStores as $st)
                            <button
                                type="button"
                                wire:click="selectStore({{ $st->id }})"
                                class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-xs font-medium rounded-lg shadow-sm transition"
                            >
                                {{ $st->name }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Daily Cap Warning Banner (≥ 80% used, yellow, dismissible) -->
            @if($this->showCapWarning)
                <div
                    x-data="{ dismissed: localStorage.getItem('dismissed_cap_warning_date') === new Date().toISOString().slice(0, 10) }"
                    x-show="!dismissed"
                    class="mx-3 mt-2 p-2.5 bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-300 dark:border-yellow-700/60 rounded-xl flex items-center justify-between text-xs text-yellow-800 dark:text-yellow-200 shrink-0"
                >
                    <div class="flex items-center space-x-2">
                        <svg class="w-4 h-4 text-yellow-600 dark:text-yellow-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <span>You've used {{ $this->todayMessageCount }} of {{ $this->dailyMessageCap }} {{ $this->vendorId ? 'questions' : 'messages' }} today.</span>
                    </div>
                    <button
                        type="button"
                        @click="dismissed = true; localStorage.setItem('dismissed_cap_warning_date', new Date().toISOString().slice(0, 10))"
                        class="text-yellow-600 dark:text-yellow-400 hover:text-yellow-800 dark:hover:text-yellow-100 p-1 rounded transition"
                        title="Dismiss"
                    >
                        ✕
                    </button>
                </div>
            @endif

            <!-- Messages Area -->
            <div x-ref="messagesList" class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50 dark:bg-gray-950/50" id="assistant-messages-list">
                @forelse($this->messages as $msg)
                    @if($msg->role === 'system')
                        <!-- System Message -->
                        <div wire:key="msg-{{ $msg->id }}" class="flex justify-center my-2">
                            <span class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-medium bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/40 text-amber-800 dark:text-amber-300">
                                <span>{{ $msg->content }}</span>
                            </span>
                        </div>
                    @else
                        <!-- User or Assistant Message -->
                        <div wire:key="msg-{{ $msg->id }}" class="flex flex-col {{ $msg->role === 'user' ? 'items-end' : 'items-start' }}">
                            @if($msg->store_name_snapshot)
                                <span class="text-[10px] font-medium text-gray-400 dark:text-gray-500 mb-0.5 px-1">
                                    {{ $msg->store_name_snapshot }}
                                </span>
                            @endif
                            <div class="max-w-[85%] rounded-2xl px-3.5 py-2 text-sm shadow-sm {{ $msg->role === 'user' ? 'bg-amber-500 text-white rounded-br-xs' : 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 border border-gray-200 dark:border-gray-700 rounded-bl-xs' }}">
                                <p class="whitespace-pre-wrap leading-relaxed">{{ $msg->content }}</p>
                            </div>
                            <span class="text-[10px] text-gray-400 mt-1 px-1">
                                {{ $msg->created_at?->format('H:i') }}
                            </span>
                        </div>
                    @endif
                @empty
                    <div x-show="optimisticMessages.length === 0" class="h-full flex flex-col items-center justify-center text-center p-6 text-gray-400">
                        <svg class="w-12 h-12 mb-2 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                        </svg>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">How can I help you today?</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Ask questions about your store, products, or orders.</p>
                    </div>
                @endforelse

                <!-- Optimistic User Messages -->
                <template x-for="msg in optimisticMessages" :key="msg.id">
                    <div class="flex flex-col items-end">
                        <div class="max-w-[85%] rounded-2xl px-3.5 py-2 text-sm shadow-sm bg-amber-500 text-white rounded-br-xs">
                            <p class="whitespace-pre-wrap leading-relaxed" x-text="msg.content"></p>
                        </div>
                    </div>
                </template>

                <!-- Thinking State -->
                <div
                    x-show="(thinking || $wire.thinking) && !$wire.errorMessage"
                    class="flex items-center space-x-2 text-xs text-gray-500 py-1"
                    style="display: none;"
                >
                    <span class="inline-block w-2 h-2 rounded-full bg-amber-500 animate-ping"></span>
                    <span>Thinking...</span>
                </div>

                <!-- Error Message State (replaces Thinking if server errors) -->
                @if($this->errorMessage)
                    <div class="flex items-center space-x-2 text-xs text-red-600 dark:text-red-400 py-2 px-3 rounded-xl bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800">
                        <svg class="w-4 h-4 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="font-medium">{{ $this->errorMessage }}</span>
                    </div>
                @endif

                <!-- Auto-Scroll Anchor -->
                <div x-ref="chatEnd"></div>
            </div>

            @php
                $lastMsg = $this->messages->last();
                $isStoreSwitch = $lastMsg && $lastMsg->role === 'system' && str_starts_with((string) $lastMsg->content, '[Store context:');
                $showChips = $this->messages->isEmpty() || $isStoreSwitch;
            @endphp

            <!-- Footer / Input -->
            <div class="p-3 border-t border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shrink-0">
                @if($showChips)
                    <div x-show="optimisticMessages.length === 0" class="flex items-center space-x-2 overflow-x-auto pb-2 mb-1.5 text-xs shrink-0">
                        <button
                            type="button"
                            @click="selectChip('How much did I sell this week?')"
                            class="rounded-full bg-gray-800 text-gray-300 px-3 py-1 text-xs hover:bg-gray-700 whitespace-nowrap transition cursor-pointer"
                        >
                            How much did I sell this week?
                        </button>
                        <button
                            type="button"
                            @click="selectChip('What items are running low on stock?')"
                            class="rounded-full bg-gray-800 text-gray-300 px-3 py-1 text-xs hover:bg-gray-700 whitespace-nowrap transition cursor-pointer"
                        >
                            What items are running low on stock?
                        </button>
                        <button
                            type="button"
                            @click="selectChip('Do I owe any suppliers?')"
                            class="rounded-full bg-gray-800 text-gray-300 px-3 py-1 text-xs hover:bg-gray-700 whitespace-nowrap transition cursor-pointer"
                        >
                            Do I owe any suppliers?
                        </button>
                        <button
                            type="button"
                            @click="selectChip(&quot;What's my cash variance?&quot;)"
                            class="rounded-full bg-gray-800 text-gray-300 px-3 py-1 text-xs hover:bg-gray-700 whitespace-nowrap transition cursor-pointer"
                        >
                            What's my cash variance?
                        </button>
                    </div>
                @endif
                <form wire:submit.prevent="sendMessage" @submit.prevent="submitMessage($event)" class="flex items-center space-x-2">
                    <input
                        type="text"
                        wire:model="messageInput"
                        x-ref="messageInput"
                        placeholder="Type your message..."
                        maxlength="2000"
                        class="flex-1 bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-100 placeholder-gray-400 text-sm rounded-xl px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-amber-500 border border-transparent focus:border-amber-500 transition"
                        :disabled="thinking || $wire.thinking"
                    />
                    <button
                        type="submit"
                        :disabled="thinking || $wire.thinking"
                        class="inline-flex items-center justify-center p-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white transition focus:outline-none"
                        title="Send"
                    >
                        <svg class="w-5 h-5 transform rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif
</div>
