<x-filament-panels::page>
    @php
        $products = $this->getProducts();
        $idsOnPage = $products->pluck('id')->all();
        $allOnPageSelected = count($idsOnPage) > 0 && count(array_intersect($idsOnPage, $selected)) === count($idsOnPage);
        $canSeeCostPrice = $this->canSeeCostPrice();
    @endphp

    <div class="space-y-4">
        {{-- Toolbar --}}
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 rounded-xl p-4">
            <div class="relative flex-1 sm:max-w-xs">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <x-heroicon-o-magnifying-glass class="h-4 w-4 text-gray-400"/>
                </div>
                <input type="text" wire:model.live.debounce.300ms="search"
                    aria-label="Search products by name or SKU"
                    placeholder="Search products by name or SKU…"
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 py-2 pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>

            <select wire:model.live="statusFilter"
                aria-label="Filter by status"
                class="rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="published">Published</option>
                <option value="archived">Archived</option>
            </select>

            <select wire:model.live="categoryFilter"
                aria-label="Filter by category"
                class="rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">All categories</option>
                @foreach($this->getCategoryOptions() as $categoryId => $categoryName)
                    <option value="{{ $categoryId }}">{{ $categoryName }}</option>
                @endforeach
            </select>

            @if(count($selected) > 0)
            <button wire:click="deleteSelected"
                wire:confirm="Delete {{ count($selected) }} selected product(s)? This cannot be undone."
                class="inline-flex items-center gap-1.5 rounded-lg bg-red-50 dark:bg-red-900/20 px-3 py-2 text-sm font-semibold text-red-700 dark:text-red-400 transition-colors hover:bg-red-100 dark:hover:bg-red-900/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                <x-heroicon-o-trash class="h-4 w-4"/>
                Delete {{ count($selected) }} selected
            </button>
            @endif
        </div>

        @if($displayMode === 'grid')
            {{-- GRID VIEW --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @forelse($products as $product)
                    @include('filament.vendor.products.grid-card', ['record' => $product, 'canSeeCostPrice' => $canSeeCostPrice])
                @empty
                    <div class="col-span-full py-12 text-center text-sm text-gray-400">No products found.</div>
                @endforelse
            </div>
        @else
            {{-- DESKTOP TABLE --}}
            <div class="hidden md:block overflow-hidden rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900">
                <table class="w-full text-left [table-layout:fixed]">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-gray-800">
                            <th class="w-10 py-3 px-4">
                                <input type="checkbox"
                                    aria-label="Select all products on this page"
                                    @checked($allOnPageSelected)
                                    wire:click="toggleSelectAllOnPage"
                                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            </th>
                            <th class="w-[38%] py-3 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Product</th>
                            <th class="w-[18%] py-3 px-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Pricing</th>
                            <th class="w-[17%] py-3 px-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Stock</th>
                            <th class="w-[13%] py-3 px-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
                            <th class="w-24 py-3 px-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse($products as $product)
                            @include('filament.vendor.products.table-row', ['product' => $product, 'canSeeCostPrice' => $canSeeCostPrice])
                        @empty
                            <tr><td colspan="6" class="py-12 text-center text-sm text-gray-400">No products found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- MOBILE CARDS --}}
            <div class="md:hidden space-y-3">
                @forelse($products as $product)
                    @include('filament.vendor.products.mobile-card', ['product' => $product, 'canSeeCostPrice' => $canSeeCostPrice])
                @empty
                    <p class="py-12 text-center text-sm text-gray-400">No products found.</p>
                @endforelse
            </div>
        @endif

        <div>{{ $products->links() }}</div>
    </div>
</x-filament-panels::page>
