<x-filament-panels::page>
    @php
        $stores = $this->selectableStores();
    @endphp

    {{-- Only worth showing when there is more than one branch to choose
         between: a single-store vendor gains nothing from a dropdown whose
         every option means the same thing. --}}
    @if ($stores->count() > 1)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 px-4 py-3">
            <label for="inventoryStoreFilter" class="text-sm font-medium text-gray-700 dark:text-gray-200">
                Showing
            </label>

            <select
                id="inventoryStoreFilter"
                wire:model.live="storeFilter"
                class="rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
                <option value="">All stores — whole business</option>
                @foreach ($stores as $store)
                    <option value="{{ $store->id }}">
                        {{ $store->name }}@if ($store->is_default) (main) @endif
                    </option>
                @endforeach
            </select>

            <p class="text-xs text-gray-500 dark:text-gray-400">
                Every figure below — value, units and movement — is for this selection.
            </p>
        </div>
    @endif

    <x-filament-widgets::widgets
        :widgets="$this->getWidgets()"
        :columns="$this->getColumns()"
        :data="$this->getWidgetData()"
    />
</x-filament-panels::page>
