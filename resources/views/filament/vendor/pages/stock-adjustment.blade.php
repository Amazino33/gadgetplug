<x-filament-panels::page>
@php
    $ready   = collect($this->preview)->whereNull('error');
    $changes = $ready->filter(fn ($r) => (int) ($r['change'] ?? 0) !== 0);
    $problem = collect($this->preview)->whereNotNull('error');
@endphp

<div class="w-full max-w-4xl mx-auto space-y-4">

    {{-- What this does, in the words of the person using it. Setting stock by
         hand is the kind of thing that deserves a sentence of explanation. --}}
    <div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-5 space-y-2">
        <h2 class="text-white font-montserrat font-bold text-base">Set stock from a sheet</h2>
        <p class="text-[#5a7a5c] text-sm">
            Paste one product per line: <span class="text-[#7a9e7c] font-mono">SKU or barcode</span>, then the
            quantity on hand. Copying two columns straight out of a spreadsheet works.
        </p>
        <p class="text-[#5a7a5c] text-sm">
            Quantities are the <span class="text-white font-semibold">total on the shelf</span>, not an amount to add.
            Stock lands in <span class="text-[#4caf50] font-semibold">{{ $this->getStoreName() }}</span> and is
            recorded in Stock Movement with your reason.
        </p>
    </div>

    <div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-5 space-y-4">
        <div>
            <label for="pasted" class="text-[#7a9e7c] text-xs font-semibold uppercase tracking-wider mb-1.5 block">
                Paste your rows
            </label>
            <textarea id="pasted" wire:model="pasted" rows="10" spellcheck="false"
                placeholder="ISW-023P&#9;12&#10;B1481&#9;4&#10;6901443&#9;30"
                class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-xl px-4 py-3 text-sm font-mono leading-relaxed focus:outline-none focus:border-[#4caf50] placeholder-[#3a5a3c]"></textarea>
            <p class="text-[#3a5a3c] text-xs mt-1">Up to {{ $this::MAX_ROWS }} lines at a time.</p>
        </div>

        <div>
            <label for="reason" class="text-[#7a9e7c] text-xs font-semibold uppercase tracking-wider mb-1.5 block">
                Reason
            </label>
            <input id="reason" type="text" wire:model="reason"
                class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-[#4caf50] placeholder-[#3a5a3c]">
            @error('reason')
                <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
            <p class="text-[#3a5a3c] text-xs mt-1">Saved against every stock movement this creates.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="buildPreview" wire:loading.attr="disabled" wire:target="buildPreview"
                class="bg-[#4caf50] hover:bg-[#43a047] disabled:opacity-60 text-white font-bold text-sm px-5 py-2.5 rounded-xl transition-colors font-montserrat focus:outline-none focus:ring-2 focus:ring-[#4caf50] focus:ring-offset-2 focus:ring-offset-[#0d1a0d]">
                <span wire:loading.remove wire:target="buildPreview">Check the list</span>
                <span wire:loading wire:target="buildPreview">Checking…</span>
            </button>

            @if($this->hasPreviewed && $changes->isNotEmpty())
            <button wire:click="apply" wire:loading.attr="disabled" wire:target="apply"
                wire:confirm="Set stock for {{ $changes->count() }} product(s) in {{ $this->getStoreName() }}? This is recorded in Stock Movement and cannot be undone in bulk."
                class="bg-[#e65c00] hover:bg-[#d35400] disabled:opacity-60 text-white font-bold text-sm px-5 py-2.5 rounded-xl transition-colors font-montserrat focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-[#0d1a0d]">
                <span wire:loading.remove wire:target="apply">Apply {{ $changes->count() }} change(s)</span>
                <span wire:loading wire:target="apply">Applying…</span>
            </button>
            @endif

            @if($this->pasted !== '')
            <button wire:click="clearAll"
                class="border border-[#2a3a2a] hover:border-[#4caf50] text-[#7a9e7c] text-sm font-semibold px-4 py-2.5 rounded-xl transition-colors">
                Clear
            </button>
            @endif
        </div>
    </div>

    @if($this->hasPreviewed && count($this->preview) > 0)
    <div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] overflow-hidden">
        <div class="px-5 py-3 bg-[#162016] border-b border-[#1a3a1a] flex flex-wrap items-center gap-x-4 gap-y-1">
            <span class="text-[#4caf50] text-xs font-semibold">{{ $changes->count() }} will change</span>
            <span class="text-[#5a7a5c] text-xs">{{ $ready->count() - $changes->count() }} already correct</span>
            @if($problem->isNotEmpty())
            <span class="text-[#c96a6a] text-xs font-semibold">{{ $problem->count() }} need attention</span>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-[#5a7a5c] text-[11px] uppercase tracking-wider">
                        <th class="text-left font-semibold px-4 py-2">Product</th>
                        <th class="text-right font-semibold px-4 py-2">Now</th>
                        <th class="text-right font-semibold px-4 py-2">Sheet</th>
                        <th class="text-right font-semibold px-4 py-2">Change</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#162016]">
                    @foreach($this->preview as $row)
                    <tr class="{{ $row['error'] ? 'bg-red-950/20' : '' }}">
                        <td class="px-4 py-2.5">
                            <p class="{{ $row['error'] ? 'text-[#c96a6a]' : 'text-white' }} leading-tight">{{ $row['name'] }}</p>
                            @if($row['error'])
                                <p class="text-[#c96a6a] text-[11px] mt-0.5">{{ $row['error'] }}</p>
                            @elseif($row['sku'])
                                <p class="text-[#3a5a3c] text-[11px] font-mono mt-0.5">{{ $row['sku'] }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right text-[#7a9e7c] tabular-nums">{{ $row['current'] ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right text-white tabular-nums">{{ $row['target'] ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums font-semibold
                            @if($row['change'] === null) text-[#3a5a3c]
                            @elseif($row['change'] > 0) text-[#4caf50]
                            @elseif($row['change'] < 0) text-[#e0873f]
                            @else text-[#3a5a3c] @endif">
                            @if($row['change'] === null) —
                            @elseif($row['change'] > 0) +{{ $row['change'] }}
                            @else {{ $row['change'] }}
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>
</x-filament-panels::page>
