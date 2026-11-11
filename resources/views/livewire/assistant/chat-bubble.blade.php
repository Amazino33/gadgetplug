<div>
@if($this->isEnabled)
@php
    $activeStoreName = $this->activeThread?->store?->name;
    $messages = $this->messages;
    $lastMsg = $messages->last();
    $isStoreSwitch = $lastMsg && $lastMsg->role === 'system' && str_starts_with((string) $lastMsg->content, '[Store context:');
    $showChips = $messages->isEmpty() || $isStoreSwitch;
@endphp
<div
    class="gp-chat"
    x-on:gp-open-assistant.window="open = true"
    x-data="{
        open: false,
        historyOpen: false,
        storeOpen: false,
        optimisticMessages: [],
        thinking: false,
        clientError: null,
        lastQuestion: null,
        atBottom: true,
        newBelow: false,
        shownTime: null,
        coarse: window.matchMedia('(pointer: coarse)').matches,

        init() {
            // The sheet follows the visible viewport, so the phone keyboard never covers the input.
            const vv = window.visualViewport;
            const fit = () => {
                this.$el.style.setProperty('--gp-chat-h', (vv ? vv.height : window.innerHeight) + 'px');
                this.$el.style.setProperty('--gp-chat-top', (vv ? vv.offsetTop : 0) + 'px');
            };
            fit();
            if (vv) { vv.addEventListener('resize', fit); vv.addEventListener('scroll', fit); }

            this.$watch('open', (isOpen) => {
                const phone = window.matchMedia('(max-width: 767px)').matches;
                document.documentElement.style.overflow = isOpen && phone ? 'hidden' : '';
                if (isOpen) {
                    this.$nextTick(() => { this.scrollToBottom(false); this.$refs.messageInput?.focus({ preventScroll: true }); });
                } else {
                    this.historyOpen = false;
                    this.storeOpen = false;
                    this.$nextTick(() => this.$refs.launcher?.focus());
                }
            });

            this.$wire.$watch('thinking', (isThinking) => {
                if (!isThinking) {
                    this.thinking = false;
                }
            });

            const list = this.$refs.messagesList;
            if (list) {
                list.addEventListener('scroll', () => {
                    this.atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
                    if (this.atBottom) this.newBelow = false;
                }, { passive: true });
                // New content: follow it only if the reader is already at the bottom.
                new MutationObserver(() => {
                    if (this.atBottom) this.scrollToBottom(true); else this.newBelow = true;
                }).observe(list, { childList: true, subtree: true });
            }
        },

        scrollToBottom(smooth) {
            this.$nextTick(() => {
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                this.$refs.chatEnd?.scrollIntoView({ behavior: smooth && !reduce ? 'smooth' : 'auto', block: 'end' });
                this.newBelow = false;
                this.atBottom = true;
            });
        },

        grow(el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 132) + 'px';
        },

        onEnter(e) {
            // Desktop: Enter sends, Shift+Enter is a new line. Phones: return is a new line; the button sends.
            if (e.shiftKey || this.coarse) return;
            e.preventDefault();
            this.submitMessage();
        },

        selectChip(text) {
            const input = this.$refs.messageInput;
            if (input) {
                input.value = text;
            }
            $wire.messageInput = text;
            this.submitMessage();
        },

        retry(text) {
            const question = text || this.lastQuestion || $wire.failedQuestion;
            if (!question) return;
            const input = this.$refs.messageInput;
            if (input) input.value = question;
            $wire.messageInput = question;
            this.submitMessage();
        },

        submitMessage(e) {
            if (e) {
                e.preventDefault();
            }
            if (this.thinking || $wire.thinking) {
                return;
            }
            const input = this.$refs.messageInput;
            const text = (input ? input.value : ($wire.messageInput || '')).trim();
            if (!text) {
                return;
            }

            this.optimisticMessages.push({ id: 'temp-' + Date.now(), content: text });
            this.lastQuestion = text;
            this.clientError = null;
            this.thinking = true;
            $wire.thinking = true;
            $wire.errorMessage = null;

            if (input) { input.value = ''; this.grow(input); }
            $wire.messageInput = '';

            this.scrollToBottom(true);

            $wire.sendMessage(text).then(() => {
                this.$nextTick(() => {
                    this.optimisticMessages = [];
                    this.thinking = false;
                    this.$refs.messageInput?.focus({ preventScroll: true });
                });
            }).catch(() => {
                // The request never came back (network, timeout): our words, never the browser's.
                this.optimisticMessages = [];
                this.thinking = false;
                $wire.thinking = false;
                this.clientError = 'Couldn\'t get an answer. Check your connection and try again.';
                this.scrollToBottom(true);
            });
        }
    }"
    @keydown.escape.window="if (open) { if (historyOpen) historyOpen = false; else if (storeOpen) storeOpen = false; else open = false; }"
