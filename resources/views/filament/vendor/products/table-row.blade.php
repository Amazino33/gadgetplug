@php
    $imgUrl = $product->getFirstMediaUrl('product-images', 'thumb');
    $available = $product->available_stock;

    $stockDot = match (true) {
        $available === 0 => 'bg-red-500',
        $available < 5   => 'bg-amber-500',
        default           => 'bg-emerald-500',
    };
    $stockLabel = match (true) {
        $available === 0 => 'Out of stock',
        $available < 5   => "Low stock ({$available})",
        default           => "{$available} available",
    };
    $stockTextClass = match (true) {
        $available === 0 => 'text-red-600 dark:text-red-400',
        $available < 5   => 'text-amber-600 dark:text-amber-400',
        default           => 'text-gray-700 dark:text-gray-300',
    };

    $status = ($product->status === 'published' && $product->unpublish_at?->isPast()) ? 'expired' : $product->status;
    $statusClasses = match ($status) {
        'published' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        'draft'     => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
        'archived'  => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
        'expired'   => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        default     => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    };

    $eyebrow = trim(collect([$product->category?->name, $product->brand, $product->sku ? "SKU: {$product->sku}" : null])
        ->filter()->implode(' · '));

    $viewUrl = \App\Filament\Vendor\Resources\Products\ProductResource::getUrl('view', ['record' => $product], tenant: filament()->getTenant());
    $editUrl = \App\Filament\Vendor\Resources\Products\ProductResource::getUrl('edit', ['record' => $product], tenant: filament()->getTenant());
@endphp

<tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
    <td class="py-3 px-4">
        <input type="checkbox"
            aria-label="Select {{ $product->name }}"
            wire:click="toggleSelected({{ $product->id }})"
            @checked(in_array($product->id, $selected, true))
            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
    </td>
    <td class="py-3 px-4">
        <a href="{{ $viewUrl }}" class="group flex items-center gap-3">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 dark:border-white/10 bg-gray-100 dark:bg-gray-800">
                @if($imgUrl)
                    <img src="{{ $imgUrl }}" alt="{{ $product->name }}" loading="lazy" class="h-full w-full object-cover">
                @else
                    <x-heroicon-o-photo class="h-5 w-5 text-gray-300 dark:text-gray-600"/>
                @endif
            </div>
            <div class="min-w-0">
                <p title="{{ $product->name }}" class="truncate font-montserrat text-sm font-semibold text-gray-900 dark:text-white group-hover:underline">{{ $product->name }}</p>
                <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $eyebrow !== '' ? $eyebrow : '—' }}</p>
            </div>
        </a>
    </td>
    <td class="whitespace-nowrap py-3 px-4 text-right">
        @if($product->cost_price !== null)
        <p class="text-xs text-gray-400 dark:text-gray-500">Cost ₦{{ number_format((float) $product->cost_price, 2) }}</p>
        @endif
        <p class="font-montserrat text-sm font-bold text-gray-900 dark:text-white">₦{{ number_format((float) $product->price, 2) }}</p>
    </td>
    <td class="whitespace-nowrap py-3 px-4 text-center">
        <span class="inline-flex items-center gap-1.5 text-xs font-medium {{ $stockTextClass }}">
            <span class="h-2 w-2 rounded-full {{ $stockDot }}"></span>
            {{ $stockLabel }}
        </span>
    </td>
    <td class="py-3 px-4 text-center">
        <span class="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold {{ $statusClasses }}">{{ ucfirst($status) }}</span>
    </td>
    <td class="py-3 px-4">
        <div class="flex items-center justify-end gap-1">
            <a href="{{ $editUrl }}" title="Edit" aria-label="Edit {{ $product->name }}"
                class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-gray-100 dark:hover:bg-white/10 hover:text-gray-700 dark:hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                <x-heroicon-o-pencil class="h-4 w-4"/>
            </a>
            <button wire:click="deleteProduct({{ $product->id }})"
                wire:confirm="Delete this product? This cannot be undone."
                title="Delete" aria-label="Delete {{ $product->name }}"
                class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                <x-heroicon-o-trash class="h-4 w-4"/>
            </button>
        </div>
    </td>
</tr>
