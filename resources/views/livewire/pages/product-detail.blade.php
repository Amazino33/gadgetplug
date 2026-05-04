<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Services\CartService;

new class extends Component {
    public Product $product;

    public function mount(Product $product): void
    {
        $this->product = $product->load(['vendor', 'category', 'media']);
    }

    public function addToCart(): void
    {
        // We already have $this->product loaded in this component.
        app(CartService::class)->add($this->product);

        session()->flash('success', 'Added to cart!');
        $this->dispatch('cart-updated');
    }
}; ?>

<div>
    <x-layouts.storefront>
        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                {{-- Breadcrumbs --}}
                <nav class="mb-6 text-sm text-gray-500 dark:text-gray-4--">
                    <a href="{{ route('home') }}" class="hover:text-blue-600 dark:hover:text-blue-400">Home</a>
                    <span class="mx-2">/</span>
                    <span>{{ $product->name }}</span>
                </nav>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 p-6 md:p-8">
                        {{-- Image Placeholder --}}
                        @php
                            $allImages  = $product->getMedia('product-images');
                            $firstImage = $allImages->first();
                            $defaultUrl = $firstImage ? $firstImage->getUrl('preview') : '';
                        @endphp

                        <div class="flex flex-col gap-4" x-data="{ currentImage: '{{ $defaultUrl }}' }">

                            {{-- Main Image --}}
                            @if($firstImage)
                                <div class="aspect-square w-full bg-gray-100 dark:bg-gray-800 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                                    <img
                                        x-bind:src="currentImage"
                                        alt="{{ $product->name }}"
                                        class="w-full h-full object-cover transition-all duration-300"
                                    >
                                </div>
                            @else
                                <div class="aspect-square bg-gray-200 dark:bg-gray-700 rounded-lg flex items-center justify-center border border-gray-200 dark:border-gray-700">
                                    <span class="text-gray-500 dark:text-gray-400 text-lg">No Image</span>
                                </div>
                            @endif

                            {{-- Thumbnails (only if more than one image) --}}
                            @if($allImages->count() > 1)
                                <div class="grid grid-cols-4 gap-3">
                                    @foreach($allImages as $image)
                                        @php
                                            $thumbUrl   = $image->getUrl('thumb');
                                            $previewUrl = $image->getUrl('preview');
                                        @endphp
                                        <button
                                            @click="currentImage = '{{ $previewUrl }}'"
                                            class="aspect-square rounded-md overflow-hidden focus:outline-none transition-all duration-200 border-2"
                                            :class="currentImage === '{{ $previewUrl }}'
                                                ? 'border-blue-600 opacity-100'
                                                : 'border-transparent opacity-60 hover:opacity-100 hover:border-gray-300'"
                                        >
                                            <img
                                                src="{{ $thumbUrl }}"
                                                alt="{{ $product->name }} thumbnail"
                                                class="w-full h-full object-cover"
                                                loading="lazy"
                                            >
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                        </div>

                        {{-- Product Info --}}
                        <div>
                            {{-- Vendor & Category --}}
                            <div class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                                <span>{{ $product->vendor->name ?? 'Unknown Vendor' }}</span>
                                @if ($product->category)
                                    <span class="mx-1">·</span>
                                    <span>{{ $product->category->name }}</span>
                                @endif
                            </div>

                            <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                                {{ $product->name }}
                            </h1>

                            @if ($product->brand)
                                <p class="text-lg text-gray-600 dark:text-gray-300 mb-4">
                                    Brand: {{ $product->brand }}
                                </p>
                            @endif

                            {{-- Price & Stock --}}
                            <div class="flex items-center gap-4 mb-6">
                                <span class="text-3xl font-bold text-gray-900 dark:text-white">
                                    ₦{{ number_format($product->price, 2) }}
                                </span>
                                <span class="px-3 py-1 rounded-full text-sm font-medium
                                    {{ $product->stock_quantity > 0 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">
                                    {{ $product->stock_quantity > 0 ?'In Stock' : 'Out of Stock' }}
                                </span>
                            </div>

                            {{-- Add to Cart Button --}}
                            <button wire:click="addToCart" class="w-full md:w-auto px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed" @disabled($product->stock_quantity < 1)>
                                Add to Cart
                            </button>

                            {{-- Description --}}
                            @if ($product->description)
                                <div class="mt-8">
                                    <div class="text-xl font-semibold text-gray-900 dark:text-white mb-3">Description</div>
                                    <div class="prose prose-sm dark:prose-invert max-w-none text-gray-700 dark:text-gray-300">
                                        {{ $product->description }}
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Specifications Section --}}
                    @if (!empty($product->specifications))
                        <div class="border-t border-gray-200 dark:border-gray-700 p-6 md:p-8">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Specifications</h2>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @foreach ($product->specifications as $key => $value)
                                            <tr>
                                                <td class="py-3 pr-4 text-sm font-medium text-gray-900 dark:text-white whitespace-nowrap">
                                                    {{ ucwords(str_replace('_', ' ', $key)) }}
                                                </td>
                                                <td class="py-3 text-sm text-gray-700 dark:text-gray-300">
                                                    {{ is_array($value) ? json_encode($value) : $value }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </x-layouts.storefront>
</div>
