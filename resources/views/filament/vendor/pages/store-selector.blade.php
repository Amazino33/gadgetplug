<x-filament-panels::page>
    @php
        $stores = $this->stores();
        $metrics = $this->metrics();
        $activeId = $this->activeStoreId();
        $showCost = $this->canSeeCostValue();
    @endphp

    @if ($stores->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center">
            <x-heroicon-o-building-storefront class="mx-auto h-10 w-10 text-gray-400"/>
            <p class="mt-3 font-semibold text-gray-900 dark:text-white">You are not assigned to any store yet.</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Ask the store owner to give you access to a store, then reload this page.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($stores as $store)
                @php
                    $m = $metrics[$store->id] ?? \App\Services\Inventory\StoreStockMetrics::empty();
                    $isActive = $activeId === $store->id;
                @endphp

                <button type="button"
                    wire:click="selectStore({{ $store->id }})"
                    wire:loading.attr="disabled"
                    class="group relative flex flex-col rounded-xl border p-5 text-left transition
                        {{ $isActive
                            ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/10'
                            : 'border-gray-200 dark:border-white/10 hover:border-primary-400 hover:shadow-md bg-white dark:bg-white/5' }}">

                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-montserrat text-base font-bold text-gray-900 dark:text-white">
                                {{ $store->name }}
                            </p>
                            <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $store->address ?: 'No address set' }}
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-col items-end gap-1">
                            @if ($isActive)
                                <span class="rounded-full bg-primary-600 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">Active</span>
                            @endif
                            @if ($store->is_default)
                                <span class="rounded-full bg-gray-100 dark:bg-white/10 px-2 py-0.5 text-[10px] font-medium text-gray-600 dark:text-gray-300">Default</span>
                            @endif
                            @unless ($store->is_active)
                                <span class="rounded-full bg-red-100 dark:bg-red-900/30 px-2 py-0.5 text-[10px] font-medium text-red-700 dark:text-red-300">Inactive</span>
                            @endunless
                        </div>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-gray-100 dark:border-white/10 pt-4">
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Products</dt>
                            <dd class="mt-0.5 font-montserrat text-lg font-bold text-gray-900 dark:text-white">{{ number_format($m->product_count) }}</dd>
                        </div>

                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Low stock</dt>
                            <dd class="mt-0.5 font-montserrat text-lg font-bold {{ $m->low_stock_count > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white' }}">
                                {{ number_format($m->low_stock_count) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Value (retail)</dt>
                            <dd class="mt-0.5 font-montserrat text-base font-bold text-gray-900 dark:text-white">₦{{ number_format($m->retail_value, 2) }}</dd>
                        </div>

                        {{-- Cost is the sensitive figure: it reveals margin. Gated on the
                             same check the product form uses, so a staff member without it
                             sees the card without ever seeing what the stock cost. --}}
                        @if ($showCost)
                            <div>
                                <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Value (cost)</dt>
                                <dd class="mt-0.5 font-montserrat text-base font-bold text-gray-900 dark:text-white">₦{{ number_format($m->cost_value, 2) }}</dd>
                            </div>
                        @endif
                    </dl>

                    <span class="mt-4 inline-flex items-center gap-1 text-xs font-semibold text-primary-600 dark:text-primary-400">
                        {{ $isActive ? 'Continue here' : 'Work in this store' }}
                        <x-heroicon-m-arrow-right class="h-3.5 w-3.5 transition group-hover:translate-x-0.5"/>
                    </span>
                </button>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
