@php
    $imgUrl = $product->getFirstMediaUrl('product-images', 'thumb');
    $available = $product->storeAvailable();

    $stockDot = match (true) {
        $available === 0        => 'bg-red-500',
        $product->isStoreLowStock()   => 'bg-amber-500',
        default                  => 'bg-emerald-500',
    };
    $stockLabel = match (true) {
        $available === 0        => 'Out of stock',
        $product->isStoreLowStock()   => "Low stock ({$available})",
        default                  => "{$available} available",
    };
    $stockTextClass = match (true) {
        $available === 0        => 'text-red-600 dark:text-red-400',
        $product->isStoreLowStock()   => 'text-amber-600 dark:text-amber-400',
        default                  => 'text-gray-700 dark:text-gray-300',
    };

    $status = ($product->status === 'published' && $product->unpublish_at?->isPast()) ? 'expired' : $product->status;
    $statusClasses = match ($status) {
        'published' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        'draft'     => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
        'archived'  => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
        'expired'   => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        default     => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    };

    $eyebrow = trim(collect([$product->category?->name, $product->sku ? "SKU: {$product->sku}" : null])
        ->filter()->implode(' · '));

    $viewUrl = \App\Filament\Vendor\Resources\Products\ProductResource::getUrl('view', ['record' => $product], tenant: filament()->getTenant());
    $editUrl = \App\Filament\Vendor\Resources\Products\ProductResource::getUrl('edit', ['record' => $product], tenant: filament()->getTenant());
@endphp

<div class="relative rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4">
    <div class="absolute top-4 right-4 flex items-center gap-1">
        <a href="{{ $editUrl }}" title="Edit" aria-label="Edit {{ $product->name }}"
            class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 dark:hover:bg-white/10 hover:text-gray-700 dark:hover:text-white">
            <x-heroicon-o-pencil class="h-4 w-4"/>
        </a>
        <button wire:click="deleteProduct({{ $product->id }})"
            wire:confirm="Delete this product? This cannot be undone."
            title="Delete" aria-label="Delete {{ $product->name }}"
            class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-600">
            <x-heroicon-o-trash class="h-4 w-4"/>
        </button>
    </div>

    <a href="{{ $viewUrl }}" class="mb-4 flex items-start gap-4 pr-16">
        <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 dark:border-white/10 bg-gray-100 dark:bg-gray-800">
            @if($imgUrl)
                <img src="{{ $imgUrl }}" alt="{{ $product->name }}" loading="lazy" class="h-full w-full object-cover">
            @else
                <x-heroicon-o-photo class="h-6 w-6 text-gray-300 dark:text-gray-600"/>
            @endif
        </div>
        <div class="min-w-0">
            <h3 class="mb-1 font-montserrat text-[15px] font-semibold leading-tight text-gray-900 dark:text-white">{{ $product->name }}</h3>
            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $eyebrow !== '' ? $eyebrow : '—' }}</p>
            <span class="mt-2 inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold {{ $statusClasses }}">{{ ucfirst($status) }}</span>
        </div>
    </a>

    <div class="flex items-end justify-between border-t border-gray-100 dark:border-white/5 pt-3">
        <div>
            @if(($canSeeCostPrice ?? false) && $product->cost_price !== null)
            <p class="text-[11px] text-gray-400 dark:text-gray-500">Cost ₦{{ number_format((float) $product->cost_price, 2) }}</p>
            @endif
            <p class="font-montserrat text-base font-bold text-gray-900 dark:text-white">₦{{ number_format((float) $product->price, 2) }}</p>
        </div>
        <span class="inline-flex items-center gap-1.5 text-xs font-medium {{ $stockTextClass }}">
            <span class="h-2 w-2 rounded-full {{ $stockDot }}"></span>
            {{ $stockLabel }}
        </span>
    </div>
</div>