>
    {{-- Launcher: hidden while the chat is open, so there is one way out (the header's close). --}}
    <button
        type="button"
        x-ref="launcher"
        x-show="!open"
        @click="open = true"
        class="fixed z-[100] flex items-center justify-center w-14 h-14 rounded-full bg-primary-500 hover:bg-primary-400 text-gray-950 shadow-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-primary-500/40"
        style="right: max(1.25rem, env(safe-area-inset-right)); bottom: max(1.25rem, env(safe-area-inset-bottom));"
        aria-label="Open Ask GadgetPlug"
        :aria-expanded="open.toString()"
    >
        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
        </svg>
    </button>

    {{-- The chat: a full-screen sheet on phones, a 420px panel docked right from 768px. --}}
    <section
        x-show="open"
        x-cloak
        style="display: none; height: var(--gp-chat-h, 100dvh); top: var(--gp-chat-top, 0px);"
        class="gp-chat-sheet fixed inset-x-0 z-[110] flex flex-col min-w-0 overflow-hidden md:left-auto md:w-[420px] md:border-l md:shadow-2xl"
        role="dialog"
        aria-label="Ask GadgetPlug"
        id="assistant-chat-bubble-container"
    >
        {{-- One-row header: history, title, store, questions left (only when low), close. --}}
        <header class="gp-chat-header relative flex items-center gap-1 h-14 px-1.5 shrink-0 min-w-0">
            <button type="button" @click="historyOpen = true" class="gp-chat-icon-btn" aria-label="Conversations" title="Conversations">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <h2 class="flex-1 min-w-0 truncate text-[15px] font-semibold">Ask GadgetPlug</h2>

            @if($this->ownedStores->count() > 0)
                <button
                    type="button"
                    @click="storeOpen = !storeOpen"
                    class="gp-chat-store-chip"
                    aria-haspopup="listbox"
                    :aria-expanded="storeOpen.toString()"
                    title="Store: {{ $activeStoreName ?? 'none picked' }}"
                >
                    <span class="truncate">{{ $activeStoreName ?? 'Pick a store' }}</span>
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
            @endif

            @if($this->showQuestionsLeft)
                <span class="gp-chat-left shrink-0" title="Questions left today; they reset at midnight">{{ $this->questionsLeft }} left today</span>
            @endif

            <button type="button" @click="open = false" class="gp-chat-icon-btn" aria-label="Close" title="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>

            {{-- Store picker (Phase 2 turns this into a bottom sheet with "All stores"). --}}
            <div x-show="storeOpen" x-cloak @click.outside="storeOpen = false" style="display: none;" class="gp-chat-popover absolute right-2 top-full mt-1 z-30 w-64 max-w-[calc(100%-1rem)] py-1" role="listbox" aria-label="Ask about store">
                @foreach($this->ownedStores as $st)
                    <button
                        type="button"
                        role="option"
                        aria-selected="{{ $this->activeThread?->store_id === $st->id ? 'true' : 'false' }}"
                        wire:click="selectStore({{ $st->id }})"
                        @click="storeOpen = false"
                        class="gp-chat-option {{ $this->activeThread?->store_id === $st->id ? 'is-active' : '' }}"
                    >
                        <span class="truncate">{{ $st->name }}</span>
                    </button>
                @endforeach
            </div>
        </header>

        {{-- Conversation history: a drawer inside the chat. --}}
        <div x-show="historyOpen" x-cloak style="display: none;" class="absolute inset-0 z-40 flex" role="dialog" aria-label="Conversations">
            <div class="absolute inset-0 bg-gray-950/40" @click="historyOpen = false"></div>
            <div class="gp-chat-drawer relative flex flex-col w-[85%] max-w-xs h-full min-w-0">
                <div class="flex items-center justify-between h-14 px-3 shrink-0 gp-chat-hairline-b">
                    <span class="text-[13px] font-semibold">Conversations</span>
                    <button type="button" @click="historyOpen = false" class="gp-chat-icon-btn" aria-label="Close conversations">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="px-3 py-2 shrink-0">
                    <button type="button" wire:click="createNewThread" @click="historyOpen = false" class="gp-chat-new w-full" title="New conversation">
                        + New conversation
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-2 pb-3 space-y-1 min-w-0">
                    @forelse($this->threads as $t)
                        <div
                            wire:key="thread-item-{{ $t->id }}"
                            class="gp-chat-thread {{ $this->activeThreadId === $t->id ? 'is-active' : '' }}"
                        >
                            @if($editingThreadId === $t->id)
                                <div class="flex items-center gap-1 min-w-0">
                                    <input
                                        type="text"
                                        wire:model="editingTitle"
                                        wire:keydown.enter="saveThreadTitle({{ $t->id }})"
                                        class="gp-chat-field flex-1 min-w-0 text-[13px] px-2 py-2"
                                        aria-label="Conversation name"
                                    />
                                    <button type="button" wire:click.stop="saveThreadTitle({{ $t->id }})" class="gp-chat-icon-btn" aria-label="Save name">✓</button>
                                    <button type="button" wire:click.stop="cancelEditingTitle" class="gp-chat-icon-btn" aria-label="Cancel">✕</button>
                                </div>
                            @else
                                <div class="flex items-center gap-1 min-w-0">
                                    <button type="button" wire:click="selectThread({{ $t->id }})" @click="historyOpen = false" class="flex-1 min-w-0 text-left py-1.5">
                                        <span class="flex items-center gap-1 min-w-0 text-[13px] font-medium" title="{{ $t->title }}">
                                            <span class="truncate">{{ $t->title ?: 'New conversation' }}</span>
                                            @if($t->hasDeletedStore())
                                                <svg class="w-3.5 h-3.5 text-warning-600 inline-block shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" title="Store removed" aria-label="Store removed">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                                </svg>
                                            @endif
                                        </span>
                                        <span class="flex items-center justify-between gap-2 mt-0.5 text-[11px] gp-chat-muted">
                                            <span class="truncate">{{ $t->hasDeletedStore() ? ($t->store_removed_name . ' (removed)') : ($t->store?->name ?? 'No store') }}</span>
                                            <span class="shrink-0">{{ $t->last_message_at?->diffForHumans(short: true) }}</span>
                                        </span>
                                    </button>
                                    {{-- @js: any title (quotes, line breaks, </script>) is a safe JavaScript string here. --}}
                                    <button type="button" wire:click.stop="startEditingTitle({{ $t->id }}, @js($t->title ?: ''))" class="gp-chat-icon-btn" aria-label="Rename conversation" title="Rename">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536M9 13l6.232-6.232a2.5 2.5 0 113.536 3.536L12.536 16.536 9 17l.464-3.536z"></path></svg>
                                    </button>
                                    <button type="button" wire:click.stop="deleteThread({{ $t->id }})" wire:confirm="Delete this conversation?" class="gp-chat-icon-btn text-danger-600" aria-label="Delete conversation" title="Delete">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-center py-8 px-4 text-[13px] gp-chat-muted">No conversations yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- A conversation about a store that was since removed, or one still waiting for a store. --}}
        @if($this->activeThread && $this->activeThread->hasDeletedStore())
            <div class="gp-chat-notice m-3 shrink-0">
                <p class="font-semibold">
                    This conversation was about {{ $this->activeThread->store_removed_name }}, removed on {{ $this->localTime($this->activeThread->store_removed_at)?->format('M j, Y') ?? 'recently' }}.
                </p>
                <p class="mt-0.5">Pick a new store to continue.</p>
                @if($this->ownedStores->count() > 0)
                    <div class="mt-2.5 flex flex-wrap gap-1.5">
                        @foreach($this->ownedStores as $st)
                            <button type="button" wire:click="selectStore({{ $st->id }})" class="gp-chat-chip">{{ $st->name }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        @elseif($this->activeThread && ! $this->activeThread->store_id && $this->ownedStores->count() >= 2)
            <div class="gp-chat-notice m-3 shrink-0 text-center">
                <p class="font-semibold">Pick a store for this conversation:</p>
                <div class="mt-2.5 flex flex-wrap items-center justify-center gap-2">
                    @foreach($this->ownedStores as $st)
                        <button type="button" wire:click="selectStore({{ $st->id }})" class="gp-chat-chip">{{ $st->name }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- The conversation. --}}
        <div class="relative flex-1 min-h-0 min-w-0">
            <div
                x-ref="messagesList"
                id="assistant-messages-list"
                class="gp-chat-canvas absolute inset-0 overflow-y-auto overflow-x-hidden px-4 py-3"
                role="log"
                aria-live="polite"
                aria-relevant="additions"
                aria-label="Conversation"
            >
                @php
                    $previousAt = null;
                    $seenConversation = false;
                    $lastQuestion = null;
                @endphp
                @forelse($messages as $msg)
                    @php
                        // Dividers count only what is shown: system notes (store context) don't reset the 10-minute clock.
                        $gap = $msg->role !== 'system' && $msg->created_at
                            && ($previousAt === null || $previousAt->diffInMinutes($msg->created_at) >= 10);
                        if ($msg->role !== 'system' && $msg->created_at) {
                            $previousAt = $msg->created_at;
                        }
                    @endphp

                    @if($gap)
                        <div class="gp-chat-divider" wire:key="time-{{ $msg->id }}"><span>{{ $this->dividerLabel($msg->created_at) }}</span></div>
                    @endif

                    @if($msg->role === 'system')
                        @php $switchedTo = \App\Livewire\Assistant\ChatBubble::storeContextName($msg->content); @endphp
                        @if($switchedTo === null)
                            <div wire:key="msg-{{ $msg->id }}" class="gp-chat-divider"><span>{{ $msg->content }}</span></div>
                        @elseif($seenConversation)
                            {{-- The store, only where it changes partway through. --}}
                            <div wire:key="msg-{{ $msg->id }}" class="gp-chat-divider"><span>Now asking about {{ $switchedTo }}</span></div>
                        @else
                            <div wire:key="msg-{{ $msg->id }}" hidden></div>
                        @endif
                    @elseif($msg->role === 'user')
                        @php $seenConversation = true; $lastQuestion = (string) $msg->content; @endphp
                        <div wire:key="msg-{{ $msg->id }}" class="flex flex-col items-end my-2 min-w-0" @click="shownTime = shownTime === {{ $msg->id }} ? null : {{ $msg->id }}">
                            <div class="gp-chat-mine max-w-[85%] min-w-0 rounded-2xl rounded-br-md px-3.5 py-2">
                                <p class="whitespace-pre-wrap break-words">{{ $msg->content }}</p>
                            </div>
                            <time x-show="shownTime === {{ $msg->id }}" x-cloak style="display: none;" class="gp-chat-time">{{ $this->localTime($msg->created_at)?->format('H:i') }}</time>
                        </div>
                    @else
                        @php
                            $seenConversation = true;
                            $status = $msg->status?->value;
                            $failed = in_array($status, ['provider_error', 'internal_error', 'tool_error', 'phrasing_failed'], true);
                        @endphp
                        <div wire:key="msg-{{ $msg->id }}" class="my-2 min-w-0" @click="shownTime = shownTime === {{ $msg->id }} ? null : {{ $msg->id }}">
                            @if($failed)
                                {{-- The backend's own friendly words (out of credit, busy, timed out), and a way to try again. --}}
                                <div class="gp-chat-error" role="alert">
                                    <p>{{ $msg->content }}</p>
                                    @if($lastQuestion)
                                        <button type="button" @click.stop="retry(@js($lastQuestion))" class="gp-chat-retry">Retry</button>
                                    @endif
                                </div>
                            @elseif($status === 'cap_reached' || $msg->is_prompt)
                                <div class="gp-chat-notice"><p>{{ $msg->content }}</p></div>
                            @else
                                <div class="gp-chat-answer">{{ $this->answerHtml($msg) }}</div>
                                @if($status === 'no_access')
                                    <p class="mt-1.5 text-[12px] gp-chat-muted">Your role doesn't include this. Ask the shop owner if you need it.</p>
                                @endif
                            @endif
                            <time x-show="shownTime === {{ $msg->id }}" x-cloak style="display: none;" class="gp-chat-time">{{ $this->localTime($msg->created_at)?->format('H:i') }}</time>
                        </div>
                    @endif
                @empty
                    <div x-show="optimisticMessages.length === 0" class="h-full flex flex-col justify-center py-6">
                        <p class="text-[15px] font-semibold">Ask about sales, stock, prices or debts{{ $activeStoreName ? ' in '.$activeStoreName : '' }}.</p>
                        <p class="mt-1 text-[13px] gp-chat-muted">Type a question, or tap one below.</p>
                    </div>
                @endforelse

                {{-- Your question, shown at once while the answer comes. --}}
                <template x-for="msg in optimisticMessages" :key="msg.id">
                    <div class="flex flex-col items-end my-2 min-w-0">
                        <div class="gp-chat-mine max-w-[85%] min-w-0 rounded-2xl rounded-br-md px-3.5 py-2">
                            <p class="whitespace-pre-wrap break-words" x-text="msg.content"></p>
                        </div>
                    </div>
                </template>

                {{-- Thinking: where the answer will appear. --}}
                <div x-show="(thinking || $wire.thinking) && !$wire.errorMessage && !clientError" x-cloak style="display: none;" class="gp-chat-thinking my-3" role="status">
                    <span class="gp-chat-dot"></span><span class="gp-chat-dot"></span><span class="gp-chat-dot"></span>
                    <span class="ml-2 text-[13px] gp-chat-muted">Checking…</span>
                </div>

                {{-- A question that could not be sent: plain words and Retry, never the error's own text. --}}
                @if($this->errorMessage)
                    <div class="gp-chat-error my-2" role="alert">
                        <p>{{ $this->errorMessage }}</p>
                        @if($this->failedQuestion)
                            <button type="button" @click="retry(@js($this->failedQuestion))" class="gp-chat-retry">Retry</button>
                        @endif
                    </div>
                @endif
                <div x-show="clientError" x-cloak style="display: none;" class="gp-chat-error my-2" role="alert">
                    <p x-text="clientError"></p>
                    <button type="button" @click="retry()" class="gp-chat-retry">Retry</button>
                </div>

                <div x-ref="chatEnd" class="h-px"></div>
            </div>

            {{-- Scrolled up to read: a pill instead of yanking the reader down. --}}
            <button type="button" x-show="newBelow" x-cloak style="display: none;" @click="scrollToBottom(true)" class="gp-chat-pill absolute bottom-3 left-1/2 -translate-x-1/2">
                New answer ↓
            </button>
        </div>

        {{-- Input: a growing textarea, above the keyboard and the phone's home bar. --}}
        <footer class="gp-chat-footer shrink-0 px-3 pt-2 min-w-0" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom));">
            @if($showChips)
                <div x-show="optimisticMessages.length === 0" class="flex items-center gap-2 overflow-x-auto pb-2 mb-0.5 min-w-0">
                    <button type="button" @click="selectChip('How much did I sell this week?')" class="gp-chat-chip whitespace-nowrap">How much did I sell this week?</button>
                    <button type="button" @click="selectChip('What items are running low on stock?')" class="gp-chat-chip whitespace-nowrap">What items are running low on stock?</button>
                    <button type="button" @click="selectChip('Do I owe any suppliers?')" class="gp-chat-chip whitespace-nowrap">Do I owe any suppliers?</button>
                    <button type="button" @click="selectChip(&quot;What's my cash variance?&quot;)" class="gp-chat-chip whitespace-nowrap">What's my cash variance?</button>
                </div>
            @endif
            <form wire:submit.prevent="sendMessage" @submit.prevent="submitMessage($event)" class="flex items-end gap-2 min-w-0">
                <textarea
                    wire:model="messageInput"
                    x-ref="messageInput"
                    rows="1"
                    maxlength="2000"
                    placeholder="Ask a question…"
                    aria-label="Your question"
                    :enterkeyhint="coarse ? 'enter' : 'send'"
                    @input="grow($el)"
                    @keydown.enter="onEnter($event)"
                    class="gp-chat-field flex-1 min-w-0 resize-none text-[15px] leading-[22px] px-3.5 py-[11px] max-h-[132px]"
                ></textarea>
                <button
                    type="submit"
                    :disabled="thinking || $wire.thinking"
                    class="gp-chat-send shrink-0"
                    aria-label="Send"
                    title="Send"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M13 6l6 6-6 6"></path></svg>
                </button>
            </form>
        </footer>
    </section>
</div>
@endif
</div>
