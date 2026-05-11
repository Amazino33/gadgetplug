<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Services\CartService;

new class extends Component {
    public Product $product;
    public int $quantity = 1;

    public function mount(Product $product): void
    {
        $this->product = $product->load(['vendor', 'category', 'media']);
    }

    public function incrementQty(): void
    {
        if ($this->quantity < $this->product->stock_quantity) {
            $this->quantity++;
        }
    }

    public function decrementQty(): void
    {
        if ($this->quantity > 1) {
            $this->quantity--;
        }
    }

    public function addToCart(): void
    {
        app(CartService::class)->add($this->product, $this->quantity);
        $this->dispatch('cart-updated');
    }

    public function buyNow(): void
    {
        app(CartService::class)->add($this->product, $this->quantity);
        $this->dispatch('cart-updated');
        $this->redirectRoute('checkout');
    }
}; ?>

@php
$allImages  = $product->getMedia('product-images');
$firstImage = $allImages->first();
$defaultUrl = $firstImage ? $firstImage->getUrl() : '';

$categoryEmojis = [
    'phones' => '📱', 'mobile' => '📱', 'smartphones' => '📱',
    'laptops' => '💻', 'computers' => '💻',
    'audio' => '🎧', 'headphones' => '🎧', 'speakers' => '🎧',
    'wearables' => '⌚', 'watches' => '⌚',
    'gaming' => '🎮', 'cameras' => '📷',
    'accessories' => '🔌', 'smart home' => '🏠',
    'refurbished' => '♻️',
];
$emoji = $categoryEmojis[strtolower($product->category?->name ?? '')] ?? '📦';
@endphp

<div>
<x-layouts.storefront>

