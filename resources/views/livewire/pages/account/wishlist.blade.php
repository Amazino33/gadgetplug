<?php

use Livewire\Volt\Component;
use App\Models\Wishlist;

new class extends Component {
    public function removeFromWishlist(int $productId): void
    {
        Wishlist::where('user_id', auth()->id())
            ->where('product_id', $productId)
            ->delete();
    }

    public function with(): array
    {
        return [
            'items' => auth()->user()
                ->wishlistedProducts()
                ->with('media', 'category')
                ->latest('wishlists.created_at')
                ->get(),
        ];
    }
}; ?>

<div>
<x-layouts.account active="account.wishlist">

    <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl overflow-hidden">
        <div class="px-5 md:px-6 py-4 border-b border-brand-border dark:border-[#2a3a2a]">
            <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">
                Wishlist
                @if ($items->count() > 0)
                <span class="ml-1.5 bg-brand-lime text-brand-dark text-[10px] font-black px-1.5 py-0.5 rounded-full">{{ $items->count() }}</span>
                @endif
            </h2>
        </div>

        @if ($items->isEmpty())
        <div class="px-6 py-14 text-center">
            <div class="text-5xl mb-3">🤍</div>
            <h4 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9] mb-1">Your wishlist is empty</h4>
            <p class="text-[12px] text-brand-muted mb-4">Tap the heart icon on any product to save it here.</p>
            <a href="{{ route('home') }}"
               class="inline-block bg-brand text-white font-montserrat font-bold text-[12px] px-5 py-2 rounded-xl hover:bg-[#055002] transition-colors">
                Browse Products
            </a>
        </div>
        @else
        <div class="divide-y divide-brand-border dark:divide-[#2a3a2a]">
            @foreach ($items as $product)
            <div class="flex items-center gap-4 px-5 md:px-6 py-4">
                {{-- Thumbnail --}}
                <a href="{{ route('product.show', $product->slug) }}"
                   class="w-16 h-16 rounded-xl bg-brand-bg dark:bg-[#0d1a0d] border border-brand-border dark:border-[#2a3a2a] flex items-center justify-center flex-shrink-0 overflow-hidden">
                    @if ($product->getFirstMediaUrl('product-images', 'thumb'))
                        <img src="{{ $product->getFirstMediaUrl('product-images', 'thumb') }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                    @else
                        <span class="text-2xl">📱</span>
                    @endif
                </a>

                {{-- Info --}}
                <div class="flex-1 min-w-0">
                    <a href="{{ route('product.show', $product->slug) }}"
                       class="font-semibold text-[13px] text-brand-dark dark:text-[#e8f5e9] hover:text-brand line-clamp-1 block">
                        {{ $product->name }}
                    </a>
                    <p class="text-[11px] text-brand-muted mt-0.5">{{ $product->category?->name }}</p>
                    <p class="font-montserrat font-black text-[14px] text-brand mt-1">₦{{ number_format($product->price) }}</p>
                </div>

                {{-- Stock + remove --}}
                <div class="flex flex-col items-end gap-2 flex-shrink-0">
                    @if ($product->stock_quantity > 0)
                    <span class="text-[10px] font-semibold text-brand bg-[#e8f5e8] dark:bg-[#1a2a1a] px-2 py-0.5 rounded-full">In Stock</span>
                    @else
                    <span class="text-[10px] font-semibold text-red-500 bg-red-50 dark:bg-red-900/20 px-2 py-0.5 rounded-full">Out of Stock</span>
                    @endif
                    <button wire:click="removeFromWishlist({{ $product->id }})"
                        class="text-[11px] text-[#bbb] dark:text-[#7a9e7c] hover:text-red-500 transition-colors flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Remove
                    </button>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

</x-layouts.account>
</div>
