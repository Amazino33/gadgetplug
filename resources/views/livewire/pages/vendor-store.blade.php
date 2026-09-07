<?php

use App\Models\Product;
use App\Models\Vendor;
use App\Models\Wishlist;
use App\Services\CartService;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * A store's public page.
 *
 * Where the feed's store line lands: a customer who liked one product can see
 * the rest of what that shop sells. Deliberately narrow — this is a catalogue
 * filtered to one vendor, not a second storefront with its own rules.
 *
 * The grid markup here is close to the one on the catalogue rather than shared
 * with it. Extracting a common <x-product-card> is the right end state, but it
 * would edit the main storefront's markup to serve a new page, and that page
 * can prove itself first.
 */
new class extends Component {
    use WithPagination;

    public Vendor $vendor;

    public string $sort = 'latest';

    public ?string $cartError = null;

    public array $wishlistIds = [];

    /**
     * A store that has switched off online sales has no public page.
     *
     * 404 rather than an empty shop: the products are already hidden by
     * visibleOnline, so without this the page would render a real store name
     * above nothing, which reads as "this shop has sold out" instead of "this
     * shop does not sell here".
     */
    public function mount(Vendor $vendor): void
    {
        abort_unless($vendor->online_sales_enabled, 404);

        $this->vendor = $vendor;
    }

    public function boot(): void
    {
        if (auth()->check()) {
            $this->wishlistIds = Wishlist::where('user_id', auth()->id())
                ->pluck('product_id')
                ->toArray();
        }
    }

    public function toggleWishlist(int $productId): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login');

            return;
        }

        $exists = Wishlist::where('user_id', auth()->id())
            ->where('product_id', $productId)
            ->exists();

        if ($exists) {
            Wishlist::where('user_id', auth()->id())->where('product_id', $productId)->delete();
            $this->wishlistIds = array_values(array_diff($this->wishlistIds, [$productId]));
        } else {
            Wishlist::create(['user_id' => auth()->id(), 'product_id' => $productId]);
            $this->wishlistIds[] = $productId;
        }
    }

    /**
     * Re-read through the same visibility rules the grid uses.
     *
     * The id arrives from the browser, so trusting it would let anything be
     * added to a cart by id alone — including a product this store has hidden.
     */
    public function addToCart(int $productId): void
    {
        $product = $this->storeProducts()->whereKey($productId)->first();

        if (! $product) {
            $this->cartError = 'That product is no longer available.';

            return;
        }

        if (! app(CartService::class)->add($product)) {
            $this->cartError = "Sorry, \"{$product->name}\" is out of stock.";

            return;
        }

        $this->cartError = null;
        $this->dispatch('cart-updated');
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    /** One definition of "what this store sells", used by the grid and the guard. */
    private function storeProducts()
    {
        return Product::query()
            ->where('vendor_id', $this->vendor->id)
            ->visibleOnline()
            ->inStockForSale();
    }

    public function with(): array
    {
        $query = $this->storeProducts()->with(['category', 'media']);

        match ($this->sort) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            default      => $query->latest(),
        };

        return [
            'products' => $query->paginate(24),
        ];
    }
}; ?>

@php
    // Same rotating card tints the catalogue uses, so a product looks the same
    // here as it does on the home grid.
    $cardBgs = [
        'background: linear-gradient(135deg,#f0f7f0,#e3f0e3)',
        'background: linear-gradient(135deg,#f7f4ef,#efe9df)',
        'background: linear-gradient(135deg,#eef4f7,#dfe9f0)',
        'background: linear-gradient(135deg,#f7eff4,#f0dfe9)',
    ];
@endphp

{{-- The wrapping div is Livewire's single root element: the layout renders a
     whole document, and without this the component has several roots and
     refuses to render. Same shape as every other page component here. --}}
