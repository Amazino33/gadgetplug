<x-filament-panels::page>
<div class="min-h-[80vh] flex items-start justify-center">
<div class="w-full max-w-md mx-auto">

@php
    $session       = $this->getSession();
    $role          = $this->getRole();
    $total         = $this->getTotalProducts();
    $product       = $this->getCurrentProduct();
    $isLastProduct = $total > 0 && $this->currentPosition >= $total;
    $isCounting    = $session && (
        ($session->status === 'a_counting' && $role === 'a') ||
        ($session->status === 'b_counting' && $role === 'b')
    );
@endphp

{{-- ── NO SESSION: Start Form ──────────────────────────────────────────── --}}
@if (!$session)
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-6 space-y-6">
    <div class="text-center">
        <div class="w-14 h-14 bg-[#1a3a1a] rounded-full flex items-center justify-center mx-auto mb-3">
            <x-heroicon-o-eye-slash class="w-7 h-7 text-[#4caf50]"/>
        </div>
        <h2 class="text-white font-montserrat font-bold text-xl">Start Blind Count</h2>
        <p class="text-[#5a7a5c] text-sm mt-1">Products will be served randomly. Count what you physically see.</p>
    </div>

    <div class="space-y-4">
        <div>
            <label class="text-[#7a9e7c] text-xs font-semibold uppercase tracking-wider mb-1.5 block">Frequency</label>
            <select wire:model="frequency"
                class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-[#4caf50]">
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="custom">Custom</option>
            </select>
        </div>

        @if($frequency === 'custom')
        <div>
            <label class="text-[#7a9e7c] text-xs font-semibold uppercase tracking-wider mb-1.5 block">Every N Days</label>
            <input type="number" wire:model="customDays" min="1"
                class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-[#4caf50]"
                placeholder="e.g. 3">
        </div>
        @endif

        <div class="flex items-center justify-between bg-[#162016] border border-[#2a3a2a] rounded-xl px-4 py-3">
            <div>
                <p class="text-white text-sm font-medium">Count by Category</p>
                <p class="text-[#5a7a5c] text-xs">Finish one category before the next</p>
            </div>
            <button wire:click="$toggle('byCategory')"
                class="relative w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none {{ $byCategory ? 'bg-[#4caf50]' : 'bg-[#2a3a2a]' }}">
                <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200 {{ $byCategory ? 'translate-x-5' : 'translate-x-0' }}"></span>
            </button>
        </div>
    </div>

    <button wire:click="startSession"
        class="w-full bg-[#4caf50] hover:bg-[#43a047] text-white font-bold py-3.5 rounded-xl transition-colors font-montserrat">
        Begin Count Session
    </button>
</div>

{{-- ── WAITING: A is still counting ────────────────────────────────────── --}}
@elseif($session->status === 'a_counting' && $role !== 'a')
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-4">
    <div class="w-14 h-14 bg-[#1a3a1a] rounded-full flex items-center justify-center mx-auto">
        <x-heroicon-o-clock class="w-7 h-7 text-amber-400"/>
    </div>
    <h2 class="text-white font-montserrat font-bold text-lg">Waiting for Storekeeper A</h2>
    <p class="text-[#5a7a5c] text-sm">{{ $session->storekeeperA->name }} is currently completing their count. You will be notified when it is your turn.</p>
</div>

{{-- ── WAITING: A submitted, B hasn't joined yet ───────────────────────── --}}
@elseif($session->status === 'b_counting' && $role === 'none')
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-5">
    <div class="w-14 h-14 bg-[#1a3a1a] rounded-full flex items-center justify-center mx-auto">
        <x-heroicon-o-shield-check class="w-7 h-7 text-[#4caf50]"/>
    </div>
    <div>
        <h2 class="text-white font-montserrat font-bold text-lg">Verification Required</h2>
        <p class="text-[#5a7a5c] text-sm mt-1">Storekeeper A has finished. Join as Storekeeper B to verify the count independently.</p>
    </div>
    <button wire:click="joinAsB"
        class="w-full bg-[#4caf50] hover:bg-[#43a047] text-white font-bold py-3.5 rounded-xl transition-colors font-montserrat">
        Join as Storekeeper B
    </button>
</div>

{{-- ── WAITING: A submitted, waiting for B ─────────────────────────────── --}}
@elseif($session->status === 'b_counting' && $role === 'a')
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-4">
    <div class="w-14 h-14 bg-[#1a2a1a] rounded-full flex items-center justify-center mx-auto">
        <x-heroicon-o-check-circle class="w-7 h-7 text-[#4caf50]"/>
    </div>
    <h2 class="text-white font-montserrat font-bold text-lg">Your count is submitted</h2>
    <p class="text-[#5a7a5c] text-sm">Waiting for Storekeeper B to complete their independent verification.</p>
</div>

{{-- ── COMPLETED ─────────────────────────────────────────────────────────── --}}
@elseif($session->status === 'completed')
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-4">
    <div class="w-14 h-14 bg-[#1a2a1a] rounded-full flex items-center justify-center mx-auto">
        <x-heroicon-o-check-badge class="w-7 h-7 text-[#4caf50]"/>
    </div>
    <h2 class="text-white font-montserrat font-bold text-lg">Session Complete</h2>
    <p class="text-[#5a7a5c] text-sm">The blind count has been processed. Check Audit Sessions for any discrepancies that need manager review.</p>
    <a href="{{ \App\Filament\Vendor\Resources\AuditSessions\AuditSessionResource::getUrl('index', tenant: filament()->getTenant()) }}"
        class="inline-block mt-2 text-[#4caf50] text-sm font-semibold hover:underline">
        View Audit Sessions →
    </a>
</div>

{{-- ── COUNTING UI ───────────────────────────────────────────────────────── --}}
@elseif($isCounting && $product)
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] overflow-hidden"
    x-data="{ count: $wire.entangle('count') }">

    {{-- Header --}}
    <div class="px-4 pt-4 pb-2 flex items-center justify-between">
        <span class="text-[#5a7a5c] text-xs font-semibold uppercase tracking-wider">Live Stock Audit</span>
        <span class="text-[#7a9e7c] text-xs font-semibold">ITEM {{ $this->currentPosition }} OF {{ $total }}</span>
    </div>

    {{-- Progress bar --}}
    <div class="px-4 mb-4">
        <div class="h-1.5 bg-[#1a3a1a] rounded-full overflow-hidden">
            <div class="h-full bg-[#4caf50] rounded-full transition-all duration-300"
                style="width: {{ $total > 0 ? round(($this->currentPosition / $total) * 100) : 0 }}%">
            </div>
        </div>
    </div>

    {{-- Product Image --}}
    <div class="relative mx-4 mb-4 bg-[#162016] rounded-xl overflow-hidden aspect-square">
        @php $imgUrl = $product->getFirstMediaUrl('product-images', 'preview'); @endphp
        @if($imgUrl)
            <img src="{{ $imgUrl }}" alt="{{ $product->name }}"
                class="w-full h-full object-contain p-4">
        @else
            <div class="w-full h-full flex items-center justify-center">
                <x-heroicon-o-photo class="w-16 h-16 text-[#2a3a2a]"/>
            </div>
        @endif

        {{-- Badge --}}
        @if($product->available_stock <= 5 && $product->available_stock > 0)
        <span class="absolute top-3 left-3 bg-amber-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-md uppercase tracking-wide">
            Low Stock
        </span>
        @elseif($product->available_stock === 0)
        <span class="absolute top-3 left-3 bg-red-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-md uppercase tracking-wide">
            Out of Stock
        </span>
        @endif
    </div>

    {{-- Product Info --}}
    <div class="px-4 mb-5 space-y-0.5">
        @if($product->sku)
        <p class="text-[#5a7a5c] text-[11px] font-semibold uppercase tracking-widest">SKU: {{ $product->sku }}</p>
        @endif
        <p class="text-white font-montserrat font-bold text-lg leading-tight">{{ $product->name }}</p>
        @if($product->barcode)
        <p class="text-[#5a7a5c] text-xs font-mono">{{ $product->barcode }}</p>
        @endif
    </div>

    {{-- Counter --}}
    <div class="px-4 mb-5">
        <div class="flex items-center gap-3">
            <button @click="count = Math.max(0, count - 1)"
                class="w-14 h-14 bg-[#162016] border border-[#2a3a2a] hover:border-[#4caf50] text-white text-2xl font-bold rounded-xl flex items-center justify-center transition-colors select-none">
                −
            </button>

            <div class="flex-1 text-center">
                <input type="number"
                    x-model="count"
                    min="0"
                    class="w-full bg-transparent text-white text-4xl font-bold text-center border-none outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                    placeholder="0">
                <p class="text-[#3a5a3c] text-[11px] mt-0.5">Tap to type exact number</p>
            </div>

            <button @click="count++"
                class="w-14 h-14 bg-[#162016] border border-[#2a3a2a] hover:border-[#4caf50] text-white text-2xl font-bold rounded-xl flex items-center justify-center transition-colors select-none">
                +
            </button>
        </div>
    </div>

    {{-- Search previously counted --}}
    <div class="px-4 mb-4">
        <button wire:click="$toggle('showSearch')"
            class="text-[#5a7a5c] hover:text-[#4caf50] text-xs flex items-center gap-1.5 transition-colors">
            <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5"/>
            {{ $showSearch ? 'Hide' : 'Search counted products' }}
        </button>

        @if($showSearch)
        <div class="mt-2 space-y-2">
            <input type="text" wire:model.live.debounce.300ms="searchQuery"
                placeholder="Search by name or SKU…"
                class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#4caf50] placeholder-[#3a5a3c]">

            <div class="space-y-1 max-h-40 overflow-y-auto">
                @foreach($this->getCountedEntries() as $entry)
                <button wire:click="goToPosition({{ $entry->position }})"
                    class="w-full text-left flex items-center justify-between px-3 py-2 bg-[#162016] hover:bg-[#1a2a1a] rounded-lg transition-colors">
                    <span class="text-white text-xs">{{ $entry->product->name }}</span>
                    <span class="text-[#4caf50] text-xs font-bold">{{ $entry->count }}</span>
                </button>
                @endforeach
                @if($this->getCountedEntries()->isEmpty())
                <p class="text-[#3a5a3c] text-xs px-1">No counted products yet.</p>
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- Action Button --}}
    <div class="px-4 pb-5">
        @if($isLastProduct)
        <button wire:click="submitAll" wire:confirm="This will lock your count and cannot be undone. Are you sure?"
            class="w-full bg-[#4caf50] hover:bg-[#43a047] text-white font-bold py-4 rounded-xl transition-colors font-montserrat text-base">
            Submit All Counts ✓
        </button>
        @else
        <button wire:click="next"
            class="w-full bg-[#e65c00] hover:bg-[#d35400] text-white font-bold py-4 rounded-xl transition-colors font-montserrat text-base flex items-center justify-center gap-2">
            Next <span>→</span>
        </button>
        @endif
    </div>
</div>
@endif

</div>
</div>
</x-filament-panels::page>
