<x-filament-panels::page>
@php
    $grouped = $this->getGroupedProducts(applySearch: true);
    $shown   = $grouped->flatten()->count();
    $total   = $this->getProductCount();
@endphp

<div class="w-full max-w-3xl mx-auto space-y-4">

    {{-- Download + search. The download always returns the full list, never the
         filtered one — a partial pricelist living on someone's phone is worse
         than no pricelist at all. --}}
    <div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-4 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-white font-montserrat font-bold text-base leading-tight">Live Price List</h2>
                <p class="text-[#5a7a5c] text-xs mt-0.5">
                    {{ $total }} published {{ Str::plural('product', $total) }} · prices update as you change them
                </p>
            </div>
            <button wire:click="downloadPdf"
                wire:loading.attr="disabled"
                wire:target="downloadPdf"
                class="shrink-0 flex items-center gap-2 bg-[#4caf50] hover:bg-[#43a047] disabled:opacity-60 text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-colors font-montserrat focus:outline-none focus:ring-2 focus:ring-[#4caf50] focus:ring-offset-2 focus:ring-offset-[#0d1a0d]">
                <x-heroicon-o-arrow-down-tray class="w-4 h-4" wire:loading.remove wire:target="downloadPdf"/>
                <span wire:loading.remove wire:target="downloadPdf">Download PDF</span>
                <span wire:loading wire:target="downloadPdf">Preparing…</span>
            </button>
        </div>

        <input type="text" wire:model.live.debounce.300ms="search"
            aria-label="Search products"
            placeholder="Search by name, SKU or brand…"
            class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-[#4caf50] placeholder-[#3a5a3c]">

        @if($this->search !== '')
        <p class="text-[#5a7a5c] text-xs">
            Showing {{ $shown }} of {{ $total }}. The PDF always contains the full list.
        </p>
        @endif
    </div>

    @forelse($grouped as $categoryName => $products)
    <div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] overflow-hidden">
        <div class="px-4 py-2.5 bg-[#162016] border-b border-[#1a3a1a] flex items-center justify-between">
            <h3 class="text-[#7a9e7c] text-xs font-semibold uppercase tracking-wider">{{ $categoryName }}</h3>
            <span class="text-[#3a5a3c] text-xs">{{ $products->count() }}</span>
        </div>

        <div class="divide-y divide-[#162016]">
            @foreach($products as $product)
            <div class="px-4 py-2.5 flex items-baseline justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm leading-tight {{ (int) $product->stock_quantity <= 0 ? 'text-[#5a7a5c]' : 'text-white' }}">
                        {{ $product->name }}
                        @if((int) $product->stock_quantity <= 0)
                        <span class="text-[10px] text-[#c96a6a] font-semibold uppercase ml-1">out of stock</span>
                        @endif
                    </p>
                    @if($product->sku)
                    <p class="text-[#3a5a3c] text-[11px] font-mono mt-0.5 truncate">{{ $product->sku }}</p>
                    @endif
                </div>
                <p class="shrink-0 text-sm font-bold whitespace-nowrap {{ (int) $product->stock_quantity <= 0 ? 'text-[#5a7a5c]' : 'text-[#4caf50]' }}">
                    &#8358;{{ number_format((float) $product->price, 2) }}
                </p>
            </div>
            @endforeach
        </div>
    </div>
    @empty
    <div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-2">
        <div class="w-14 h-14 bg-[#1a3a1a] rounded-full flex items-center justify-center mx-auto">
            <x-heroicon-o-tag class="w-7 h-7 text-[#5a7a5c]"/>
        </div>
        <h3 class="text-white font-montserrat font-bold text-base">
            {{ $this->search !== '' ? 'No matching products' : 'No published products yet' }}
        </h3>
        <p class="text-[#5a7a5c] text-sm">
            {{ $this->search !== '' ? 'Try a different name, SKU or brand.' : 'Publish a product and it will appear here automatically.' }}
        </p>
    </div>
    @endforelse

</div>
</x-filament-panels::page>
