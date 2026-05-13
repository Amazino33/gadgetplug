<?php

use App\Models\Product;
use App\Models\Category;
use App\Models\Wishlist;
use App\Services\CartService;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Session;
use function Livewire\Volt\{state};

new class extends Component {
    use WithPagination;

    public ?int $selectedCategory = null;
    public string $search = '';
    public string $sort = 'latest';

    public function mount(): void
    {
        $this->search = request('search', '');

        if ($slug = request('category')) {
            $category = Category::where('slug', $slug)->first();
            $this->selectedCategory = $category?->id;
        }
    }

    public array $wishlistIds = [];

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

    public function with(): array
    {
        $query = Product::with(['vendor', 'category', 'media'])
            ->where('stock_quantity', '>', 0)
            ->when($this->selectedCategory, fn($q) => $q->where('category_id', $this->selectedCategory))
            ->when($this->search, fn($q) => $q->where(function ($sq) {
                $sq->where('name', 'like', "%{$this->search}%")
                   ->orWhere('brand', 'like', "%{$this->search}%");
            }));

        match ($this->sort) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            default      => $query->latest(),
        };

        return [
            'products'   => $query->paginate(9),
            'categories' => Category::withCount('products')->orderByDesc('products_count')->get(),
        ];
    }

    public function filterCategory(?int $categoryId): void
    {
        $this->selectedCategory = $categoryId;
        $this->resetPage();
    }

    public function addToCart(int $productId): void
    {
        $product = Product::find($productId);
        if (!$product) return;
        app(CartService::class)->add($product);
        $this->dispatch('cart-updated');
    }

    public function buyNow(int $productId): void
    {
        $product = Product::find($productId);
        if (!$product) return;
        app(CartService::class)->add($product);
        $this->dispatch('cart-updated');
        $this->redirectRoute('checkout');
    }
}

?>

@php
$categoryEmojis = [
    'phones' => '📱', 'mobile' => '📱', 'smartphones' => '📱',
    'laptops' => '💻', 'computers' => '💻', 'pcs' => '💻',
    'audio' => '🎧', 'headphones' => '🎧', 'speakers' => '🎧',
    'wearables' => '⌚', 'watches' => '⌚', 'smartwatches' => '⌚',
    'gaming' => '🎮', 'games' => '🎮', 'consoles' => '🎮',
    'cameras' => '📷', 'photography' => '📷',
    'accessories' => '🔌', 'cables' => '🔌',
    'smart home' => '🏠', 'home' => '🏠',
    'refurbished' => '♻️', 'used' => '♻️',
    'tablets' => '📟', 'ipads' => '📟',
];

$cardBgs = [
    'background: linear-gradient(135deg, #e8f0ff, #d0e4ff)',
    'background: linear-gradient(135deg, #fff3e0, #ffe0b2)',
    'background: linear-gradient(135deg, #e8f5e9, #c8e6c9)',
    'background: linear-gradient(135deg, #fce4ec, #f8bbd0)',
    'background: linear-gradient(135deg, #e8eaf6, #c5cae9)',
    'background: linear-gradient(135deg, #f3e5f5, #e1bee7)',
];
@endphp

<div>
<x-layouts.storefront>

