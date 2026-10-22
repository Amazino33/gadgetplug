{{-- The order page's map: the store holding the stock, the customer, a line
     between them and its straight-line length. The customer is their shared
     pin when they gave one at checkout, otherwise their LGA's centre —
     labelled approximate. Staff only; never shown anywhere public. --}}
@php
    $map = $getState();
@endphp

@if ($map['store'] && $map['customer'])
    <div wire:ignore x-data="gpOrderMap(@js($map))" class="grid gap-3">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-gray-700 dark:text-gray-300">
            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-blue-600 ring-1 ring-gray-950/20"></span> {{ $map['store']['name'] }}</span>
            <span class="inline-flex items-center gap-1.5">
                <span @class(['size-3 rounded-full ring-1 ring-gray-950/20', 'bg-green-600' => $map['customer']['precision'] === 'exact', 'bg-amber-600' => $map['customer']['precision'] !== 'exact'])></span>
                {{ $map['customer']['label'] }}
            </span>
        </div>

        <div x-ref="map" class="h-72 w-full overflow-hidden rounded-xl ring-1 ring-gray-950/10 sm:h-80 dark:ring-white/10"></div>

        <p class="text-sm font-medium text-gray-950 dark:text-white">
            {{ number_format($map['km'], 1) }} km straight-line
            <span class="font-normal text-gray-500 dark:text-gray-400">— the road is longer.</span>
        </p>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">
        @if (! $map['store'])
            The store holding this order's stock has no location yet — pin it under Delivery settings → Store locations.
        @else
            No location for the customer: their LGA has no reference point.
        @endif
    </p>
@endif