<div>
<x-layouts.storefront>

    {{-- Store header --}}
    <div class="bg-white dark:bg-[#1a2a1a] border-b border-brand-border dark:border-[#2a3a2a]">
        <div class="max-w-6xl mx-auto px-4 py-6 sm:py-8">
            <div class="flex items-start gap-4">
                <div class="h-16 w-16 sm:h-20 sm:w-20 shrink-0 overflow-hidden rounded-full bg-brand-bg ring-1 ring-brand-border">
                    @if ($vendor->logo_url)
                        <img src="{{ $vendor->logo_url }}" alt="{{ $vendor->name }}" class="h-full w-full object-cover">
                    @else
                        {{-- The normal case: nothing uploads a logo yet. --}}
                        <span class="flex h-full w-full items-center justify-center font-montserrat text-lg font-black text-brand">
                            {{ strtoupper(substr($vendor->name, 0, 2)) }}
                        </span>
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5">
                        <h1 class="truncate font-montserrat text-lg sm:text-2xl font-black text-[#111] dark:text-[#e8f5e9]">
                            {{ $vendor->name }}
                        </h1>
                        @if ($vendor->is_verified)
                            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-brand" title="Verified store">
                                <svg class="h-2.5 w-2.5 fill-none" style="stroke:#fff;stroke-width:3" viewBox="0 0 24 24">
                                    <polyline points="20 6 9 17 4 12" />
                                </svg>
                            </span>
                        @endif
                    </div>

                    {{-- Hidden entirely when unset, rather than printed empty. --}}
                    @if ($vendor->location)
                        <p class="mt-0.5 flex items-center gap-1 text-[13px] text-[#7a9e7c]">
                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                            </svg>
                            {{ $vendor->location }}
                        </p>
                    @endif

                    @if ($vendor->description)
                        <p class="mt-2 max-w-2xl text-[13px] leading-snug text-[#5a7a5c] dark:text-[#9ab89c]">
                            {{ $vendor->description }}
                        </p>
                    @endif

                    <p class="mt-2 text-[12px] font-semibold text-[#8a9e8c]">
                        {{ $products->total() }} {{ Str::plural('product', $products->total()) }} available
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 py-5">
        @if ($cartError)
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-[13px] text-red-700">
                {{ $cartError }}
            </div>
        @endif

        @if ($products->count())
            <div class="mb-4 flex items-center justify-between gap-3">
                <p class="text-[13px] text-[#7a9e7c]">Showing {{ $products->count() }} of {{ $products->total() }}</p>

                <select wire:model.live="sort"
                        aria-label="Sort products"
                        class="rounded-lg border border-brand-border bg-white dark:bg-[#1a2a1a] px-3 py-2 text-[12px] font-semibold text-[#111] dark:text-[#e8f5e9]">
                    <option value="latest">Newest first</option>
                    <option value="price_asc">Price: low to high</option>
                    <option value="price_desc">Price: high to low</option>
                </select>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
                @foreach ($products as $product)
                    @php
                        $bg = $cardBgs[$product->id % count($cardBgs)];
                        $thumbUrl = $product->getFirstMediaUrl('product-images', 'preview');
                        $categoryIcon = \App\Support\CategoryIcon::for($product->category?->name);
                        $aboveFold = $loop->index < 4;
                        $wishlisted = in_array($product->id, $wishlistIds);
                    @endphp

                    <div class="group bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden transition-all hover:-translate-y-[3px] hover:shadow-[0_8px_30px_rgba(6,139,3,0.1)]">
                        <div class="relative">
                            <a href="{{ route('product.show', $product) }}" class="block">
                                <div class="gp-card-img h-[140px] flex items-center justify-center relative" style="{{ $bg }}">
                                    @if ($thumbUrl)
                                        <img src="{{ $thumbUrl }}" alt="{{ $product->name }}"
                                             width="400" height="280"
                                             loading="{{ $aboveFold ? 'eager' : 'lazy' }}"
                                             decoding="async"
                                             class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
                                    @else
                                        <x-gp-icon :name="$categoryIcon" class="w-12 h-12 text-brand opacity-40" />
                                    @endif
                                </div>
                            </a>

                            <button wire:click="toggleWishlist({{ $product->id }})"
                                    aria-label="{{ $wishlisted ? 'Remove '.$product->name.' from wishlist' : 'Add '.$product->name.' to wishlist' }}"
                                    aria-pressed="{{ $wishlisted ? 'true' : 'false' }}"
                                    class="absolute top-0.5 right-0.5 w-11 h-11 rounded-full flex items-center justify-center transition-all duration-200 {{ $wishlisted ? 'text-red-500' : 'text-[#aaa] hover:text-red-400 opacity-70 group-hover:opacity-100 focus-visible:opacity-100' }}">
                                <span class="w-7 h-7 rounded-full bg-white dark:bg-[#1a2a1a] shadow-md flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5" fill="{{ $wishlisted ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
                                    </svg>
                                </span>
                            </button>
                        </div>

                        <div class="p-3">
                            <a href="{{ route('product.show', $product) }}"
                               class="block text-[12px] font-semibold text-[#111] dark:text-[#e8f5e9] leading-[1.35] mb-2 hover:text-brand transition-colors line-clamp-2">
                                {{ $product->name }}
                            </a>

                            @if ($product->brand)
                                <p class="text-[10px] text-[#8a9e8c] mb-1">{{ $product->brand }}</p>
                            @endif

                            <div class="flex items-baseline gap-1.5 mb-2">
                                <span class="font-montserrat font-black text-[15px] text-brand">₦{{ number_format($product->price) }}</span>
                            </div>

                            <button wire:click="addToCart({{ $product->id }})"
                                    aria-label="Add {{ $product->name }} to cart"
                                    class="w-full flex items-center justify-center gap-1 min-h-[44px] px-2 bg-brand hover:bg-[#055002] text-white border-0 rounded-lg text-[11px] font-semibold font-montserrat transition-colors">
                                <x-gp-icon name="cart" class="w-3 h-3 flex-shrink-0" />
                                Add to Cart
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $products->links() }}
            </div>
        @else
            {{-- A real state, not an error: the store exists and sells online,
                 but has nothing listed or everything is out of stock. --}}
            <div class="py-16 text-center">
                <p class="font-montserrat text-[15px] font-bold text-[#111] dark:text-[#e8f5e9]">Nothing in stock right now</p>
                <p class="mt-1 text-[13px] text-[#7a9e7c]">{{ $vendor->name }} has no products available at the moment.</p>
                <a href="{{ route('home') }}"
                   class="mt-4 inline-block rounded-full bg-brand px-6 py-2.5 font-montserrat text-[13px] font-black text-white">
                    Browse the marketplace
                </a>
            </div>
        @endif
    </div>
</x-layouts.storefront>
</div>
