<?php

use Illuminate\Support\Str;
use Livewire\Volt\Component;
use App\Models\Product;
use App\Models\Wishlist;
use App\Services\CartService;
use App\Services\Meta\MetaConversionsService;

new class extends Component {
    public Product $product;
    public int $quantity = 1;
    public bool $wishlisted = false;
    public ?string $cartError = null;

    public function mount(Product $product): void
    {
        if (! Product::visibleOnline()->where('id', $product->id)->exists()) {
            abort(404);
        }

        $this->product = $product->load(['vendor', 'category', 'media']);
        if (auth()->check()) {
            $this->wishlisted = Wishlist::where('user_id', auth()->id())
                ->where('product_id', $product->id)
                ->exists();
        }

        $this->fireViewContent();
    }

    /**
     * @return array{email: ?string, phone: ?string, name: ?string, fbp: ?string, fbc: ?string, client_ip: ?string, user_agent: ?string}
     */
    private function currentUserData(): array
    {
        $user = auth()->user();

        return [
            'email'      => $user?->email,
            'phone'      => $user?->phone,
            'name'       => $user?->name,
            'fbp'        => request()->cookie('_fbp'),
            'fbc'        => request()->cookie('_fbc'),
            'client_ip'  => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];
    }

    private function fireViewContent(): void
    {
        app(MetaConversionsService::class)->dispatchEvent(
            eventName: 'ViewContent',
            eventId: (string) Str::uuid(),
            eventSourceUrl: url()->current(),
            userData: $this->currentUserData(),
            customData: [
                'currency'     => 'NGN',
                'value'        => (float) $this->product->price,
                'content_ids'  => [$this->product->id],
                'content_type' => 'product',
            ],
        );
    }

    public function toggleWishlist(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login');
            return;
        }

        if ($this->wishlisted) {
            Wishlist::where('user_id', auth()->id())->where('product_id', $this->product->id)->delete();
            $this->wishlisted = false;
        } else {
            Wishlist::create(['user_id' => auth()->id(), 'product_id' => $this->product->id]);
            $this->wishlisted = true;
        }
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
        if (! app(CartService::class)->add($this->product, $this->quantity)) {
            $this->cartError = 'Sorry, this product is out of stock.';
            return;
        }

        $this->cartError = null;
        $this->dispatch('cart-updated');
        $this->fireAddToCart();
    }

    public function buyNow(): void
    {
        if (! app(CartService::class)->add($this->product, $this->quantity)) {
            $this->cartError = 'Sorry, this product is out of stock.';
            return;
        }

        $this->cartError = null;
        $this->dispatch('cart-updated');
        $this->fireAddToCart();
        $this->redirectRoute('checkout');
    }

    // Server CAPI copy dispatched right here (this method runs inside a real
    // request, so cookies/IP/UA are available); browser copy is a separate
    // dispatched event picked up by an Alpine listener in the storefront
    // layout — reusing 'cart-updated' would over-fire (it also covers
    // decrement/remove/clear in cart.blade.php), so this gets its own event.
    private function fireAddToCart(): void
    {
        $eventId = (string) Str::uuid();

        app(MetaConversionsService::class)->dispatchEvent(
            eventName: 'AddToCart',
            eventId: $eventId,
            eventSourceUrl: url()->current(),
            userData: $this->currentUserData(),
            customData: [
                'currency'     => 'NGN',
                'value'        => (float) $this->product->price * $this->quantity,
                'content_ids'  => [$this->product->id],
                'content_type' => 'product',
            ],
        );

        $this->dispatch('pixel-add-to-cart', eventId: $eventId, value: (float) $this->product->price * $this->quantity);
    }
}; ?>

@php
$allImages  = $product->getMedia('product-images');
$firstImage = $allImages->first();
// The 'preview' conversion (800x800, already generated on upload) — not the
// raw original — since this is the largest-contentful-paint image on the page.
$defaultUrl = $firstImage ? $firstImage->getUrl('preview') : '';

$categoryIcon = \App\Support\CategoryIcon::for($product->category?->name);
@endphp

<div>
@php
    $ogTitle       = trim(($product->brand ? $product->brand . ' ' : '') . $product->name) . ' — GadgetPlug';
    $ogDescription = $product->description
        ? Str::limit(strip_tags($product->description), 155)
        : 'Buy ' . $product->name . ' at the best price on GadgetPlug Nigeria. Verified vendor, fast delivery.';
    $ogImage       = $product->getFirstMediaUrl('product-images', 'preview') ?: asset('images/logo.svg');
    $ogUrl         = route('product.show', $product->slug);
@endphp
<x-layouts.storefront
    :title="$ogTitle"
    :description="$ogDescription"
    :image="$ogImage"
    :url="$ogUrl"
>

@if($cartError)
    <div class="fixed top-20 left-1/2 -translate-x-1/2 z-50 bg-red-600 text-white text-sm font-medium px-4 py-2.5 rounded-lg shadow-lg"
        x-data x-init="setTimeout(() => $wire.set('cartError', null), 4000)">
        {{ $cartError }}
    </div>
@endif

