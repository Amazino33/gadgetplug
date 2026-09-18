<?php

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use App\Models\Product;
use App\Models\Wishlist;
use App\Services\CartService;
use App\Services\Meta\MetaConversionsService;

new class extends Component {
    /**
     * How many related products each rail shows at most.
     *
     * Ten is two full desktop rows' worth and about four flicks on a phone —
     * past that nobody scrolls, and every extra card is another image on a
     * connection that is usually mobile data.
     */
    private const RELATED_LIMIT = 10;

    public Product $product;
    public int $quantity = 1;
    public bool $wishlisted = false;
    public ?string $cartError = null;

    /**
     * InitiateCheckout fires when the payment screen opens, which a shopper can
     * do more than once by dismissing it and tapping Buy Now again. Meta counts
     * every send, so without this the funnel would report more checkouts
     * started than there were shoppers.
     */
    public bool $initiateCheckoutFired = false;

    /**
     * The event_id the server-side ViewContent was sent with, so the browser
     * copy rendered below can quote the same one. Until now ViewContent was
     * dispatched to the Conversions API with no browser counterpart at all —
     * the only event in the funnel with nothing to deduplicate against, and
     * therefore the only one an ad blocker could not cost us but a Meta-side
     * outage could.
     */
    public ?string $viewContentEventId = null;

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
        $this->viewContentEventId = (string) Str::uuid();

        app(MetaConversionsService::class)->dispatchEvent(
            eventName: 'ViewContent',
            eventId: $this->viewContentEventId,
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

    /**
     * Other things in this category from the same shop.
     *
     * Ordered so the first card is the obvious step up rather than whatever is
     * cheapest: anything dearer than the product being viewed comes first,
     * nearest price first within each group. On a shelf of power banks that
     * puts the 30,000mAh directly beside the 20,000mAh being read about, then
     * the 40,000mAh, and only then the smaller ones as peers.
     *
     * Sorted in SQL rather than PHP because the limit has to be applied by the
     * database — sorting after the fact would mean fetching the vendor's whole
     * category in order to throw most of it away.
     */
    #[Computed]
    public function relatedFromVendor(): Collection
    {
        return $this->relatedQuery()
            ->where('vendor_id', $this->product->vendor_id)
            ->limit(self::RELATED_LIMIT)
            ->get();
    }

    /**
     * The same category across the rest of the marketplace.
     *
     * Its own rail under its own heading, never blended into the vendor's — a
     * shopper reading one shop's product page should never be left unclear
     * about whose goods they are looking at. Every card here names its vendor.
     */
    #[Computed]
    public function relatedElsewhere(): Collection
    {
        return $this->relatedQuery()
            ->where('vendor_id', '!=', $this->product->vendor_id)
            ->limit(self::RELATED_LIMIT)
            ->get();
    }

    /**
     * What both rails have in common: same category, buyable right now, and not
     * the product already on screen.
     *
     * inStockForSale() rather than a stock column comparison because a resold
     * listing holds no stock of its own — its availability lives on the
     * supplier's row and only that scope knows how to reach it.
     */
    private function relatedQuery(): Builder
    {
        $price = (float) $this->product->price;

        return Product::query()
            ->visibleOnline()
            ->inStockForSale()
            ->with(['vendor', 'category', 'media'])
            ->where('category_id', $this->product->category_id)
            ->whereKeyNot($this->product->id)
            ->orderByRaw('CASE WHEN price > ? THEN 0 ELSE 1 END', [$price])
            ->orderByRaw('ABS(price - ?)', [$price]);
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
        if ($this->quantity < $this->product->available_stock) {
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

    /**
     * Buy Now was tapped and the payment screen is already on screen.
     *
     * The screen itself is opened by Alpine against markup that is already in
     * the DOM, so it appears on the tap with no request in between. This runs
     * afterwards, purely to record that checkout started — nothing the shopper
     * is looking at waits for it.
     */
    public function openPaymentChoice(): void
    {
        if ($this->initiateCheckoutFired) {
            return;
        }

        $this->initiateCheckoutFired = true;

        $eventId = (string) Str::uuid();
        $value   = (float) $this->product->price * $this->quantity;

        app(MetaConversionsService::class)->dispatchEvent(
            eventName: 'InitiateCheckout',
            eventId: $eventId,
            eventSourceUrl: url()->current(),
            userData: $this->currentUserData(),
            customData: [
                'currency'     => 'NGN',
                'value'        => $value,
                'content_ids'  => [$this->product->id],
                'content_type' => 'product',
            ],
        );

        $this->dispatch('pixel-initiate-checkout', eventId: $eventId, value: $value);
    }

    /**
     * A payment method was chosen on that screen.
     *
     * The choice travels to checkout in the session rather than the URL: it
     * decides what the customer is asked for next — email is required to pay
     * online and optional otherwise — so it is not something a link handed to
     * someone should be able to set.
     */
    public function buyNow(string $method): void
    {
        if (! in_array($method, ['paystack', 'pay_on_delivery'], true)) {
            return;
        }

        if (! app(CartService::class)->add($this->product, $this->quantity)) {
            $this->cartError = 'Sorry, this product is out of stock.';
            // Nothing can be bought, so the payment screen has nothing left to
            // ask — close it and let the error banner be what they see.
            $this->dispatch('payment-choice-close');
            return;
        }

        $this->cartError = null;
        $this->dispatch('cart-updated');
        $this->fireAddToCart();
        $this->fireAddPaymentInfo($method);

        session()->put('checkout_payment_method', $method);

        $this->redirectRoute('checkout');
    }

    /**
     * AddPaymentInfo — the step Meta was missing entirely.
     *
     * Browser and server copies share one event_id so Meta folds them into a
     * single event. The server copy is the one that survives an ad blocker,
     * which on this audience is a large minority of shoppers.
     */
    private function fireAddPaymentInfo(string $method): void
    {
        $eventId = (string) Str::uuid();
        $value   = (float) $this->product->price * $this->quantity;

        app(MetaConversionsService::class)->dispatchEvent(
            eventName: 'AddPaymentInfo',
            eventId: $eventId,
            eventSourceUrl: url()->current(),
            userData: $this->currentUserData(),
            customData: [
                'currency'       => 'NGN',
                'value'          => $value,
                'content_ids'    => [$this->product->id],
                'content_type'   => 'product',
                'payment_method' => $method,
            ],
        );

        $this->dispatch('pixel-add-payment-info', eventId: $eventId, value: $value);
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

{{-- Browser half of ViewContent, quoting the event_id its Conversions API
copy was sent with in mount() so Meta folds the two into one event. --}}
@if (config('services.meta.pixel_id') && $viewContentEventId)
<script>
fbq('track', 'ViewContent', {
    value: {{ (float) $product->price }},
    currency: 'NGN',
    content_ids: @json([$product->id]),
    content_type: 'product'
}, {eventID: '{{ $viewContentEventId }}'});
</script>
@endif

@if($cartError)
    <div class="fixed top-20 left-1/2 -translate-x-1/2 z-50 bg-red-600 text-white text-sm font-medium px-4 py-2.5 rounded-lg shadow-lg"
        x-data x-init="setTimeout(() => $wire.set('cartError', null), 4000)">
        {{ $cartError }}
    </div>
@endif

<div class="px-4 md:px-6 py-6 pb-52 md:pb-6 bg-[#f8fcf8] dark:bg-[#0d1a0d] min-h-screen"
     x-data="{
        payOpen: false,
        open() {
            this.payOpen = true
            // Nothing is fetched here — the panel is already rendered below.
            // This only stops the page behind it scrolling under the overlay,
            // which on a phone is what makes an overlay feel like a screen
            // rather than a box sitting on top of one.
            document.body.style.overflow = 'hidden'
        },
        close() {
            this.payOpen = false
            document.body.style.overflow = ''
        },
     }"
     x-on:payment-choice-close.window="close()"
     x-on:keydown.escape.window="payOpen && close()">

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
                @if ($product->available_stock <= 0)
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
            @if ($product->available_stock > 0)
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
                        @disabled($quantity >= $product->available_stock)>
                        +
                    </button>
                </div>
            </div>
            @endif

            {{-- Buy Now (dominant) + Add to Cart (secondary) --}}
            <div class="hidden md:flex flex-col gap-2.5 mb-6">
                {{-- @click opens the panel on the tap itself; wire:click only
                     records that checkout started. The shopper never waits on
                     the round trip, which is the entire point. --}}
                <button type="button" @click="open()" wire:click="openPaymentChoice"
                    class="w-full flex items-center justify-center gap-2 bg-brand-orange hover:bg-[#e06610] text-white font-montserrat font-bold text-[15px] py-4 rounded-xl border-0 cursor-pointer transition-all hover:-translate-y-px shadow-lg disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:translate-y-0"
                    @disabled($product->available_stock < 1)>
                    <svg class="w-5 h-5 fill-none flex-shrink-0" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                    Buy Now
                </button>
                <div class="flex gap-2.5">
                    <button wire:click="addToCart"
                        class="flex-1 flex items-center justify-center gap-2 bg-white dark:bg-[#1a2a1a] border-2 border-brand text-brand hover:bg-brand hover:text-white font-montserrat font-bold text-[13px] py-3 rounded-xl cursor-pointer transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        @disabled($product->available_stock < 1)>
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

    {{-- ─── RELATED PRODUCTS ────────────────────────────────────────────────── --}}
    {{-- The page used to end at the specifications: a shopper who was not sold
    on this exact unit had nowhere to go but back.

    The category comes first. Someone reading about a smartwatch is in the
    market for a smartwatch, and the next thing they want is the other ones —
    which shop each belongs to is a detail they can weigh after. What else this
    shop sells sits under it: still worth offering, but it answers a question
    they have not asked yet. The two are never blended, and every card on the
    marketplace rail names its vendor. --}}
    @php
        // "More Power Banks" reads like a shop; "More products in Power Banks"
        // reads like a database. Falls back when a product has no category.
        $categoryLabel = $product->category?->name;
        $vendorRail    = $this->relatedFromVendor;
        $elsewhereRail = $this->relatedElsewhere;
    @endphp

    @if ($elsewhereRail->isNotEmpty())
    <section class="mt-10" aria-labelledby="rail-elsewhere-heading">
        <div class="flex items-baseline justify-between gap-3 mb-1">
            <h2 id="rail-elsewhere-heading" class="font-montserrat font-black text-[18px] md:text-[20px] text-brand-dark dark:text-[#e8f5e9]">
                {{ $categoryLabel ? 'More '.Str::plural($categoryLabel).' on GadgetPlug' : 'Also on GadgetPlug' }}
            </h2>
        </div>
        <p class="mb-4 text-[12px] text-brand-muted">From other verified shops on the marketplace.</p>

        {{-- Horizontal rail on mobile, grid on desktop. Same cards either way —
             only the container changes, so there is one set of markup. --}}
        <div class="gp-rail scrollbar-none -mx-4 flex gap-3 overflow-x-auto px-4 pb-2
                    md:mx-0 md:grid md:grid-cols-3 md:overflow-visible md:px-0 lg:grid-cols-4">
            @foreach ($elsewhereRail as $related)
                {{-- Eager on the first two: this rail is now the one that lands
                     just under the fold, so its opening cards are what a
                     scrolling shopper waits on. --}}
                <x-product-card
                    :product="$related"
                    :show-vendor="true"
                    :eager="$loop->index < 2"
                    width="150px"
                    class="shrink-0 md:w-auto" />
            @endforeach
        </div>
    </section>
    @endif

    @if ($vendorRail->isNotEmpty())
    <section class="mt-10" aria-labelledby="rail-vendor-heading">
        <div class="flex items-baseline justify-between gap-3 mb-4">
            <h2 id="rail-vendor-heading" class="font-montserrat font-black text-[18px] md:text-[20px] text-brand-dark dark:text-[#e8f5e9]">
                More from {{ $product->vendor->name ?? 'this shop' }}
            </h2>
            @if ($product->vendor)
            <a href="{{ route('store.show', $product->vendor) }}"
               class="shrink-0 text-[12px] font-semibold text-brand hover:underline">
                See all
            </a>
            @endif
        </div>

        <div class="gp-rail scrollbar-none -mx-4 flex gap-3 overflow-x-auto px-4 pb-2
                    md:mx-0 md:grid md:grid-cols-3 md:overflow-visible md:px-0 lg:grid-cols-4">
            @foreach ($vendorRail as $related)
                <x-product-card
                    :product="$related"
                    width="150px"
                    class="shrink-0 md:w-auto" />
            @endforeach
        </div>
    </section>
    @endif

    {{-- ─── STEP A: PAYMENT CHOICE ──────────────────────────────────────────── --}}
    {{-- Rendered with the page, hidden until Buy Now. Nothing is fetched when
    it opens, which is why it appears on the tap instead of after a spinner.
    x-cloak keeps it off screen for the frame before Alpine boots. --}}
    <div x-show="payOpen"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[200] overflow-y-auto"
         role="dialog"
         aria-modal="true"
         aria-label="Choose how to pay">
        <div x-show="payOpen"
             x-transition:enter="transition ease-out duration-300 delay-75"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="min-h-full">
            <x-checkout.payment-choice action="buyNow" :total="$product->price * $quantity">
                <x-slot:dismiss>
                    <button type="button" @click="close()"
                        aria-label="Close"
                        class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20">
                        <svg class="h-5 w-5 fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </x-slot:dismiss>
            </x-checkout.payment-choice>
        </div>
    </div>

    {{-- ─── MOBILE STICKY CTA BAR ──────────────────────────────────────────── --}}
    {{-- Buy Now dominant (full width, top); Add to Cart + wishlist secondary
    (smaller, outlined) below it — same hierarchy as the desktop buttons. --}}
    <div class="fixed left-0 right-0 md:hidden bg-white dark:bg-[#1a2a1a] border-t border-brand-border dark:border-[#2a3a2a] px-4 py-3 flex flex-col gap-2"
         style="bottom: 3rem; z-index: 50;">
        <button type="button" @click="open()" wire:click="openPaymentChoice"
            class="w-full flex items-center justify-center gap-2 bg-brand-orange hover:bg-[#e06610] text-white font-montserrat font-bold text-[14px] py-3.5 rounded-xl transition-all shadow-md disabled:opacity-50 disabled:cursor-not-allowed"
            @disabled($product->available_stock < 1)>
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
            Buy Now
        </button>
        <div class="flex gap-2.5">
            <button wire:click="addToCart"
                class="flex-1 flex items-center justify-center gap-2 bg-white dark:bg-[#1a2a1a] border-2 border-brand text-brand font-montserrat font-bold text-[12px] py-2.5 rounded-xl transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                @disabled($product->available_stock < 1)>
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