{{-- ─── HERO (home page only) ──────────────────────────────────────────────── --}}
@if($selectedCategory === null && $search === '')
<section class="relative overflow-hidden px-4 md:px-6 py-10 md:py-10 flex flex-col md:flex-row items-center gap-8 md:gap-8 bg-gradient-to-br from-[#f0f8f0] via-[#e8f5e9] to-white dark:from-[#0d1a0d] dark:via-[#0f1f0f] dark:to-[#162016]"
    style="min-height: 340px;">

    <div class="gp-hero-glow-1"></div>
    <div class="gp-hero-glow-2"></div>

    {{-- Hero text --}}
    <div class="flex-1 z-10 text-center md:text-left">
        <div class="inline-flex items-center gap-1.5 bg-brand-lime text-brand-dark text-[10px] font-bold font-montserrat px-2.5 py-1 rounded-full mb-3.5 uppercase tracking-[0.8px]">
            <svg class="w-[10px] h-[10px] fill-brand-dark" viewBox="0 0 24 24"><path d="M13 2L4 14h8l-1 8 9-12h-8z"/></svg>
            Nigeria's #1 Tech Marketplace
        </div>

        <h1 class="font-montserrat font-black text-[32px] md:text-[38px] leading-[1.1] text-brand-dark dark:text-[#e8f5e9] mb-2.5 tracking-[-1.5px]">
            Premium Retail,<br>
            <em class="text-brand not-italic">Localized.</em>
        </h1>

        <p class="text-[14px] text-[#4a6b4c] dark:text-[#b0c8b0] leading-relaxed max-w-sm mx-auto md:mx-0 mb-5">
            Authentic gadgets from verified Nigerian vendors. Test before you pay, delivered to your door in 2 hours.
        </p>

        <div class="flex items-center gap-3 justify-center md:justify-start">
            <button class="bg-brand-orange hover:bg-[#e06610] text-white font-montserrat font-bold text-[13px] px-5 py-2.5 rounded-[10px] border-0 cursor-pointer transition-all hover:-translate-y-px">
                Shop New Arrivals →
            </button>
            <button class="bg-transparent text-brand font-montserrat font-bold text-[13px] px-5 py-[9px] rounded-[10px] border-2 border-brand cursor-pointer hover:bg-brand hover:text-white transition-colors">
                Browse Verified Plugs
            </button>
        </div>

        {{-- Stats --}}
        <div class="flex gap-6 mt-5 pt-4 border-t border-[#d8eeda] dark:border-[#2a3a2a] justify-center md:justify-start">
            @foreach([['4,200+','Products'],['850+','Verified Vendors'],['2hr','Avg. Dispatch'],['98%','Satisfaction']] as [$num,$lbl])
            <div class="text-center">
                <div class="font-montserrat font-black text-[20px] text-brand">{{ $num }}</div>
                <div class="text-[10px] text-[#7a9e7c] font-medium mt-0.5">{{ $lbl }}</div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Hero phone visual (desktop only) --}}
    <div class="hidden md:flex flex-shrink-0 items-center justify-center w-[380px] h-[280px] relative z-10">
        <div class="relative w-[160px] h-[280px]">
            <div class="gp-phone-body">
                <div class="gp-phone-screen">
                    <div class="gp-phone-notch"></div>
                    <span class="font-montserrat font-black text-[14px] text-brand-lime">GadgetPlug</span>
                    <svg viewBox="0 0 80 80" class="w-[70px] h-[70px]">
                        <rect x="20" y="8" width="40" height="64" rx="8" fill="#1e3a5c"/>
                        <rect x="24" y="14" width="32" height="50" rx="4" fill="#0d1f2d"/>
                        <rect x="34" y="8" width="12" height="4" rx="2" fill="#2a4a6a"/>
                        <circle cx="40" cy="60" r="3" fill="#2a4a6a"/>
                        <rect x="28" y="20" width="24" height="16" rx="2" fill="#1e4a7a"/>
                        <circle cx="40" cy="44" r="8" fill="#0d2a4a"/>
                        <circle cx="40" cy="44" r="5" fill="#1a3a6a"/>
                        <circle cx="40" cy="44" r="2" fill="#2a5a8a"/>
                    </svg>
                    <span class="font-montserrat font-bold text-[9px] text-brand-lime">iPhone 16 Pro Max</span>
                    <span class="text-[8px] text-[rgba(255,255,255,0.5)]">From ₦1,450,000</span>
                </div>
            </div>

            {{-- Floating bubble 1 --}}
            <div class="animate-float absolute -right-2 top-5 bg-white dark:bg-[#1a2a1a] rounded-xl px-3 py-2 shadow-lg flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-brand-lime flex-shrink-0"></div>
                <div>
                    <div class="text-[10px] font-semibold text-[#111] dark:text-[#e8f5e9]">Just Verified ✓</div>
                    <div class="text-[9px] text-[#888] dark:text-[#7a9e7c]">Konga Tech Hub, Lagos</div>
                </div>
            </div>

            {{-- Floating bubble 2 --}}
            <div class="animate-float-delay absolute -right-5 bottom-10 bg-white dark:bg-[#1a2a1a] rounded-xl px-3 py-2 shadow-lg flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-brand-orange flex-shrink-0"></div>
                <div>
                    <div class="text-[10px] font-semibold text-[#111] dark:text-[#e8f5e9]">2hr Dispatch</div>
                    <div class="text-[9px] text-[#888] dark:text-[#7a9e7c]">Uyo & 22 cities</div>
                </div>
            </div>
        </div>
    </div>
</section>
@endif