<div class="px-4 md:px-6 py-6 pb-52 md:pb-6 bg-[#f8fcf8] dark:bg-[#0d1a0d] min-h-screen">

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

            {{-- Main image — 4:3 on mobile to keep title above fold; square on desktop --}}
            <div class="aspect-[4/3] lg:aspect-square w-full bg-brand-bg dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden relative">
                @if ($firstImage)
                    <img :src="current" alt="{{ $product->name }}" fetchpriority="high"
                        class="w-full h-full object-cover transition-all duration-300">
                @else
                    <div class="w-full h-full flex flex-col items-center justify-center gap-3 bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                        <x-gp-icon :name="$categoryIcon" class="w-24 h-24 text-brand opacity-40" />
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
                @php $previewUrl = $image->getUrl('preview'); @endphp
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
                @if ($product->stock_quantity <= 0)
                <span class="flex items-center gap-1 bg-[#fce4ec] text-red-600 text-[11px] font-bold font-montserrat px-2.5 py-1 rounded-full">
                    <span class="w-1.5 h-1.5 rounded-full bg-red-500 inline-block"></span>
                    Out of Stock
                </span>
                @endif
            </div>

            {{-- Trust mini-badges — directly under price, above the fold on
            mobile. "Test before you pay" is the scam-killer; it must be
            visible without scrolling. --}}
            <div class="flex flex-col gap-2 p-4 mb-5 bg-[#f8fcf8] dark:bg-[#162016] rounded-xl border border-[#e0eee0] dark:border-[#2a3a2a]">
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
                </div>
            </div>
            @endif

            {{-- Buy Now (dominant) + Add to Cart (secondary) --}}
            <div class="hidden md:flex flex-col gap-2.5 mb-6">
                <button wire:click="buyNow"
                    class="w-full flex items-center justify-center gap-2 bg-brand-orange hover:bg-[#e06610] text-white font-montserrat font-bold text-[15px] py-4 rounded-xl border-0 cursor-pointer transition-all hover:-translate-y-px shadow-lg disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:translate-y-0"
                    @disabled($product->stock_quantity < 1)>
                    <svg class="w-5 h-5 fill-none flex-shrink-0" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                    Buy Now
                </button>
                <div class="flex gap-2.5">
                    <button wire:click="addToCart"
                        class="flex-1 flex items-center justify-center gap-2 bg-white dark:bg-[#1a2a1a] border-2 border-brand text-brand hover:bg-brand hover:text-white font-montserrat font-bold text-[13px] py-3 rounded-xl cursor-pointer transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        @disabled($product->stock_quantity < 1)>
                        <svg class="w-4 h-4 fill-none flex-shrink-0" style="stroke:currentColor;stroke-width:2.5" viewBox="0 0 24 24">
                            <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <path d="M16 10a4 4 0 0 1-8 0"/>
                        </svg>
                        Add to Cart
                    </button>
                    <button wire:click="toggleWishlist"
                        class="w-12 h-[46px] rounded-xl flex items-center justify-center transition-colors cursor-pointer flex-shrink-0 border
                            {{ $wishlisted
                                ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-500'
                                : 'bg-brand-bg dark:bg-[#1a2a1a] border-brand-border dark:border-[#2a3a2a] text-[#5a7a5c] hover:border-red-300 hover:text-red-400' }}"
                        title="{{ $wishlisted ? 'Remove from wishlist' : 'Add to wishlist' }}">
                        <svg class="w-5 h-5" fill="{{ $wishlisted ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                    </button>
                </div>
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

    {{-- ─── MOBILE STICKY CTA BAR ──────────────────────────────────────────── --}}
    {{-- Buy Now dominant (full width, top); Add to Cart + wishlist secondary
    (smaller, outlined) below it — same hierarchy as the desktop buttons. --}}
    <div class="fixed left-0 right-0 md:hidden bg-white dark:bg-[#1a2a1a] border-t border-brand-border dark:border-[#2a3a2a] px-4 py-3 flex flex-col gap-2"
         style="bottom: 3rem; z-index: 50;">
        <button wire:click="buyNow"
            class="w-full flex items-center justify-center gap-2 bg-brand-orange hover:bg-[#e06610] text-white font-montserrat font-bold text-[14px] py-3.5 rounded-xl transition-all shadow-md disabled:opacity-50 disabled:cursor-not-allowed"
            @disabled($product->stock_quantity < 1)>
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
            Buy Now
        </button>
        <div class="flex gap-2.5">
            <button wire:click="addToCart"
                class="flex-1 flex items-center justify-center gap-2 bg-white dark:bg-[#1a2a1a] border-2 border-brand text-brand font-montserrat font-bold text-[12px] py-2.5 rounded-xl transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                @disabled($product->stock_quantity < 1)>
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <path d="M16 10a4 4 0 0 1-8 0"/>
                </svg>
                Add to Cart
            </button>
            <button wire:click="toggleWishlist"
                class="w-11 h-[42px] rounded-xl flex items-center justify-center flex-shrink-0 border transition-colors
                    {{ $wishlisted
                        ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-500'
                        : 'bg-brand-bg dark:bg-[#0d1a0d] border-brand-border dark:border-[#2a3a2a] text-[#5a7a5c]' }}"
                title="{{ $wishlisted ? 'Remove from wishlist' : 'Add to wishlist' }}">
                <svg class="w-5 h-5" fill="{{ $wishlisted ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                </svg>
            </button>
        </div>
    </div>

</div>

</x-layouts.storefront>
</div>
