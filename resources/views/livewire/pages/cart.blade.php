<?php

use App\Models\Product;
use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Session;

new class extends Component {
    public array $cartItems = [];
    public float $total = 0;

    public function mount(): void
    {
        $this->loadCart();
    }

    #[On('cart-updated')]
    public function loadCart(): void
    {
        $cart = Session::get('cart', []);
        $this->cartItems = [];
        $this->total = 0;

        if (empty($cart)) return;

        $productIds = array_keys($cart);
        $products   = Product::with('media')->whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($cart as $productId => $item) {
            $product = $products->get($productId);
            if (!$product) continue;

            $qty              = $item['quantity'];
            $this->total     += $product->price * $qty;
            $this->cartItems[] = [
                'id'       => $productId,
                'name'     => $product->name,
                'vendor'   => $product->vendor?->name ?? 'Unknown Vendor',
                'brand'    => $product->brand,
                'price'    => (float) $product->price,
                'quantity' => $qty,
                'subtotal' => (float) ($product->price * $qty),
                'max'      => $item['max'] ?? $product->stock_quantity,
                'thumb'    => $product->getFirstMediaUrl('product-images', 'thumb'),
                'slug'     => $product->slug,
            ];
        }
    }

    public function increment(int $productId): void
    {
        $cart = Session::get('cart', []);
        if (!isset($cart[$productId])) return;

        $max = $cart[$productId]['max'] ?? 99;
        $cart[$productId]['quantity'] = min($cart[$productId]['quantity'] + 1, $max);
        Session::put('cart', $cart);
        $this->dispatch('cart-updated');
        $this->loadCart();
    }

    public function decrement(int $productId): void
    {
        $cart = Session::get('cart', []);
        if (!isset($cart[$productId])) return;

        if ($cart[$productId]['quantity'] <= 1) {
            $this->removeItem($productId);
            return;
        }

        $cart[$productId]['quantity']--;
        Session::put('cart', $cart);
        $this->dispatch('cart-updated');
        $this->loadCart();
    }

    public function removeItem(int $productId): void
    {
        $cart = Session::get('cart', []);
        unset($cart[$productId]);
        Session::put('cart', $cart);
        $this->dispatch('cart-updated');
        $this->loadCart();
    }

    public function clearCart(): void
    {
        Session::forget('cart');
        $this->dispatch('cart-updated');
        $this->loadCart();
    }
}; ?>

<div>
<x-layouts.storefront>

