<x-filament-panels::page>
    {{--
        The Products list lives in the browser (resources/js/products-list.js):
        the first 50 rows come with the page, the whole branch follows in the
        background, and from then on searching, the tiles, filters, sorting and
        paging never wait on the server. wire:ignore keeps Livewire from
        re-drawing it; changes made here tell it to refresh (products-changed).
    --}}
    <div wire:ignore
        x-data="productsList(@js($this->getListConfig()))"
        x-on:products-changed.window="refreshNow()"
        class="space-y-4">

        {{-- ── Summary: every number is also a filter ─────────────────────── --}}
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2 2xl:grid-cols-4">
            @php
                $groups = [
                    'stock'    => ['Stock health', 'heroicon-o-cube'],
                    'gaps'     => ['Catalogue gaps', 'heroicon-o-clipboard-document-check'],
                    'movement' => ['Movement', 'heroicon-o-arrow-trending-up'],
                ];
            @endphp
            @foreach($groups as $group => [$title, $icon])
                <section class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900" aria-label="{{ $title }}">
                    <h3 class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-gray-500">
                        <x-dynamic-component :component="$icon" class="h-4 w-4"/> {{ $title }}
                    </h3>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="[key, t] in Object.entries(tiles).filter(([, t]) => t.group === '{{ $group }}')" :key="key">
                            <button type="button"
                                x-on:click="key === 'total' ? (tile = null) : pickTile(key)"
                                :aria-pressed="tile === key"
                                :class="tile === key
                                    ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500 dark:bg-primary-500/10'
                                    : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5'"
                                class="flex min-w-[7.5rem] flex-1 flex-col items-start rounded-lg border px-3 py-2 text-left transition">
                                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="t.label"></span>
                                <span x-show="loaded" class="font-montserrat text-lg font-bold"
                                    :class="{
                                        'text-emerald-600 dark:text-emerald-400': t.tone === 'green' && t.count > 0,
                                        'text-amber-600 dark:text-amber-400': t.tone === 'amber' && t.count > 0,
                                        'text-red-600 dark:text-red-400': t.tone === 'red' && t.count > 0,
                                        'text-gray-900 dark:text-white': !t.tone || t.count === 0,
                                    }"
                                    x-text="count(key === 'total' ? total : t.count)"></span>
                                <span x-show="!loaded" class="gp-shimmer mt-1 block h-5 w-14"></span>
                            </button>
                        </template>
                    </div>
                </section>
            @endforeach

            {{-- What the stock is worth: for roles allowed to see it (Visibility). --}}
            @if (\App\Support\Visibility::stockValue())
            <section class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900" aria-label="Stock value">
                <h3 class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-gray-500">
                    <x-heroicon-o-banknotes class="h-4 w-4"/> Stock value
                </h3>
                <div class="flex flex-wrap gap-1.5">
                    <div class="flex min-w-[7.5rem] flex-1 flex-col rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
                        <span class="text-xs text-gray-500 dark:text-gray-400">At selling price</span>
                        <span x-show="loaded" class="font-montserrat text-lg font-bold text-gray-900 dark:text-white" :title="money(values.retail)" x-text="moneyShort(values.retail)"></span>
                        <span x-show="!loaded" class="gp-shimmer mt-1 block h-5 w-16"></span>
                    </div>
                    <template x-if="cfg.can.cost">
                        <div class="flex min-w-[7.5rem] flex-1 flex-col rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
                            <span class="text-xs text-gray-500 dark:text-gray-400">At cost</span>
                            <span x-show="loaded" class="font-montserrat text-lg font-bold text-gray-900 dark:text-white" :title="money(values.cost)" x-text="moneyShort(values.cost)"></span>
                            <span x-show="!loaded" class="gp-shimmer mt-1 block h-5 w-16"></span>
                        </div>
                    </template>
                    @if (\App\Support\Visibility::profit())
                    <template x-if="cfg.can.cost">
                        <div class="flex min-w-[7.5rem] flex-1 flex-col rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
                            <span class="text-xs text-gray-500 dark:text-gray-400">Potential profit</span>
                            <span x-show="loaded" class="font-montserrat text-lg font-bold text-emerald-600 dark:text-emerald-400" :title="money(values.profit)" x-text="moneyShort(values.profit)"></span>
                            <span x-show="!loaded" class="gp-shimmer mt-1 block h-5 w-16"></span>
                        </div>
                    </template>
                    @endif
                </div>
            </section>
            @endif
        </div>

        {{-- ── Toolbar ──────────────────────────────────────────────────────── --}}
        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-col gap-2 2xl:flex-row 2xl:items-center">
                <div class="relative w-full min-w-[16rem] 2xl:max-w-md 2xl:flex-1">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <x-heroicon-o-magnifying-glass class="h-4 w-4 text-gray-400"/>
                    </div>
                    <input type="search" x-model.debounce.150ms="search"
                        data-tour="products-search"
                        aria-label="Search products by name, SKU or barcode"
                        placeholder="Search name, SKU or barcode…"
                        class="w-full rounded-lg border border-gray-300 bg-gray-50 py-2 pl-9 pr-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800">
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select x-model="status" aria-label="Filter by status" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800">
                        <option value="">All statuses</option>
                        <option value="published">Published</option>
                        <option value="draft">Draft</option>
                        <option value="archived">Archived</option>
                    </select>

                    <select x-model="category" aria-label="Filter by category" class="max-w-[12rem] rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800">
                        <option value="">All categories</option>
                        <template x-for="[id, name] in Object.entries(categories).sort((a, b) => a[1].localeCompare(b[1]))" :key="id">
                            <option :value="id" x-text="name" :selected="String(category) === String(id)"></option>
                        </template>
                    </select>

                    <select x-model="sort" aria-label="Sort" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800">
                        <template x-for="[key, label] in Object.entries(sorts).filter(([k]) => k !== 'margin_desc' || cfg.can.cost)" :key="key">
                            <option :value="key" x-text="label" :selected="sort === key"></option>
                        </template>
                    </select>

                    <select x-model.number="perPage" aria-label="Rows per page" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800">
                        <template x-for="n in pageSizes" :key="n">
                            <option :value="n" x-text="n + ' per page'" :selected="perPage === n"></option>
                        </template>
                    </select>

                    <div class="inline-flex overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700" role="group" aria-label="View">
                        <button type="button" x-on:click="display = 'table'" :aria-pressed="display === 'table'"
                            :class="display === 'table' ? 'bg-primary-500 text-white' : 'bg-gray-50 text-gray-700 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200'"
                            class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium">
                            <x-heroicon-o-table-cells class="h-4 w-4"/> Table
                        </button>
                        <button type="button" x-on:click="display = 'grid'" :aria-pressed="display === 'grid'"
                            :class="display === 'grid' ? 'bg-primary-500 text-white' : 'bg-gray-50 text-gray-700 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200'"
                            class="inline-flex items-center gap-1 border-l border-gray-300 px-3 py-2 text-sm font-medium dark:border-gray-700">
                            <x-heroicon-o-squares-2x2 class="h-4 w-4"/> Grid
                        </button>
                    </div>

                    <div class="relative" x-show="display === 'table'" x-on:click.outside="columnsOpen = false">
                        <button type="button" x-on:click="columnsOpen = !columnsOpen" :aria-expanded="columnsOpen"
                            class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                            <x-heroicon-o-view-columns class="h-4 w-4"/> Columns
                        </button>
                        <div x-show="columnsOpen" x-transition class="absolute right-0 z-20 mt-1 w-52 rounded-lg border border-gray-200 bg-white p-2 shadow-lg dark:border-white/10 dark:bg-gray-900">
                            <template x-for="[key, label] in Object.entries(optionalColumns).filter(([k]) => k !== 'margin' || cfg.can.cost)" :key="key">
                                <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                    <input type="checkbox" :checked="has(key)" x-on:change="toggleColumn(key)" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                                    <span x-text="label"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- What is showing, and what is still loading --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span data-range class="font-semibold text-gray-700 dark:text-gray-200" x-text="loaded ? rangeText : 'Showing the newest ' + count(Math.min(total, pageRows.length)) + ' of ' + count(total)"></span>
                <span x-show="loaded && filtered" x-text="'Stock value of these: ' + money(values.viewRetail) + (cfg.can.cost ? ' (cost ' + money(values.viewCost) + ')' : '')"></span>
                <button type="button" x-show="filtered" x-on:click="clearFilters()" class="font-semibold text-primary-600 hover:underline">Clear filters</button>
                <span x-show="!loaded && !failed" x-cloak class="inline-flex items-center gap-1.5">
                    <span class="gp-shimmer inline-block h-2 w-16"></span>
                    Getting all <span x-text="count(total)"></span> products ready…
                </span>
                <span x-show="loaded && loading" class="inline-flex items-center gap-1 text-gray-400">
                    <span class="gp-shimmer inline-block h-1.5 w-8"></span> checking for changes
                </span>
                <span x-show="failed" class="font-semibold text-red-600">Could not load the full list. <button type="button" class="underline" x-on:click="refreshNow()">Try again</button></span>
            </div>
        </div>

        {{-- ── Bulk actions ─────────────────────────────────────────────────── --}}
        <div x-show="selectedCount > 0" x-transition x-cloak
            class="sticky top-16 z-10 flex flex-wrap items-center gap-2 rounded-xl border border-primary-300 bg-primary-50 p-3 text-sm shadow-sm dark:border-primary-500/40 dark:bg-gray-900">
            <span class="font-semibold text-gray-900 dark:text-white" x-text="count(selectedCount) + ' selected'"></span>
            <button type="button" x-show="pageSelected && !selectAll && matchCount > selectedCount" x-on:click="selectAll = true"
                class="font-semibold text-primary-700 underline dark:text-primary-400"
                x-text="'Select all ' + count(matchCount) + ' that match'"></button>
            <button type="button" x-on:click="clearSelection()" class="text-gray-500 underline">Clear</button>

            <span class="mx-1 hidden h-5 w-px bg-gray-300 sm:inline-block dark:bg-gray-700"></span>

            <template x-if="cfg.can.edit">
                <div class="flex flex-wrap items-center gap-2">
                    @foreach(['published' => ['Publish', 'publish'], 'draft' => ['Move to draft', 'draft'], 'archived' => ['Archive', 'archive']] as $value => [$label, $busy])
                        <button type="button" x-on:click="bulk('status', '{{ $value }}', '{{ $busy }}')" :disabled="busy"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:opacity-60 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">
                            <svg x-show="busy === '{{ $busy }}'" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/></svg>
                            {{ $label }}
                        </button>
                    @endforeach

                    <div class="flex items-center gap-1">
                        <select x-model="bulkCategory" aria-label="Move to category" class="max-w-[11rem] rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-800">
                            <option value="">Category…</option>
                            @foreach(\App\Models\Category::query()->orderBy('name')->pluck('name', 'id') as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <button type="button" x-on:click="bulk('category_id', bulkCategory, 'category')" :disabled="busy || !bulkCategory"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">
                            <svg x-show="busy === 'category'" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/></svg>
                            Move
                        </button>
                    </div>

                    <div class="flex items-center gap-1">
                        <input type="number" min="0" x-model="bulkLowAt" placeholder="Low below…" aria-label="Count as low stock below"
                            class="w-28 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <button type="button" x-on:click="bulk('low_stock_threshold', bulkLowAt, 'low')" :disabled="busy || bulkLowAt === ''"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">
                            <svg x-show="busy === 'low'" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/></svg>
                            Set
                        </button>
                    </div>
                </div>
            </template>

            <button type="button" x-show="cfg.can.export" x-on:click="bulkExport()" :disabled="busy"
                class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:opacity-60 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">
                <svg x-show="busy === 'export'" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/></svg>
                <x-heroicon-o-arrow-down-tray x-show="busy !== 'export'" class="h-4 w-4"/> Export
            </button>
            <button type="button" x-show="cfg.can.delete" x-on:click="bulkDelete()" :disabled="busy"
                class="inline-flex items-center gap-1.5 rounded-lg bg-red-50 px-3 py-1.5 font-semibold text-red-700 hover:bg-red-100 disabled:opacity-60 dark:bg-red-900/20 dark:text-red-400">
                <svg x-show="busy === 'delete'" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/></svg>
                <x-heroicon-o-trash x-show="busy !== 'delete'" class="h-4 w-4"/> Delete
            </button>
        </div>

        {{-- ── The first page, drawn by the server ─────────────────────────
             Shows before any script has loaded; the list below takes over the
             moment it starts (x-show="false" hides this then). --}}
        @php
            $products = $this->getProducts();
            $canSeeCostPrice = $this->canSeeCostPrice();
            $selected = [];
            $allOnPageSelected = false;
        @endphp
        <div x-show="false" data-first-page>
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

        </div>

        {{-- ── Waiting for the whole list (a filter set before it arrived) ──── --}}
        <div x-show="waitingForAll" x-cloak class="space-y-2 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900" aria-live="polite">
            <p class="text-sm text-gray-500">Getting all products ready, so the filter covers every one of them…</p>
            <template x-for="i in 8" :key="i">
                <div class="flex items-center gap-3 py-2">
                    <div class="gp-shimmer h-11 w-11"></div>
                    <div class="flex-1 space-y-2"><div class="gp-shimmer h-3 w-2/5"></div><div class="gp-shimmer h-2.5 w-1/4"></div></div>
                    <div class="gp-shimmer h-3 w-20"></div>
                    <div class="gp-shimmer h-3 w-24"></div>
                </div>
            </template>
        </div>

        <div x-show="!waitingForAll" x-cloak>
            {{-- The rows (table, grid or phone cards) are drawn by the script in
                 one go (products-list.js, drawRows): Alpine binding thousands of
                 cells one by one was too slow for 200-row pages. Clicks inside
                 are handled here once, for every row. --}}
            <div x-ref="rows" x-on:click="onRowsClick($event)" x-on:change="onRowsChange($event)"></div>

            {{-- Nothing matches --}}
            <div x-show="pageRows.length === 0" class="rounded-xl border border-dashed border-gray-300 py-12 text-center dark:border-white/10">
                <p class="text-sm text-gray-500" x-text="filtered ? 'No products match these filters.' : 'No products yet.'"></p>
                <button type="button" x-show="filtered" x-on:click="clearFilters()" class="mt-2 text-sm font-semibold text-primary-600 hover:underline">Clear filters</button>
            </div>

            {{-- ── Pages ────────────────────────────────────────────────────── --}}
            <nav x-show="pages > 1" class="mt-4 flex flex-wrap items-center justify-between gap-2" aria-label="Pages">
                <span class="text-sm text-gray-500" x-text="rangeText"></span>
                <div class="flex items-center gap-1">
                    <button type="button" x-on:click="page = Math.max(1, page - 1)" :disabled="page === 1"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:opacity-40 dark:text-gray-200 dark:ring-gray-700">‹ Prev</button>
                    <template x-for="p in pageList" :key="p">
                        <button type="button" x-on:click="typeof p === 'number' && (page = p)" :disabled="typeof p !== 'number'"
                            :class="p === page ? 'bg-primary-500 text-white ring-primary-500' : 'text-gray-700 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700'"
                            class="min-w-[2.25rem] rounded-lg px-2 py-1.5 text-sm font-medium ring-1" x-text="typeof p === 'number' ? p : '…'"></button>
                    </template>
                    <button type="button" x-on:click="page = Math.min(pages, page + 1)" :disabled="page === pages"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:opacity-40 dark:text-gray-200 dark:ring-gray-700">Next ›</button>
                </div>
            </nav>
        </div>
    </div>

    {{-- This page renders its own list instead of a Filament table, so the
         page layout (which skips modals on HasTable pages) never outputs
         them. Without this the header actions mount but their modals have
         nowhere to render — Export and Take a tour appear to do nothing. --}}
    <x-filament-actions::modals />

    {{-- New product and the row pencils open this, with no page load. --}}
    @include('filament.vendor.products.quick-form')
</x-filament-panels::page>
