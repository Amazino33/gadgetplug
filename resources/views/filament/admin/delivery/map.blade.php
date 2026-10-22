{{-- Delivery settings map: every LGA as a dot coloured by its rule, the store
     hubs as blue pins. Tapping an LGA opens the page's editLga sheet. The whole
     card is wire:ignore'd — a Livewire re-render would start Leaflet a second
     time — and redraws itself from delivery-data-updated instead. --}}
<div wire:ignore x-data="gpDeliveryMap(@js($data))" x-on:delivery-data-updated.window="refresh($event.detail.data)" class="gp-delivery-map grid gap-3">
    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
        <x-filament::input.wrapper class="w-full sm:w-64">
            <x-filament::input.select x-model="stateFilter" aria-label="State">
                <option value="">All of Nigeria</option>
                @foreach ($data['states'] as $state)
                    <option value="{{ $state['id'] }}">{{ $state['name'] }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium text-gray-950 dark:text-white">
            <x-filament::input.checkbox x-model="onlyDelivered" />
            Only areas we deliver to
        </label>

        <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium text-gray-950 dark:text-white">
            <x-filament::input.checkbox x-model="showRings" />
            Distance rings
        </label>
    </div>

    <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-gray-700 dark:text-gray-300">
        <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-green-600 ring-1 ring-gray-950/20"></span> Free</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-amber-600 ring-1 ring-gray-950/20"></span> Fee</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-gray-400 ring-1 ring-gray-950/20"></span> Not delivered</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-blue-600 ring-1 ring-gray-950/20"></span> Store hub</span>
    </div>

    <div x-ref="map" class="h-[60vh] min-h-80 w-full overflow-hidden rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10"></div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Distances are straight-line ("as the crow flies") from the nearest store hub. Road distance is always longer.
    </p>
</div>
