<?php

use App\Models\Product;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Services\CartService;
use function Livewire\Volt\{state};

new class extends Component {
    use WithPagination;

    public function with(): array
    {
        return [
            'products' => Product::with(['vendor', 'category', 'media'])
                ->where('stock_quantity', '>', 0)
                ->latest()
                ->paginate(12),
        ];
    }

    public function addToCart($productId): void
    {
        $cart = Session::get('cart', []);
        $product = Product::find($productId);

        if (!$product) return;

        // Call our new Single Source of Truth
        app(CartService::class)->add($product);

        session()->flash('success', 'Added to cart!');
        $this->dispatch('cart-updated');
    }
}

?>
<div>
    <x-layouts.storefront>
        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <h1 class="text-3xl font-bold mb-6 dark:text-white">
                    Latest Gadgets
                </h1>

                {{-- Product Grid --}}
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($products as $product)
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden hover:shadow-lg transition">
                        {{-- Product Image --}}
                        <a href="{{ route('product.show', $product) }}"
                            class="block h-48 w-full bg-gray-100 dark:bg-gray-800 overflow-hidden hover:opacity-75 transition relative group">

                            @php
                                $thumbUrl = $product->getFirstMediaUrl('product-images', 'thumb');
                            @endphp

                            @if($thumbUrl)
                                <img
                                    src="{{ $thumbUrl }}"
                                    alt="{{ $product->name }}"
                                    class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                                    loading="lazy"
                                >
                            @else
                                <div class="w-full h-full flex items-center justify-center bg-gray-200 dark:bg-gray-700">
                                    <span class="text-gray-500 dark:text-gray-400 text-sm">No Image</span>
                                </div>
                            @endif
                        </a>

                        <div class="p-4">
                            {{-- Vendor & Category --}}
                            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">
                                {{ $product->vendor->name ?? 'Unknown Vendor' }}
                                @if ($product->category)
                                &nbsp; · &nbsp;{{ $product->category->name }}
                                @endif
                            </div>

                            {{-- Product Name --}}
                            <h2 class="text-lg font-semibold truncate">
                                <a href="{{ route('product.show', $product) }}"
                                    class="hover:text-blue-600 dark:hover:text-blue-400 dark:text-white">
                                    {{ $product->name }}
                                </a>
                            </h2>

                            {{-- Brand --}}
                            @if ($product->brand)
                            <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">{{ $product->brand }}</p>
                            @endif

                            {{-- Price & Stock --}}
                            <div class="mt-4 flex items-center justify-between">
                                <span class="text-xl font-bold dark:text-white">
                                    ₦{{ number_format($product->price, 2) }}
                                </span>
                                <span
                                    class="text-sm {{ $product->stock_quantity > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $product->stock_quantity > 0 ? 'In Stock' : 'Out of Stock' }}
                                </span>
                            </div>

                            {{-- Add to cart placeholder --}}
                            <button wire:click="addToCart({{ $product->id }})"
                                class="mt-4 w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition disabled:opacity-50 disabled:cursor-not-allowed"
                                @disabled($product->stock_quantity < 1 )>
                                    Add to Cart
                            </button>
                        </div>

                    </div>
                    @endforeach
                </div>

                {{-- Pagination --}}
                <div class="mt-8">
                    {{ $products->links() }}
                </div>
            </div>
        </div>
    </x-layouts.storefront>
</div>