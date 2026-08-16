<x-filament-panels::page>
    @php
        $data = $this->comparison();
        $rows = $data['rows'];
        $totals = $data['totals'];
        $showCost = $this->canSeeCostValue();
        $unattributed = $this->unattributedSales();
    @endphp

    @if (empty($rows))
        <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center">
            <p class="font-semibold text-gray-900 dark:text-white">No stores to compare yet.</p>
        </div>
    @else
        {{-- The whole-business line first: what every branch is holding, together. --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-4">
                <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Stock value (retail)</p>
                <p class="mt-1 font-montserrat text-xl font-bold text-gray-900 dark:text-white">₦{{ number_format($totals['retail_value'], 2) }}</p>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ number_format($totals['units']) }} units across {{ count($rows) }} {{ Str::plural('store', count($rows)) }}</p>
            </div>

            @if ($showCost)
                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-4">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Stock value (cost)</p>
                    <p class="mt-1 font-montserrat text-xl font-bold text-gray-900 dark:text-white">₦{{ number_format($totals['cost_value'], 2) }}</p>
                    @if ($totals['missing_cost_count'] > 0)
                        <p class="text-[11px] font-medium text-amber-600 dark:text-amber-400">
                            Excludes {{ $totals['missing_cost_count'] }} {{ Str::plural('product', $totals['missing_cost_count']) }} with no cost price
                        </p>
                    @endif
                </div>
            @endif

            <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-4">
                <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Sold — {{ $this->periodLabel() }}</p>
                <p class="mt-1 font-montserrat text-xl font-bold text-gray-900 dark:text-white">₦{{ number_format($totals['sales_revenue'], 2) }}</p>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ number_format($totals['sales_units']) }} units</p>
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-4">
                <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Low stock</p>
                <p class="mt-1 font-montserrat text-xl font-bold {{ $totals['low_stock_count'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white' }}">
                    {{ number_format($totals['low_stock_count']) }}
                </p>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">lines needing a top-up</p>
            </div>
        </div>

        @if ($unattributed > 0)
            {{-- Honest rather than tidy: sales whose fulfilling branch was never
                 recorded belong to no column below, so the rows sum to less than
                 the whole-business figure above. --}}
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                ₦{{ number_format($unattributed, 2) }} of sales in this period is not attributed to any store, so the rows below sum to less than the total above.
            </p>
        @endif

        <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Store</th>
                        <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Sold ({{ $this->periodLabel() }})</th>
                        <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Value (retail)</th>
                        @if ($showCost)
                            <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Value (cost)</th>
                        @endif
                        <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Products</th>
                        <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Low stock</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $row)
                        <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="px-4 py-3">
                                <p class="font-montserrat text-sm font-bold text-gray-900 dark:text-white">{{ $row['store']->name }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ $row['store']->is_default ? 'Default' : 'Branch' }}@unless ($row['store']->is_active) · inactive @endunless
                                </p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <p class="font-montserrat text-sm font-bold text-gray-900 dark:text-white">₦{{ number_format($row['sales_revenue'], 2) }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ number_format($row['sales_units']) }} units</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-900 dark:text-white">₦{{ number_format($row['retail_value'], 2) }}</td>
                            @if ($showCost)
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-900 dark:text-white">
                                    ₦{{ number_format($row['cost_value'], 2) }}
                                    @if ($row['missing_cost_count'] > 0)
                                        <span class="block text-[11px] font-medium text-amber-600 dark:text-amber-400">−{{ $row['missing_cost_count'] }} uncosted</span>
                                    @endif
                                </td>
                            @endif
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-900 dark:text-white">{{ number_format($row['product_count']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $row['low_stock_count'] > 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white' }}">
                                {{ number_format($row['low_stock_count']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
