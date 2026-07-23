@php
    $record = $getRecord();
    $imgUrl = $record->getFirstMediaUrl('product-images', 'thumb');
    $available = $record->available_stock;

    $badge = match (true) {
        $available === 0 => ['Out of stock', 'bg-red-500 text-white'],
        $available < 5   => ["{$available} left", 'bg-amber-500 text-white'],
        default           => ["{$available} in stock", 'bg-emerald-500 text-white'],
    };

    $eyebrow = trim(collect([$record->category?->name, $record->brand])->filter()->implode(' · '));
@endphp

<a href="{{ \App\Filament\Vendor\Resources\Products\ProductResource::getUrl('view', ['record' => $record], tenant: filament()->getTenant()) }}"
    class="group block rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 overflow-hidden transition hover:shadow-md hover:border-primary-300 dark:hover:border-primary-500/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">

    <div class="relative aspect-[4/3] bg-gray-100 dark:bg-gray-800">
        @if($imgUrl)
            <img src="{{ $imgUrl }}" alt="{{ $record->name }}" loading="lazy"
                class="absolute inset-0 h-full w-full object-cover">
        @else
            <div class="absolute inset-0 flex items-center justify-center">
                <x-heroicon-o-photo class="h-10 w-10 text-gray-300 dark:text-gray-600"/>
            </div>
        @endif

        <span class="absolute top-2 left-2 rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $badge[1] }}">
            {{ $badge[0] }}
        </span>
    </div>

    <div class="space-y-1 p-3">
        <p class="truncate text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ $eyebrow !== '' ? $eyebrow : '—' }}
        </p>

        <p class="line-clamp-2 min-h-[2.5rem] text-sm font-semibold leading-snug text-gray-950 dark:text-white">
            {{ $record->name }}
        </p>

        <div class="pt-1">
            <p class="text-xs text-gray-400 dark:text-gray-500">
                Cost {{ $record->cost_price !== null ? '₦' . number_format((float) $record->cost_price, 2) : '—' }}
            </p>
            <p class="text-base font-bold text-gray-950 dark:text-white">
                ₦{{ number_format((float) $record->price, 2) }}
            </p>
        </div>
    </div>
</a>
