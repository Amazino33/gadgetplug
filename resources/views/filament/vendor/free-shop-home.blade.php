{{--
    The home page of a free shop's panel, in place of the paid dashboard's
    sales widgets (which a shop with no till has nothing to show in). The
    review banner above it comes from the panel's CONTENT_START hook. The full
    free dashboard arrives in a later phase; this is the placeholder that
    points at the two things a free shop can do.
--}}
@php
    /** @var \App\Models\Vendor $vendor */
    $vendor = filament()->getTenant();
    $count  = $vendor->products()->count();
    $limit  = \App\Models\FreePlanSetting::productLimit();
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <a href="{{ \App\Filament\Vendor\Resources\Products\ProductResource::getUrl('index') }}"
       class="block rounded-xl border border-gray-200 bg-white p-5 hover:border-primary-500 dark:border-white/10 dark:bg-white/5">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Products</p>
        <p class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ $count }} <span class="text-sm font-medium text-gray-500">of {{ $limit }}</span></p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            {{ $count === 0 ? 'Add your first product so customers have something to see.' : 'Add or edit what your shop sells.' }}
        </p>
    </a>

    <a href="{{ \App\Filament\Vendor\Pages\ShopProfile::getUrl() }}"
       class="block rounded-xl border border-gray-200 bg-white p-5 hover:border-primary-500 dark:border-white/10 dark:bg-white/5">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Shop Profile</p>
        <p class="mt-1 truncate text-lg font-bold text-gray-950 dark:text-white">{{ $vendor->name }}</p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Logo, description, category, location and WhatsApp number.</p>
    </a>
</div>
