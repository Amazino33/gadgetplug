{{-- "Pin on map" (HubsTable): tap or drag the pin, or use this device's
     location. It fills the form's latitude and longitude fields beside it. --}}
@php
    $base = \Illuminate\Support\Str::beforeLast($getStatePath(), '.');
@endphp

<div x-data="gpPinMap" data-lat-path="{{ $base }}.latitude" data-lng-path="{{ $base }}.longitude" class="grid gap-3">
    <x-filament::button
        type="button"
        icon="heroicon-m-map-pin"
        color="gray"
        size="lg"
        class="w-full"
        x-on:click="locate()"
        x-bind:disabled="locating"
    >
        <span x-text="locating ? 'Finding you…' : 'Use my current location'"></span>
    </x-filament::button>

    <p x-show="error" x-text="error" x-cloak class="text-sm text-danger-600 dark:text-danger-400"></p>

    <div wire:ignore x-ref="map" class="h-[45vh] min-h-72 w-full overflow-hidden rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10"></div>
</div>