<div class="px-4 md:px-6 py-7 max-w-[1100px] mx-auto">

    {{-- ─── PAGE HEADER ─────────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="font-montserrat font-black text-[24px] md:text-[28px] text-brand-dark dark:text-[#e8f5e9]">Shopping Cart</h1>
            @if (count($cartItems) > 0)
            <p class="text-[13px] text-brand-muted mt-0.5">
                {{ array_sum(array_column($cartItems, 'quantity')) }} item{{ array_sum(array_column($cartItems, 'quantity')) !== 1 ? 's' : '' }} in your cart
            </p>
            @endif
        </div>
        <a href="{{ route('home') }}"
           class="flex items-center gap-1.5 text-[12px] text-brand font-semibold hover:underline">
            <svg class="w-4 h-4 fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Continue Shopping
        </a>
    </div>

    @if (count($cartItems) > 0)

    <div class="flex flex-col lg:flex-row gap-6 items-start">

        {{-- ─── CART ITEMS ──────────────────────────────────────────────────── --}}
        <div class="flex-1 min-w-0 space-y-3">

            @foreach ($cartItems as $item)
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] p-4 flex gap-4 items-center">

                {{-- Thumbnail --}}
                <a href="{{ route('product.show', ['product' => $item['slug']]) }}"
                   class="w-20 h-20 flex-shrink-0 rounded-xl bg-brand-bg dark:bg-[#0d1a0d] border border-brand-border dark:border-[#2a3a2a] overflow-hidden flex items-center justify-center">
                    @if ($item['thumb'])
                        <img src="{{ $item['thumb'] }}" alt="{{ $item['name'] }}" class="w-full h-full object-cover">
                    @else
                        <span class="text-3xl">📦</span>
                    @endif
                </a>

                {{-- Product info --}}
                <div class="flex-1 min-w-0">
                    <div class="text-[10px] text-brand-muted font-medium mb-0.5">{{ $item['vendor'] }}</div>
                    <a href="{{ route('product.show', ['product' => $item['slug']]) }}"
                       class="text-[13px] font-semibold text-[#111] dark:text-[#e8f5e9] hover:text-brand transition-colors line-clamp-2 leading-snug">
                        {{ $item['name'] }}
                    </a>
                    @if ($item['brand'])
                    <div class="text-[11px] text-brand-muted mt-0.5">{{ $item['brand'] }}</div>
                    @endif
                    <div class="text-[12px] text-brand-muted mt-1">₦{{ number_format($item['price']) }} each</div>
                </div>

                {{-- Qty controls + subtotal + remove --}}
                <div class="flex flex-col items-end gap-2.5 flex-shrink-0">
                    <span class="font-montserrat font-black text-[16px] text-brand">₦{{ number_format($item['subtotal']) }}</span>

                    {{-- +/- controls --}}
                    <div class="flex items-center rounded-xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
                        <button wire:click="decrement({{ $item['id'] }})"
                            class="w-9 h-9 flex items-center justify-center bg-brand-bg dark:bg-[#0d1a0d] hover:bg-brand hover:text-white text-brand font-bold text-lg transition-colors cursor-pointer border-r border-brand-border dark:border-[#2a3a2a]">
                            −
                        </button>
                        <span class="w-10 text-center font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9] bg-white dark:bg-[#1a2a1a]">
                            {{ $item['quantity'] }}
                        </span>
                        <button wire:click="increment({{ $item['id'] }})"
                            class="w-9 h-9 flex items-center justify-center bg-brand-bg dark:bg-[#0d1a0d] hover:bg-brand hover:text-white text-brand font-bold text-lg transition-colors cursor-pointer border-l border-brand-border dark:border-[#2a3a2a]"
                            @disabled($item['quantity'] >= $item['max'])>
                            +
                        </button>
                    </div>

                    <button wire:click="removeItem({{ $item['id'] }})"
                        class="text-[11px] text-[#bbb] dark:text-[#7a9e7c] hover:text-red-500 transition-colors cursor-pointer flex items-center gap-1">
                        <svg class="w-3 h-3 fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Remove
                    </button>
                </div>
            </div>
            @endforeach

            {{-- Clear cart --}}
            <div class="flex justify-end pt-1">
                <button wire:click="clearCart"
                    wire:confirm="Clear your entire cart?"
                    class="text-[12px] text-[#bbb] dark:text-[#7a9e7c] hover:text-red-500 transition-colors cursor-pointer flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Clear entire cart
                </button>
            </div>
        </div>

        {{-- ─── ORDER SUMMARY ────────────────────────────────────────────────── --}}
        <div class="w-full lg:w-[320px] flex-shrink-0 sticky top-[96px]">
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">Order Summary</h2>
                </div>

                <div class="p-5 space-y-3">
                    {{-- Items summary --}}
                    @foreach ($cartItems as $item)
                    <div class="flex justify-between items-center text-[12px]">
                        <span class="text-brand-muted line-clamp-1 flex-1 pr-2">{{ $item['name'] }} <span class="text-[#ccc] dark:text-[#2a3a2a]">×{{ $item['quantity'] }}</span></span>
                        <span class="text-[#111] dark:text-[#e8f5e9] font-medium flex-shrink-0">₦{{ number_format($item['subtotal']) }}</span>
                    </div>
                    @endforeach

                    <div class="border-t border-brand-border dark:border-[#2a3a2a] pt-3 mt-1">
                        <div class="flex justify-between items-center text-[12px] mb-1.5">
                            <span class="text-brand-muted">Subtotal</span>
                            <span class="text-[#111] dark:text-[#e8f5e9] font-medium">₦{{ number_format($total) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-[12px]">
                            <span class="text-brand-muted">Delivery</span>
                            <span class="text-brand font-semibold">Calculated at checkout</span>
                        </div>
                    </div>

                    <div class="border-t-2 border-brand-border dark:border-[#2a3a2a] pt-3">
                        <div class="flex justify-between items-center">
                            <span class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">Total</span>
                            <span class="font-montserrat font-black text-[22px] text-brand">₦{{ number_format($total) }}</span>
                        </div>
                    </div>

                    <a href="{{ route('checkout') }}"
                       class="block w-full text-center bg-brand-orange hover:bg-[#e06610] text-white font-montserrat font-bold text-[14px] py-3.5 rounded-xl transition-all hover:-translate-y-px cursor-pointer">
                        Proceed to Checkout →
                    </a>

                    {{-- Trust badges --}}
                    <div class="flex flex-col gap-2 pt-2">
                        @foreach([
                            ['icon'=>'shield','text'=>'Secure checkout — SSL encrypted'],
                            ['icon'=>'clock','text'=>'Test before you pay on delivery'],
                        ] as $trust)
                        <div class="flex items-center gap-2">
                            @if ($trust['icon'] === 'shield')
                            <svg class="w-3.5 h-3.5 fill-none flex-shrink-0" style="stroke:#068B03;stroke-width:2" viewBox="0 0 24 24">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            </svg>
                            @else
                            <svg class="w-3.5 h-3.5 fill-none flex-shrink-0" style="stroke:#F97316;stroke-width:2" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                            </svg>
                            @endif
                            <span class="text-[11px] text-brand-muted">{{ $trust['text'] }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    @else

    {{-- ─── EMPTY CART ──────────────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] py-20 text-center">
        <div class="text-7xl mb-5">🛒</div>
        <h2 class="font-montserrat font-black text-[22px] text-brand-dark dark:text-[#e8f5e9] mb-2">Your cart is empty</h2>
        <p class="text-[14px] text-brand-muted mb-7 max-w-xs mx-auto">
            Looks like you haven't added anything yet. Browse our verified vendors and find your next gadget!
        </p>
        <a href="{{ route('home') }}"
           class="inline-flex items-center gap-2 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[14px] px-7 py-3.5 rounded-xl transition-all hover:-translate-y-px cursor-pointer">
            <svg class="w-4 h-4 fill-none" style="stroke:currentColor;stroke-width:2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            Browse Products
        </a>
    </div>

    @endif
</div>

</x-layouts.storefront>
</div>