<div class="px-4 md:px-6 py-6 bg-[#f8fcf8] dark:bg-[#0d1a0d] min-h-screen">

    {{-- ─── BREADCRUMB ──────────────────────────────────────────────────────── --}}
    <nav class="flex items-center gap-1.5 text-[12px] text-brand-muted mb-6">
        <a href="{{ route('home') }}" class="hover:text-brand transition-colors">Home</a>
        <svg class="w-3 h-3 text-[#ccc] dark:text-[#2a3a2a]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
        </svg>
        @if ($product->category)
        <a href="{{ route('home', ['category' => strtolower($product->category->name)]) }}"
           class="hover:text-brand transition-colors">{{ $product->category->name }}</a>
        <svg class="w-3 h-3 text-[#ccc] dark:text-[#2a3a2a]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
        </svg>
        @endif
        <span class="text-[#111] dark:text-[#e8f5e9] font-medium line-clamp-1">{{ $product->name }}</span>
    </nav>

    {{-- ─── MAIN PRODUCT AREA ───────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">

        {{-- Left: Image Gallery --}}
        <div x-data="{ current: '{{ $defaultUrl }}' }" class="flex flex-col gap-3">

            {{-- Main image --}}
            <div class="aspect-square w-full bg-brand-bg dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden relative">
                @if ($firstImage)
                    <img :src="current" alt="{{ $product->name }}"
                        class="w-full h-full object-cover transition-all duration-300">
                @else
                    <div class="w-full h-full flex flex-col items-center justify-center gap-3 bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                        <span class="text-[100px] leading-none opacity-60">{{ $emoji }}</span>
                        <span class="text-[12px] text-brand-muted font-medium">No image available</span>
                    </div>
                @endif

                {{-- Category tag --}}
                @if ($product->category)
                <div class="absolute top-3 left-3 bg-brand-lime text-brand-dark text-[10px] font-bold font-montserrat px-2.5 py-1 rounded-full">
                    {{ $product->category->name }}
                </div>
                @endif

                {{-- Wishlist --}}
                <button class="absolute top-3 right-3 w-9 h-9 bg-white dark:bg-[#1a2a1a] rounded-full flex items-center justify-center shadow-md hover:bg-[#fce4ec] dark:hover:bg-[#1f2f1f] transition-colors">
                    <svg class="w-4 h-4 fill-none" style="stroke:#666;stroke-width:1.8" viewBox="0 0 24 24">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                    </svg>
                </button>
            </div>

            {{-- Thumbnails --}}
            @if ($allImages->count() > 1)
            <div class="grid grid-cols-4 gap-2">
                @foreach ($allImages as $image)
                @php $previewUrl = $image->getUrl(); @endphp
                <button @click="current = '{{ $previewUrl }}'"
                    class="aspect-square rounded-xl overflow-hidden border-2 transition-all duration-200 focus:outline-none"
                    :class="current === '{{ $previewUrl }}' ? 'border-brand opacity-100' : 'border-transparent opacity-60 hover:opacity-100 hover:border-brand-border'">
                    <img src="{{ $image->getUrl('thumb') }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                </button>
                @endforeach
            </div>
            @endif
        </div>

        {{-- Right: Product Info --}}
        <div class="flex flex-col">

            {{-- Vendor --}}
            <div class="flex items-center gap-2 mb-3">
                <div class="flex items-center gap-1.5">
                    <span class="text-[12px] text-brand-muted font-medium">{{ $product->vendor->name ?? 'Unknown Vendor' }}</span>
                    <div class="w-4 h-4 bg-brand rounded-full flex items-center justify-center flex-shrink-0">
                        <svg class="w-2.5 h-2.5 fill-none" style="stroke:#fff;stroke-width:2.5" viewBox="0 0 24 24">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </div>
                    <span class="text-[10px] text-brand font-semibold">Verified</span>
                </div>
                @if ($product->brand)
                <span class="text-[#ccc] dark:text-[#2a3a2a]">·</span>
                <span class="text-[12px] text-brand-muted">{{ $product->brand }}</span>
                @endif
            </div>

            {{-- Product name --}}
            <h1 class="font-montserrat font-black text-[24px] md:text-[28px] text-brand-dark dark:text-[#e8f5e9] leading-tight tracking-tight mb-4">
                {{ $product->name }}
            </h1>

            {{-- Price + stock --}}
            <div class="flex items-center gap-3 mb-5 pb-5 border-b border-brand-border dark:border-[#2a3a2a]">
                <span class="font-montserrat font-black text-[32px] text-brand leading-none">
                    ₦{{ number_format($product->price) }}
                </span>
                @if ($product->stock_quantity > 0)
                <span class="flex items-center gap-1 bg-[#e8f5e9] dark:bg-[#1a2a1a] text-brand text-[11px] font-bold font-montserrat px-2.5 py-1 rounded-full">
                    <span class="w-1.5 h-1.5 rounded-full bg-brand inline-block"></span>
                    In Stock ({{ $product->stock_quantity }})
                </span>
                @else
                <span class="flex items-center gap-1 bg-[#fce4ec] text-red-600 text-[11px] font-bold font-montserrat px-2.5 py-1 rounded-full">
                    <span class="w-1.5 h-1.5 rounded-full bg-red-500 inline-block"></span>
                    Out of Stock
                </span>
                @endif
            </div>

            {{-- Quantity selector --}}
            @if ($product->stock_quantity > 0)
            <div class="mb-4">
                <label class="text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-2 block">Quantity</label>
                <div class="flex items-center gap-0">
                    <button wire:click="decrementQty"
                        class="w-10 h-10 rounded-l-xl bg-brand-bg dark:bg-[#1a2a1a] border border-brand-border dark:border-[#2a3a2a] flex items-center justify-center text-brand font-bold text-lg hover:bg-brand hover:text-white hover:border-brand transition-colors cursor-pointer"
                        @disabled($quantity <= 1)>
                        −
                    </button>
                    <div class="w-14 h-10 border-y border-brand-border dark:border-[#2a3a2a] bg-white dark:bg-[#1a2a1a] flex items-center justify-center font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">
                        {{ $quantity }}
                    </div>
                    <button wire:click="incrementQty"
                        class="w-10 h-10 rounded-r-xl bg-brand-bg dark:bg-[#1a2a1a] border border-brand-border dark:border-[#2a3a2a] flex items-center justify-center text-brand font-bold text-lg hover:bg-brand hover:text-white hover:border-brand transition-colors cursor-pointer"
                        @disabled($quantity >= $product->stock_quantity)>
                        +
                    </button>
                    <span class="ml-3 text-[11px] text-brand-muted">Max: {{ $product->stock_quantity }}</span>
                </div>
            </div>
            @endif

            {{-- Add to cart + Buy Now --}}
            <div class="flex gap-2.5 mb-6">
                <button wire:click="addToCart"
                    class="flex-1 flex items-center justify-center gap-2 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] py-3.5 rounded-xl border-0 cursor-pointer transition-all hover:-translate-y-px disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:translate-y-0"
                    @disabled($product->stock_quantity < 1)>
                    <svg class="w-4 h-4 fill-none flex-shrink-0" style="stroke:currentColor;stroke-width:2.5" viewBox="0 0 24 24">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                    Add to Cart
                </button>
                <button wire:click="buyNow"
                    class="flex-1 flex items-center justify-center gap-2 bg-brand-orange hover:bg-[#e06610] text-white font-montserrat font-bold text-[13px] py-3.5 rounded-xl border-0 cursor-pointer transition-all hover:-translate-y-px disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:translate-y-0"
                    @disabled($product->stock_quantity < 1)>
                    <svg class="w-4 h-4 fill-none flex-shrink-0" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                    Buy Now
                </button>
                <button class="w-12 h-[50px] bg-brand-bg dark:bg-[#1a2a1a] border border-brand-border dark:border-[#2a3a2a] rounded-xl flex items-center justify-center hover:border-brand transition-colors cursor-pointer flex-shrink-0">
                    <svg class="w-5 h-5 fill-none" style="stroke:#5a7a5c;stroke-width:1.8" viewBox="0 0 24 24">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                    </svg>
                </button>
            </div>

            {{-- Trust mini-badges --}}
            <div class="flex flex-col gap-2 p-4 bg-[#f8fcf8] dark:bg-[#162016] rounded-xl border border-[#e0eee0] dark:border-[#2a3a2a]">
                @foreach([
                    ['icon'=>'shield','text'=>'Verified vendor — CAC registered business'],
                    ['icon'=>'clock','text'=>'Test before you pay — rider brings to you'],
                    ['icon'=>'bolt','text'=>'2-hour dispatch for orders before 4pm'],
                ] as $badge)
                <div class="flex items-center gap-2.5">
                    @if ($badge['icon'] === 'shield')
                    <svg class="w-4 h-4 fill-none flex-shrink-0" style="stroke:#068B03;stroke-width:2" viewBox="0 0 24 24">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <polyline points="9 12 11 14 15 10" style="stroke:#068B03;stroke-width:2.5"/>
                    </svg>
                    @elseif ($badge['icon'] === 'clock')
                    <svg class="w-4 h-4 fill-none flex-shrink-0" style="stroke:#F97316;stroke-width:2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    @else
                    <svg class="w-4 h-4 fill-brand-lime flex-shrink-0" viewBox="0 0 24 24">
                        <path d="M13 2L4 14h8l-1 8 9-12h-8z"/>
                    </svg>
                    @endif
                    <span class="text-[11px] text-[#4a6b4c] dark:text-[#b0c8b0]">{{ $badge['text'] }}</span>
                </div>
                @endforeach
            </div>

            {{-- Description --}}
            @if ($product->description)
            <div class="mt-6 pt-6 border-t border-brand-border dark:border-[#2a3a2a]">
                <h3 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-3">About this product</h3>
                <div class="text-[13px] text-[#444] dark:text-[#b0c8b0] leading-relaxed whitespace-pre-wrap">{{ $product->description }}</div>
            </div>
            @endif
        </div>
    </div>

    {{-- ─── SPECIFICATIONS ──────────────────────────────────────────────────── --}}
    @if (!empty($product->specifications))
    <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
        <div class="px-6 py-4 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
            <h2 class="font-montserrat font-bold text-[16px] text-brand-dark dark:text-[#e8f5e9]">Specifications</h2>
        </div>
        <div class="divide-y divide-[#f0f4f1] dark:divide-[#2a3a2a]">
            @foreach ($product->specifications as $key => $value)
            <div class="flex px-6 py-3.5 hover:bg-[#fafcfa] dark:hover:bg-[#1f2f1f] transition-colors">
                <dt class="w-[180px] flex-shrink-0 text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9]">
                    {{ ucwords(str_replace('_', ' ', $key)) }}
                </dt>
                <dd class="flex-1 text-[12px] text-[#555] dark:text-[#b0c8b0]">
                    {{ is_array($value) ? implode(', ', $value) : $value }}
                </dd>
            </div>
            @endforeach
        </div>
    </div>
    @endif

</div>

</x-layouts.storefront>
</div>
