<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use App\Models\Product;
use Illuminate\Support\Facades\Session;

new class extends Component {
    public int   $count = 0;
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
        $this->total     = 0;
        $this->count     = 0;

        if (empty($cart)) return;

        $productIds = array_keys($cart);
        $products   = Product::with('media')->whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($cart as $productId => $item) {
            $product = $products->get($productId);
            if (!$product) continue;

            $qty             = $item['quantity'];
            $this->count    += $qty;
            $this->total    += $product->price * $qty;
            $this->cartItems[] = [
                'id'       => $productId,
                'name'     => $product->name,
                'price'    => (float) $product->price,
                'quantity' => $qty,
                'subtotal' => (float) ($product->price * $qty),
                'thumb'    => $product->getFirstMediaUrl('product-images', 'thumb'),
            ];
        }
    }

    public function removeItem(int $productId): void
    {
        $cart = Session::get('cart', []);
        unset($cart[$productId]);
        Session::put('cart', $cart);
        $this->dispatch('cart-updated');
        $this->loadCart();
    }
}; ?>

<div x-data="{ open: false }" @click.outside="open = false" class="relative hidden md:block">

    {{-- Cart icon trigger --}}
    <button @click="open = !open"
        class="relative flex flex-col items-center gap-0.5 cursor-pointer text-[#444] dark:text-[#c0d8c0] hover:text-brand dark:hover:text-brand focus:outline-none transition-colors">
        <svg class="w-[22px] h-[22px] fill-none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
            <line x1="3" y1="6" x2="21" y2="6"/>
            <path d="M16 10a4 4 0 0 1-8 0"/>
        </svg>
        <span class="hidden md:block text-[10px]">Cart</span>
        @if ($count > 0)
        <span class="absolute -top-1 -right-1.5 bg-brand-lime text-brand-dark text-[9px] font-bold font-montserrat w-4 h-4 rounded-full flex items-center justify-center border border-white dark:border-[#1a2a1a]">
            {{ $count > 9 ? '9+' : $count }}
        </span>
        @endif
    </button>

    {{-- Dropdown panel --}}
    <div x-show="open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-2 scale-95"
         class="absolute right-0 top-[calc(100%+12px)] w-[340px] max-w-[calc(100vw-32px)] bg-white dark:bg-[#1a2a1a] rounded-2xl shadow-[0_20px_60px_rgba(0,0,0,0.15)] border border-brand-border dark:border-[#2a3a2a] z-[200] origin-top-right"
         style="display:none"
         wire:ignore.self>

        {{-- Panel header --}}
        <div class="flex items-center justify-between px-4 py-3.5 border-b border-brand-border dark:border-[#2a3a2a]">
            <span class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">
                My Cart
                @if ($count > 0)
                <span class="ml-1 bg-brand-lime text-brand-dark text-[10px] font-black px-1.5 py-0.5 rounded-full">{{ $count }}</span>
                @endif
            </span>
            <button @click="open = false" class="w-6 h-6 flex items-center justify-center rounded-full hover:bg-[#f0f8f0] dark:hover:bg-[#1f2f1f] text-[#aaa] dark:text-[#7a9e7c] hover:text-brand transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        @if (count($cartItems) > 0)

        {{-- Items list --}}
        <div class="divide-y divide-[#f0f4f1] dark:divide-[#2a3a2a] overflow-y-auto" style="max-height: 300px;">
            @foreach ($cartItems as $item)
            <div class="flex items-center gap-3 px-4 py-3">
                {{-- Thumbnail --}}
                <div class="w-12 h-12 rounded-xl bg-brand-bg dark:bg-[#0d1a0d] border border-brand-border dark:border-[#2a3a2a] flex items-center justify-center flex-shrink-0 overflow-hidden">
                    @if ($item['thumb'])
                        <img src="{{ $item['thumb'] }}" alt="{{ $item['name'] }}" class="w-full h-full object-cover">
                    @else
                        <span class="text-lg">📦</span>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 min-w-0">
                    <div class="text-[12px] font-semibold text-[#111] dark:text-[#e8f5e9] leading-tight line-clamp-1">{{ $item['name'] }}</div>
                    <div class="text-[11px] text-brand-muted mt-0.5">
                        {{ $item['quantity'] }} × ₦{{ number_format($item['price']) }}
                    </div>
                </div>

                {{-- Subtotal + remove --}}
                <div class="text-right flex-shrink-0">
                    <div class="font-montserrat font-bold text-[13px] text-brand">₦{{ number_format($item['subtotal']) }}</div>
                    <button wire:click="removeItem({{ $item['id'] }})"
                        class="text-[10px] text-[#bbb] dark:text-[#7a9e7c] hover:text-red-500 transition-colors mt-0.5 cursor-pointer">
                        Remove
                    </button>
                </div>
            </div>
            @endforeach
        </div>

        {{-- Footer --}}
        <div class="p-4 border-t border-brand-border dark:border-[#2a3a2a] bg-[#f8fcf8] dark:bg-[#162016] rounded-b-2xl">
            <div class="flex items-center justify-between mb-3">
                <span class="text-[12px] text-brand-muted font-medium">Subtotal ({{ $count }} item{{ $count !== 1 ? 's' : '' }})</span>
                <span class="font-montserrat font-black text-[17px] text-brand">₦{{ number_format($total) }}</span>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ route('cart') }}" @click="open = false"
                   class="block text-center bg-white dark:bg-[#1a2a1a] border-2 border-brand text-brand font-montserrat font-bold text-[12px] py-2 rounded-xl hover:bg-brand hover:text-white transition-colors cursor-pointer">
                    View Cart
                </a>
                <a href="{{ route('checkout') }}" @click="open = false"
                   class="block text-center bg-brand-orange text-white font-montserrat font-bold text-[12px] py-2 rounded-xl hover:bg-[#e06610] transition-colors cursor-pointer">
                    Checkout →
                </a>
            </div>
        </div>

        @else

        {{-- Empty state --}}
        <div class="px-6 py-10 text-center">
            <div class="text-5xl mb-3">🛒</div>
            <h4 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9] mb-1">Your cart is empty</h4>
            <p class="text-[12px] text-brand-muted mb-4 leading-relaxed">Add some gadgets and they'll appear here</p>
            <a href="{{ route('home') }}" @click="open = false"
               class="inline-block bg-brand text-white font-montserrat font-bold text-[12px] px-5 py-2 rounded-xl hover:bg-[#055002] transition-colors cursor-pointer">
                Browse Products
            </a>
        </div>

        @endif
    </div>
</div>
