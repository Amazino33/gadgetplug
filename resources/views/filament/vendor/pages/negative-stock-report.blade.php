<x-filament-panels::page>

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6 pb-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Products Below Zero</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Goods sold before the records said they had arrived. A delivery or a stock count settles each one.
            </p>
        </div>

        @if ($rows->isEmpty())
            <div class="px-6 pb-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Nothing is below zero.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left border-t border-gray-100 dark:border-white/10">
                    <thead>
                        <tr class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <th class="px-6 py-3">Product</th>
                            <th class="px-4 py-3">Branch</th>
                            <th class="px-4 py-3 text-right">Quantity Owed</th>
                            <th class="px-4 py-3">Last Sale</th>
                            <th class="px-6 py-3 text-right">Sales With Estimated Cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <tr class="text-sm">
                                <td class="px-6 py-3">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $row->name }}</p>
                                    @if ($row->sku)
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $row->sku }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $row->store_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-red-600 dark:text-red-400">
                                    {{ number_format(abs((int) $row->quantity)) }} {{ $row->base_unit ?: 'Piece' }}
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                    {{ $row->last_sold_at ? \Illuminate\Support\Carbon::parse($row->last_sold_at)->format('d M Y, H:i') : '—' }}
                                </td>
                                <td class="px-6 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($estimatedCounts[$row->product_id] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="fi-section mt-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6 pb-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Sales With Estimated Cost</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                These sold more than was on record, so there were no purchase batches to cost them from. The product's cost price at the time was used. Latest 50.
            </p>
        </div>

        @if ($estimatedSales->isEmpty())
            <div class="px-6 pb-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">None yet.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left border-t border-gray-100 dark:border-white/10">
                    <thead>
                        <tr class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <th class="px-6 py-3">Sale</th>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3 text-right">Quantity</th>
                            <th class="px-4 py-3 text-right">Sold For</th>
                            <th class="px-6 py-3 text-right">Estimated Cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($estimatedSales as $sale)
                            <tr class="text-sm">
                                <td class="px-6 py-3">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $sale->reference }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ $sale->completed_at ? \Illuminate\Support\Carbon::parse($sale->completed_at)->format('d M Y, H:i') : '' }}</p>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $sale->product_name }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">
                                    @if ($sale->unit_qty !== null && (int) $sale->unit_qty !== (int) $sale->quantity)
                                        {{ $sale->unit_qty }} {{ $sale->unit_name }} ({{ $sale->quantity }})
                                    @else
                                        {{ $sale->quantity }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">₦{{ number_format((float) $sale->total, 2) }}</td>
                                <td class="px-6 py-3 text-right text-gray-700 dark:text-gray-300">{{ $sale->cost_total !== null ? '₦' . number_format((float) $sale->cost_total, 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</x-filament-panels::page>
