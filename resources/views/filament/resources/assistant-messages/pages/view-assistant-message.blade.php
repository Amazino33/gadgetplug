<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Message Metadata Card -->
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 dark:border-white/10 pb-4 mb-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Message #{{ $this->record->id }} Overview</h2>
                    <p class="text-xs text-gray-500">Thread #{{ $this->record->thread_id }} &bull; Sent {{ $this->record->created_at->format('M j, Y H:i:s') }}</p>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $this->record->role === 'user' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' }}">
                        {{ ucfirst($this->record->role) }}
                    </span>
                    @if($this->record->isFlagged())
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                            🚩 Flagged
                        </span>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <span class="block text-xs font-medium text-gray-400">Vendor / User</span>
                    <span class="font-medium text-gray-900 dark:text-white">{{ $this->record->user?->name ?? 'Unknown' }} ({{ $this->record->user?->email }})</span>
                </div>
                <div>
                    <span class="block text-xs font-medium text-gray-400">Store / Branch</span>
                    <span class="font-medium text-gray-900 dark:text-white">{{ $this->record->store?->name ?? ($this->record->store_name_snapshot ? $this->record->store_name_snapshot.'*' : 'None') }}</span>
                </div>
                <div>
                    <span class="block text-xs font-medium text-gray-400">Flag Status</span>
                    @if($this->record->isFlagged())
                        <span class="text-rose-600 font-medium">Flagged on {{ $this->record->flagged_at?->format('M j, Y H:i') }} by {{ $this->record->flaggedBy?->name ?? 'Admin' }}</span>
                        @if($this->record->flag_note)
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1 italic">"{{ $this->record->flag_note }}"</p>
                        @endif
                    @else
                        <span class="text-gray-500">Not Flagged</span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Thread Context Stream -->
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-white/10 flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-sm text-gray-900 dark:text-white">Thread Context (surrounding 20 messages before and after)</h3>
                    <p class="text-xs text-gray-500">Showing conversation flow in chronological order</p>
                </div>
                <span class="text-xs text-gray-400">{{ $this->surroundingMessages->count() }} messages loaded</span>
            </div>

            <div class="p-6 space-y-4 max-h-[600px] overflow-y-auto bg-gray-50/50 dark:bg-gray-950/40" id="thread-context-stream">
                @foreach($this->surroundingMessages as $msg)
                    @php
                        $isCurrent = $msg->id === $this->record->id;
                    @endphp
                    <div class="flex flex-col {{ $msg->role === 'user' ? 'items-end' : 'items-start' }}">
                        <div class="max-w-[85%] rounded-2xl px-4 py-3 text-sm shadow-sm transition-all {{ $isCurrent ? 'ring-2 ring-amber-500 ring-offset-2 dark:ring-offset-gray-900' : '' }} {{ $msg->role === 'user' ? 'bg-amber-500 text-white rounded-br-xs' : 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 border border-gray-200 dark:border-gray-700 rounded-bl-xs' }}">
                            <div class="flex items-center justify-between gap-3 mb-1 text-[11px] {{ $msg->role === 'user' ? 'text-amber-100' : 'text-gray-400' }}">
                                <span class="font-semibold">{{ $msg->role === 'user' ? ($msg->user?->name ?? 'User') : 'Assistant' }}</span>
                                <div class="flex items-center space-x-1.5">
                                    @if($msg->isFlagged())
                                        <span class="px-1.5 py-0.5 rounded bg-rose-500 text-white font-bold text-[10px]">FLAGGED</span>
                                    @endif
                                    <span>{{ $msg->created_at->format('M j, H:i:s') }}</span>
                                </div>
                            </div>

                            <p class="whitespace-pre-wrap leading-relaxed select-text">{{ $msg->content }}</p>

                            @if($isCurrent)
                                <div class="mt-2 pt-2 border-t {{ $msg->role === 'user' ? 'border-amber-400/50 text-amber-100' : 'border-gray-200 dark:border-gray-700 text-amber-600 dark:text-amber-400' }} text-[11px] font-semibold flex items-center space-x-1">
                                    <span>👉 Target Message #{{ $msg->id }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-panels::page>

