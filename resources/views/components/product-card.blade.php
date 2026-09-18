{{--
    One product, as a card.

    Lifted verbatim from the grid on the store page, which had already noted
    that extracting this was the right end state. It is used here by the
    product page's related rails only — the catalogue and the store page still
    render their own copies, so adopting this component changed nothing that
    was already on screen. Migrating those two is the follow-up; doing it in
    this change would have meant editing the main storefront's markup to serve
    a new section, which is how unrelated regressions happen.

    Deliberately link-only: no add-to-cart, no wishlist. Those need a Livewire
    method on whichever component renders the card, and the related rails are
    for getting a shopper to a *different product page*, not for buying from
    underneath the one they are already reading.
--}}
@props([
    'product',
    'eager' => false,      // the first card or two in a rail — skip lazy loading
    'showVendor' => false, // on the cross-vendor rail, whose shop this is matters
    'width' => null,       // fixed width for horizontal rails; null = fill the grid cell
])

@php
    $thumbUrl     = $product->getFirstMediaUrl('product-images', 'preview');
    $categoryIcon = \App\Support\CategoryIcon::for($product->category?->name);
    // Same rotating tint the storefront grids use, so a card pulled out of one
    // and dropped into a rail does not suddenly look like a different card.
    $cardBgs = [
        'background:linear-gradient(135deg,#f0f8f0,#e8f5e9)',
        'background:linear-gradient(135deg,#fff4ec,#ffe8d6)',
        'background:linear-gradient(135deg,#eef7ff,#dbeeff)',
        'background:linear-gradient(135deg,#f6f0ff,#eadcff)',
    ];
    $bg = $cardBgs[$product->id % count($cardBgs)];
@endphp

<div {{ $attributes->merge(['class' => 'group bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden transition-all hover:-translate-y-[3px] hover:shadow-[0_8px_30px_rgba(6,139,3,0.1)]']) }}
     @if ($width) style="width:{{ $width }}" @endif>
    <a href="{{ route('product.show', $product) }}" class="block">
        <div class="gp-card-img h-[140px] flex items-center justify-center relative" style="{{ $bg }}">
            @if ($thumbUrl)
                <img src="{{ $thumbUrl }}" alt="{{ $product->name }}"
                     width="400" height="280"
                     loading="{{ $eager ? 'eager' : 'lazy' }}"
                     decoding="async"
                     class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
            @else
                <x-gp-icon :name="$categoryIcon" class="w-12 h-12 text-brand opacity-40" />
            @endif
        </div>
    </a>

    <div class="p-3">
        <a href="{{ route('product.show', $product) }}"
           class="block text-[12px] font-semibold text-[#111] dark:text-[#e8f5e9] leading-[1.35] mb-2 hover:text-brand transition-colors line-clamp-2">
            {{ $product->name }}
        </a>

        @if ($showVendor && $product->vendor)
            {{-- Named, not badged-as-an-afterthought: this card is somebody
                 else's shop and the shopper should know before they tap. --}}
            <p class="flex items-center gap-1 text-[10px] text-brand-muted mb-1">
                <svg class="w-2.5 h-2.5 shrink-0 fill-none" style="stroke:#068B03;stroke-width:3" viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                <span class="truncate">{{ $product->vendor->name }}</span>
            </p>
        @elseif ($product->brand)
            <p class="text-[10px] text-[#8a9e8c] mb-1">{{ $product->brand }}</p>
        @endif

        <div class="flex items-baseline gap-1.5">
            <span class="font-montserrat font-black text-[15px] text-brand">₦{{ number_format($product->price) }}</span>
        </div>
    </div>
</div>