{{-- ─── FLASH STRIP ────────────────────────────────────────────────────────── --}}
<div class="bg-brand-orange px-4 md:px-6 py-2 flex items-center justify-between">
    <span class="font-montserrat font-black text-[11px] text-white">⚡ FLASH SALE — Up to 40% OFF Today Only!</span>
    <a href="#" class="font-montserrat font-bold text-[10px] text-white underline cursor-pointer">Grab Deals</a>
</div>

{{-- ─── MAIN BODY: SIDEBAR + PRODUCTS ─────────────────────────────────────── --}}
<div class="flex gap-5 px-4 md:px-6 py-7 items-start bg-[#f8fcf8] dark:bg-[#0d1a0d]">

    {{-- Category sidebar (desktop only) --}}
    <aside class="hidden lg:block w-[200px] flex-shrink-0 bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden sticky top-[92px]">
        <div class="bg-brand px-4 py-3.5">
            <p class="font-montserrat font-bold text-[12px] text-white tracking-[0.5px] uppercase">Browse Categories</p>
        </div>
        <div class="py-2">
            {{-- All products --}}
            <button wire:click="filterCategory(null)"
                class="w-full flex items-center gap-2.5 px-4 py-2.5 cursor-pointer transition-colors border-l-[3px] text-left
                    {{ $selectedCategory === null ? 'bg-[#e8f5e9] dark:bg-[#1f2f1f] border-brand' : 'border-transparent hover:bg-[#f0f8f0] dark:hover:bg-[#1f2f1f] hover:border-brand' }}">
                <div class="w-7 h-7 rounded-lg bg-[#f0f8f0] dark:bg-[#0d1a0d] flex items-center justify-center text-sm flex-shrink-0">🛒</div>
                <span class="text-[12px] font-medium {{ $selectedCategory === null ? 'text-brand font-semibold' : 'text-[#222] dark:text-[#e8f5e9]' }}">All Products</span>
            </button>

            @foreach ($categories as $category)
            @php $emoji = $categoryEmojis[strtolower($category->name)] ?? '📦'; @endphp
            <button wire:click="filterCategory({{ $category->id }})"
                class="w-full flex items-center gap-2.5 px-4 py-2.5 cursor-pointer transition-colors border-l-[3px] text-left
                    {{ $selectedCategory === $category->id ? 'bg-[#e8f5e9] dark:bg-[#1f2f1f] border-brand' : 'border-transparent hover:bg-[#f0f8f0] dark:hover:bg-[#1f2f1f] hover:border-brand' }}">
                <div class="w-7 h-7 rounded-lg bg-[#f0f8f0] dark:bg-[#0d1a0d] flex items-center justify-center text-sm flex-shrink-0">{{ $emoji }}</div>
                <span class="text-[12px] font-medium {{ $selectedCategory === $category->id ? 'text-brand font-semibold' : 'text-[#222] dark:text-[#e8f5e9]' }}">{{ $category->name }}</span>
                <span class="text-[10px] text-[#8a9e8c] ml-auto">{{ number_format($category->products_count) }}</span>
            </button>
            @endforeach
        </div>

        {{-- Promo mini --}}
        <div class="mx-3 mb-3 rounded-xl p-3 text-center" style="background: linear-gradient(135deg, #068B03, #0aaa05)">
            <p class="text-[10px] text-brand-lime font-bold font-montserrat">FLASH DEAL</p>
            <h4 class="text-[13px] text-white font-montserrat font-black my-1">Up to 40% OFF<br>Today Only!</h4>
            <button class="w-full bg-brand-orange text-white border-0 rounded-md py-1.5 text-[10px] font-bold font-montserrat cursor-pointer hover:bg-[#e06610] transition-colors">
                Grab Deals →
            </button>
        </div>
    </aside>

    {{-- Product area --}}
    <div class="flex-1 min-w-0">

        {{-- Toolbar --}}
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-montserrat font-black text-[17px] text-brand-dark dark:text-[#e8f5e9]">
                {{ $search ? 'Results for "' . $search . '"' : ($selectedCategory ? $categories->firstWhere('id', $selectedCategory)?->name ?? 'Products' : 'New Arrivals') }}
                <span class="text-brand-orange text-[13px] font-semibold">· {{ $products->total() }} results</span>
            </h2>
            <select wire:model.live="sort"
                class="bg-white dark:bg-[#1a2a1a] border border-[#e0e8e1] dark:border-[#2a3a2a] rounded-lg px-2.5 py-1.5 text-[12px] text-[#444] dark:text-[#b0c8b0] outline-none cursor-pointer">
                <option value="latest">Sort: Newest</option>
                <option value="price_asc">Price: Low→High</option>
                <option value="price_desc">Price: High→Low</option>
            </select>
        </div>

        {{-- Mobile category chips --}}
        <div class="flex gap-2 overflow-x-auto scrollbar-none pb-3 lg:hidden">
            <button wire:click="filterCategory(null)"
                class="flex-shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-full border-[1.5px] text-[11px] font-montserrat font-semibold cursor-pointer transition-colors
                    {{ $selectedCategory === null ? 'bg-brand border-brand text-white' : 'bg-[#f0f8f0] dark:bg-[#1a2a1a] border-[#c8e6c9] dark:border-[#2a3a2a] text-brand' }}">
                🛒 All
            </button>
            @foreach ($categories as $category)
            @php $emoji = $categoryEmojis[strtolower($category->name)] ?? '📦'; @endphp
            <button wire:click="filterCategory({{ $category->id }})"
                class="flex-shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-full border-[1.5px] text-[11px] font-montserrat font-semibold cursor-pointer transition-colors
                    {{ $selectedCategory === $category->id ? 'bg-brand border-brand text-white' : 'bg-[#f0f8f0] dark:bg-[#1a2a1a] border-[#c8e6c9] dark:border-[#2a3a2a] text-brand' }}">
                {{ $emoji }} {{ $category->name }}
            </button>
            @endforeach
        </div>

        {{-- Product grid --}}
        @if ($products->count())
        <div class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-3 gap-3.5">
            @foreach ($products as $product)
            @php
                $bg = $cardBgs[$product->id % count($cardBgs)];
                $isNew = $product->created_at->diffInDays(now()) <= 14;
                $thumbUrl = $product->getFirstMediaUrl('product-images', 'preview');
                $emoji = $categoryEmojis[strtolower($product->category?->name ?? '')] ?? '📦';
            @endphp

            <div class="group bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden cursor-pointer transition-all hover:-translate-y-[3px] hover:shadow-[0_8px_30px_rgba(6,139,3,0.1)]">

                {{-- Image + wishlist overlay --}}
                <div class="relative">
                    <a href="{{ route('product.show', $product) }}" class="block">
                        <div class="gp-card-img h-[140px] flex items-center justify-center relative" style="{{ $bg }}">
                            @if ($isNew)
                            <div class="absolute top-2.5 left-2.5 bg-brand-lime text-brand-dark text-[9px] font-bold font-montserrat px-2 py-0.5 rounded-full tracking-[0.3px]">NEW</div>
                            @endif
                            @if ($thumbUrl)
                            <img src="{{ $thumbUrl }}" alt="{{ $product->name }}"
                                class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
                            @else
                            <span class="text-5xl opacity-70">{{ $emoji }}</span>
                            @endif
                        </div>
                    </a>
                    {{-- Heart button — always visible when wishlisted, appears on hover otherwise --}}
                    <button wire:click="toggleWishlist({{ $product->id }})"
                        class="absolute top-2.5 right-2.5 w-7 h-7 rounded-full shadow-md flex items-center justify-center transition-all duration-200
                            {{ in_array($product->id, $wishlistIds)
                                ? 'bg-white dark:bg-[#1a2a1a] opacity-100 text-red-500'
                                : 'bg-white dark:bg-[#1a2a1a] opacity-0 group-hover:opacity-100 text-[#aaa] hover:text-red-400' }}"
                        title="{{ in_array($product->id, $wishlistIds) ? 'Remove from wishlist' : 'Add to wishlist' }}">
                        <svg class="w-3.5 h-3.5" fill="{{ in_array($product->id, $wishlistIds) ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                    </button>
                </div>

                {{-- Info --}}
                <div class="p-3">
                    <div class="flex items-center gap-1 mb-1">
                        <span class="text-[10px] text-[#7a9e7c] font-medium">{{ $product->vendor->name ?? 'Unknown Vendor' }}</span>
                        <div class="w-[13px] h-[13px] bg-brand rounded-full inline-flex items-center justify-center flex-shrink-0">
                            <svg class="w-2 h-2 fill-none" style="stroke:#fff;stroke-width:2.5" viewBox="0 0 24 24">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </div>
                    </div>

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

                    <div class="flex gap-1.5">
                        <button wire:click="addToCart({{ $product->id }})"
                            class="flex-1 flex items-center justify-center gap-1 bg-brand hover:bg-[#055002] text-white border-0 rounded-lg py-1.5 text-[10px] font-semibold font-montserrat cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            @disabled($product->stock_quantity < 1)>
                            <svg class="w-3 h-3 fill-none flex-shrink-0" style="stroke:currentColor;stroke-width:2.5" viewBox="0 0 24 24">
                                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                                <line x1="3" y1="6" x2="21" y2="6"/>
                                <path d="M16 10a4 4 0 0 1-8 0"/>
                            </svg>
                            Cart
                        </button>
                        <button wire:click="buyNow({{ $product->id }})"
                            class="flex-1 flex items-center justify-center gap-1 bg-brand-orange hover:bg-[#e06610] text-white border-0 rounded-lg py-1.5 text-[10px] font-semibold font-montserrat cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            @disabled($product->stock_quantity < 1)>
                            <svg class="w-3 h-3 fill-none flex-shrink-0" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                                <path d="M5 12h14M12 5l7 7-7 7"/>
                            </svg>
                            Buy Now
                        </button>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-8">
            {{ $products->links() }}
        </div>

        @else
        <div class="text-center py-20">
            <div class="text-5xl mb-4">🔍</div>
            <h3 class="font-montserrat font-bold text-[18px] text-brand-dark dark:text-[#e8f5e9] mb-2">No products found</h3>
            <p class="text-[14px] text-brand-muted mb-5">Try a different search or browse another category.</p>
            <button wire:click="filterCategory(null)" class="bg-brand text-white font-montserrat font-bold text-[13px] px-5 py-2.5 rounded-lg border-0 cursor-pointer hover:bg-[#055002] transition-colors">
                Show All Products
            </button>
        </div>
        @endif
    </div>
</div>

{{-- ─── TRUST BAND ─────────────────────────────────────────────────────────── --}}
<section class="bg-white dark:bg-[#0d1a0d] px-4 md:px-6 py-8 border-t-[3px] border-brand-lime border-b border-brand-border dark:border-[#2a3a2a]">
    <div class="max-w-[880px] mx-auto">
        <h2 class="font-montserrat font-black text-[20px] text-brand-dark dark:text-[#e8f5e9] text-center mb-1.5">Why Nigerians Trust GadgetPlug</h2>
        <p class="text-[13px] text-[#6a8a6c] dark:text-[#b0c8b0] text-center mb-7">Built for the Nigerian consumer. Powered by verified local vendors.</p>

        <div class="flex flex-col md:flex-row gap-4">
            @foreach([
                ['icon_color'=>'#068B03','num_color'=>'#068B03','num'=>'850+','label'=>'Verified Plugs','desc'=>'Every vendor is CAC-registered and background-checked. No fakes, no scams.','badge_bg'=>'#e8f5e9','badge_color'=>'#068B03','badge'=>'CAC Registered','icon_path'=>'shield'],
                ['icon_color'=>'#F97316','num_color'=>'#F97316','num'=>'Test First','label'=>'Before You Pay','desc'=>'Our dispatch riders bring the gadget to you. Inspect, test, and only then pay. Zero risk.','badge_bg'=>'#fff3e0','badge_color'=>'#c45c00','badge'=>'Rider-verified delivery','icon_path'=>'clock'],
                ['icon_color'=>'#0a2d09','num_color'=>'#0a2d09','num'=>'2-Hour','label'=>'Dispatch Guarantee','desc'=>'Orders placed before 4pm are dispatched same day. Uyo, Lagos, Abuja & 20 cities.','badge_bg'=>'#e8f5e9','badge_color'=>'#068B03','badge'=>'⚡ Same-day delivery','icon_path'=>'bolt'],
            ] as $trust)
            <div class="flex-1 bg-[#f8fcf8] dark:bg-[#162016] rounded-2xl border-[1.5px] border-[#e0eee0] dark:border-[#2a3a2a] p-6 text-center hover:-translate-y-[3px] hover:border-brand transition-all cursor-default">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3.5" style="background:{{ $trust['icon_color'] }}">
                    @if ($trust['icon_path'] === 'shield')
                    <svg class="w-7 h-7 fill-none" style="stroke:#fff;stroke-width:2" viewBox="0 0 24 24">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <polyline points="9 12 11 14 15 10" style="stroke:#fff;stroke-width:2.5"/>
                    </svg>
                    @elseif ($trust['icon_path'] === 'clock')
                    <svg class="w-7 h-7 fill-none" style="stroke:#fff;stroke-width:2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    @else
                    <svg class="w-7 h-7 fill-brand-lime" viewBox="0 0 24 24">
                        <path d="M13 2L4 14h8l-1 8 9-12h-8z"/>
                    </svg>
                    @endif
                </div>
                <div class="font-montserrat font-black text-[28px] mb-0.5" style="color:{{ $trust['num_color'] }}">{{ $trust['num'] }}</div>
                <div class="font-montserrat font-bold text-[14px] text-[#111] dark:text-[#e8f5e9] mb-1.5">{{ $trust['label'] }}</div>
                <div class="text-[11px] text-[#6a8a6c] dark:text-[#b0c8b0] leading-relaxed mb-2">{{ $trust['desc'] }}</div>
                <div class="inline-flex items-center gap-1 text-[9px] font-bold font-montserrat px-2 py-1 rounded-full"
                    style="background:{{ $trust['badge_bg'] }};color:{{ $trust['badge_color'] }}">
                    {{ $trust['badge'] }}
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ─── TRENDING BAND ───────────────────────────────────────────────────────── --}}
<section class="bg-[#e8f0e9] dark:bg-brand-dark px-4 md:px-6 py-9 transition-colors duration-200">
    <div class="flex items-center justify-between mb-5">
        <h2 class="font-montserrat font-black text-[22px] text-brand-dark dark:text-white tracking-[-0.5px]">
            Trending <span class="text-brand dark:text-brand-lime">Right Now</span>
        </h2>
        <a href="#" class="text-[12px] text-brand dark:text-brand-lime font-semibold font-montserrat cursor-pointer hover:underline">See All Categories →</a>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        @php
        $trendCards = [
            ['bg'=>'#1a1a2e','lightBg'=>'#dde0f5','emoji'=>'💻','cat'=>'Category','name'=>'Laptops & Ultrabooks','count'=>'820 products','badge'=>'From ₦450,000','span'=>true],
            ['bg'=>'#1a0a2e','lightBg'=>'#ede0f5','emoji'=>'🎧','cat'=>'Category','name'=>'Premium Audio','count'=>'640 products','badge'=>'From ₦18,000','span'=>false],
            ['bg'=>'#0d1a0d','lightBg'=>'#d8eeda','emoji'=>'📱','cat'=>'Trending','name'=>'Flagship Phones','count'=>'1,240 products','badge'=>'🔥 Hottest','span'=>false],
            ['bg'=>'#2d1a00','lightBg'=>'#fdecd0','emoji'=>'⌚','cat'=>'Category','name'=>'Wearables','count'=>'380 products','badge'=>'New Arrivals','span'=>false],
            ['bg'=>'#1a0a0a','lightBg'=>'#f5dde0','emoji'=>'🎮','cat'=>'Gaming','name'=>'Consoles & Accessories','count'=>'290 products','badge'=>'PS5 In Stock','span'=>false],
        ];
        @endphp

        @foreach ($trendCards as $i => $card)
        <div class="rounded-2xl overflow-hidden cursor-pointer relative transition-all hover:scale-[1.02] hover:shadow-lg
            {{ $card['span'] ? 'col-span-2 h-[240px]' : 'h-[200px]' }}"
            :style="`background: ${dark ? '{{ $card['bg'] }}' : '{{ $card['lightBg'] }}'}`">
            <div class="absolute inset-0 flex items-end justify-start p-4">
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 opacity-25 text-[{{ $card['span'] ? '90' : '60' }}px] leading-none select-none">{{ $card['emoji'] }}</div>
                <div class="relative z-10">
                    <div class="text-[9px] text-brand dark:text-brand-lime font-bold font-montserrat tracking-[1px] uppercase mb-1">{{ $card['cat'] }}</div>
                    <div class="font-montserrat font-black text-[{{ $card['span'] ? '20' : '15' }}px] text-[#111] dark:text-white leading-[1.2]">{{ $card['name'] }}</div>
                    <div class="text-[10px] text-[#5a7a5c] dark:text-white/50 mt-0.5">{{ $card['count'] }}</div>
                    <div class="inline-block bg-brand-orange text-white text-[9px] font-bold font-montserrat px-1.5 py-0.5 rounded-full mt-1.5">{{ $card['badge'] }}</div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</section>

</x-layouts.storefront>
</div>
