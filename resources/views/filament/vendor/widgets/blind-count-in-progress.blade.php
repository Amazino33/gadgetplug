@php $sessions = $this->getSessions(); @endphp

<div>
@if ($sessions->isNotEmpty())
<div class="mb-4 space-y-2" x-data="{ expanded: null }">
    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-1">Blind Count Sessions — In Progress</p>

    @foreach ($sessions as $session)
    @php
        $total    = count($session->product_order ?? []);
        $counted  = $session->counted_so_far;
        $isA      = $session->status === 'a_counting';
        $statusLabel = $isA
            ? ($session->storekeeperA->name ?? 'Storekeeper A') . ' is counting…'
            : ($session->storekeeperA->name ?? 'Storekeeper A') . ' has counted — awaiting verification';
    @endphp

    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
        {{-- Clickable summary row --}}
        <button
            type="button"
            @click="expanded = expanded === {{ $session->id }} ? null : {{ $session->id }}"
            class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-gray-50 transition-colors"
        >
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full {{ $isA ? 'bg-amber-100' : 'bg-green-100' }}">
                    @if($isA)
                        <x-heroicon-o-clock class="w-4 h-4 text-amber-600"/>
                    @else
                        <x-heroicon-o-shield-check class="w-4 h-4 text-green-600"/>
                    @endif
                </span>
                <div>
                    <p class="text-sm font-semibold text-gray-800">Session #{{ $session->id }}</p>
                    <p class="text-xs text-gray-500">{{ $statusLabel }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-400">{{ $counted }}/{{ $total }} counted</span>
                <x-heroicon-o-chevron-down class="w-4 h-4 text-gray-400 transition-transform"
                    ::class="expanded === {{ $session->id }} ? 'rotate-180' : ''"/>
            </div>
        </button>

        {{-- Expanded detail panel --}}
        <div x-show="expanded === {{ $session->id }}"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 -translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="border-t border-gray-100">
            <div class="px-4 py-3 space-y-3">

                {{-- Progress bar --}}
                <div>
                    <div class="flex justify-between text-xs text-gray-500 mb-1">
                        <span>Progress</span>
                        <span>{{ $total > 0 ? round(($counted / $total) * 100) : 0 }}%</span>
                    </div>
                    <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all {{ $isA ? 'bg-amber-400' : 'bg-green-500' }}"
                            style="width: {{ $total > 0 ? round(($counted / $total) * 100) : 0 }}%">
                        </div>
                    </div>
                </div>

                {{-- Participants --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 rounded-lg px-3 py-2">
                        <p class="text-[10px] text-gray-400 uppercase tracking-wider font-semibold mb-0.5">Storekeeper A</p>
                        <p class="text-sm font-semibold text-gray-700">{{ $session->storekeeperA->name ?? '—' }}</p>
                        <p class="text-xs text-green-600 font-medium mt-0.5">✓ Submitted</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg px-3 py-2">
                        <p class="text-[10px] text-gray-400 uppercase tracking-wider font-semibold mb-0.5">Storekeeper B</p>
                        @if($session->storekeeperB)
                            <p class="text-sm font-semibold text-gray-700">{{ $session->storekeeperB->name }}</p>
                            <p class="text-xs text-amber-600 font-medium mt-0.5">Counting…</p>
                        @else
                            <p class="text-sm text-gray-400 italic">Not joined yet</p>
                        @endif
                    </div>
                </div>

                {{-- Meta --}}
                <div class="flex justify-between text-xs text-gray-400 pt-1 border-t border-gray-100">
                    <span>Started {{ $session->created_at->diffForHumans() }}</span>
                    <span>{{ $total }} products · {{ $session->frequency }}</span>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif
</div>
