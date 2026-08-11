<x-filament-panels::page>

    {{ $this->form }}

    <div class="fi-section mt-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6 pb-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">What Needs Restocking</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Based on the last {{ $windowDays }} days of sales, a {{ $leadTimeDays }}-day supplier lead time, and restocking to {{ $targetCoverDays }} days of cover.
                Sorted most urgent first. Change these under "Restock Settings" above.
            </p>
        </div>

        @if ($rows->isEmpty())
            <div class="px-6 pb-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Nothing needs attention right now — try "Show healthy & dead stock too" to see the full picture, or adjust your filters.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left border-t border-gray-100 dark:border-white/10">
                    <thead>
                        <tr class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <th class="px-6 py-3">Product</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3 text-right">Stock</th>
                            <th class="px-4 py-3 text-right">Daily Sales</th>
                            <th class="px-4 py-3 text-right">Days Left</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Reorder Qty</th>
                            <th class="px-6 py-3 text-right">Est. Cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($rows as $row)
                            @php
                                $result = $row['result'];
                                $product = $row['product'];
                                $estimatedCost = $result->reorderQuantity * (float) ($product->cost_price ?? 0);
                                $colorClasses = match ($result->color()) {
                                    'danger'  => 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400',
                                    'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400',
                                    'success' => 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400',
                                    'info'    => 'bg-info-50 text-info-700 dark:bg-info-400/10 dark:text-info-400',
                                    default   => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
                                };
                            @endphp
                            <tr class="text-sm">
                                <td class="px-6 py-3">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $product->name }}</p>
                                    @if ($product->sku)
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $product->sku }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $product->category?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($result->currentStock) }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($result->dailyVelocity, 2) }}/day</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">
                                    {{ $result->daysOfCover !== null ? number_format($result->daysOfCover, 1) : '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $colorClasses }}">
                                        {{ $result->label() }}
                                    </span>
                                    @if ($result->daysOutOfStock !== null && $result->daysOutOfStock > 0)
                                        <span class="block mt-1 text-xs text-gray-400 dark:text-gray-500">Out of stock {{ $result->daysOutOfStock }}d in window</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">
                                    {{ $result->reorderQuantity > 0 ? number_format($result->reorderQuantity) : '—' }}
                                </td>
                                <td class="px-6 py-3 text-right text-gray-700 dark:text-gray-300">
                                    {{ $result->reorderQuantity > 0 ? '₦' . number_format($estimatedCost, 2) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</x-filament-panels::page>
