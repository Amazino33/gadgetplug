<?php

use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;

/**
 * The store directory — where "Browse Verified Plugs" on the home hero lands.
 *
 * Lists every store a customer could actually buy from: online sales on, and
 * at least one product that passes the same visibility and stock rules the
 * store page itself applies. A store with nothing to sell would open onto an
 * empty shelf, so it is left off rather than listed. Verified stores lead.
 */
new class extends Component {
    public function with(): array
    {
        $sellable = fn (Builder $q) => $q->visibleOnline()->inStockForSale();

        return [
            'vendors' => Vendor::query()
                ->where('online_sales_enabled', true)
                ->whereHas('products', $sellable)
                ->withCount(['products' => $sellable])
                ->orderByDesc('is_verified')
                ->orderByDesc('products_count')
                ->orderBy('name')
                ->get(),
        ];
    }
}; ?>

{{-- Single root element for Livewire; the layout renders the whole document. --}}
<div>
<x-layouts.storefront>

    <div class="bg-white dark:bg-[#1a2a1a] border-b border-brand-border dark:border-[#2a3a2a]">
        <div class="max-w-6xl mx-auto px-4 py-6 sm:py-8">
            <h1 class="font-montserrat text-lg sm:text-2xl font-black text-[#111] dark:text-[#e8f5e9]">Browse Plugs</h1>
            <p class="mt-1 text-[13px] text-[#5a7a5c] dark:text-[#9ab89c]">
                Shop straight from the stores selling on GadgetPlug. Verified stores are listed first.
            </p>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 py-5">
        @if ($vendors->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5">
                @foreach ($vendors as $vendor)
                    <a href="{{ route('store.show', $vendor) }}"
                       class="group flex items-start gap-3 bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] p-4 transition-all hover:-translate-y-[3px] hover:shadow-[0_8px_30px_rgba(6,139,3,0.1)]">
                        <div class="h-12 w-12 shrink-0 overflow-hidden rounded-full bg-brand-bg ring-1 ring-brand-border">
                            @if ($vendor->logo_url)
                                <img src="{{ $vendor->logo_url }}" alt="{{ $vendor->name }}" class="h-full w-full object-cover">
                            @else
                                <span class="flex h-full w-full items-center justify-center font-montserrat text-sm font-black text-brand">
                                    {{ strtoupper(substr($vendor->name, 0, 2)) }}
                                </span>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <p class="truncate font-montserrat text-[14px] font-black text-[#111] dark:text-[#e8f5e9] group-hover:text-brand transition-colors">
                                    {{ $vendor->name }}
                                </p>
                                @if ($vendor->is_verified)
                                    <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-brand" title="Verified store">
                                        <svg class="h-2.5 w-2.5 fill-none" style="stroke:#fff;stroke-width:3" viewBox="0 0 24 24">
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg>
                                    </span>
                                @endif
                            </div>

                            @if ($vendor->location)
                                <p class="mt-0.5 truncate text-[12px] text-[#7a9e7c]">{{ $vendor->location }}</p>
                            @endif

                            @if ($vendor->description)
                                <p class="mt-1 text-[12px] leading-snug text-[#5a7a5c] dark:text-[#9ab89c] line-clamp-2">{{ $vendor->description }}</p>
                            @endif

                            <p class="mt-1.5 text-[11px] font-semibold text-[#8a9e8c]">
                                {{ $vendor->products_count }} {{ Str::plural('product', $vendor->products_count) }} available
                            </p>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="py-16 text-center">
                <p class="font-montserrat text-[15px] font-bold text-[#111] dark:text-[#e8f5e9]">No stores to show yet</p>
                <p class="mt-1 text-[13px] text-[#7a9e7c]">Check back soon — new plugs are joining.</p>
                <a href="{{ route('home') }}"
                   class="mt-4 inline-block rounded-full bg-brand px-6 py-2.5 font-montserrat text-[13px] font-black text-white">
                    Browse the marketplace
                </a>
            </div>
        @endif
    </div>
</x-layouts.storefront>
</div>
