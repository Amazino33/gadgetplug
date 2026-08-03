<x-filament-panels::page>

    {{ $this->form }}

    @php
        $delta = function (float $now, float $before): array {
            if ($before <= 0.0) {
                return ['label' => $now > 0 ? 'no comparison' : '—', 'positive' => true];
            }
            $change = (($now - $before) / $before) * 100;
            return [
                'label' => ($change >= 0 ? '+' : '') . number_format($change, 1) . '% vs previous',
                'positive' => $change >= 0,
            ];
        };

        $revenueDelta = $delta((float) $summary['revenue'], (float) $previous['revenue']);
        $profitDelta  = $delta((float) $summary['profit'], (float) $previous['profit']);
        $totalChannel = array_sum($channels);
    @endphp

    {{-- Headline figures --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mt-6">
        @foreach ([
            ['Revenue', '₦' . number_format($summary['revenue'], 2), $revenueDelta['label'], $revenueDelta['positive']],
            ['Profit', '₦' . number_format($summary['profit'], 2), $profitDelta['label'], $profitDelta['positive']],
            ['Margin', number_format($summary['margin'], 1) . '%', $summary['cost_is_estimated'] ? 'partly estimated' : 'of revenue', true],
            ['Sales', number_format($summary['orders']), $summary['units'] . ' items sold', true],
        ] as [$label, $value, $note, $positive])
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ $value }}</p>
                <p @class([
                    'mt-1 text-xs',
                    'text-success-600 dark:text-success-400' => $positive,
                    'text-danger-600 dark:text-danger-400' => ! $positive,
                ])>{{ $note }}</p>
            </div>
        @endforeach
    </div>

    @if ($summary['cost_is_estimated'])
        <div class="mt-4 rounded-xl bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300">
            Some items in this period sold before per-sale cost tracking began, so their profit is
            calculated from the product's current cost price. Figures for periods after this change are exact.
        </div>
    @endif

    {{-- Where the money came from --}}
    <div class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Sales channels</h3>

        @if ($totalChannel <= 0)
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No sales in this period.</p>
        @else
            <div class="mt-4 space-y-3">
                @foreach ($channels as $channel => $amount)
                    @php $share = $totalChannel > 0 ? ($amount / $totalChannel) * 100 : 0; @endphp
                    <div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-700 dark:text-gray-300">{{ $channel }}</span>
                            <span class="font-semibold text-gray-950 dark:text-white">
                                ₦{{ number_format($amount, 2) }}
                                <span class="ml-1 text-xs font-normal text-gray-500">({{ number_format($share, 1) }}%)</span>
                            </span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div class="h-full rounded-full {{ $channel === 'Online' ? 'bg-blue-500' : 'bg-emerald-500' }}"
                                 style="width: {{ $share }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @php
            // Revenue only counts 'paid'/'delivered' orders — everything else
            // placed in this period is real activity that just hasn't earned
            // yet. Shown here so ₦0 online revenue is explained, not mysterious.
            $notYetEarned = collect($onlineStatuses)
                ->except(['paid', 'delivered'])
                ->filter(fn ($count) => $count > 0);
        @endphp
        @if ($notYetEarned->isNotEmpty())
            <div class="mt-4 rounded-lg bg-gray-50 p-3 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                Also placed online this period, not yet counted as revenue:
                {{ $notYetEarned->map(fn ($count, $status) => $count . ' ' . str_replace('_', ' ', $status))->implode(', ') }}.
            </div>
        @endif
    </div>

    {{-- Who sold it — every cashier's POS contribution --}}
    <div class="fi-section mt-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6 pb-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Sales by team member</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">POS sales only — an online order has no cashier.</p>
        </div>

        @if ($cashiers->isEmpty())
            <p class="px-6 pb-6 text-sm text-gray-500 dark:text-gray-400">No POS sales in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-y border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium">Cashier</th>
                            <th class="px-6 py-3 text-right font-medium">Sales</th>
                            <th class="px-6 py-3 text-right font-medium">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($cashiers as $cashier)
                            <tr>
                                <td class="px-6 py-3 text-gray-950 dark:text-white">{{ $cashier['cashier_name'] }}</td>
                                <td class="px-6 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($cashier['orders']) }}</td>
                                <td class="px-6 py-3 text-right font-medium text-gray-950 dark:text-white">₦{{ number_format($cashier['revenue'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Best sellers --}}
    <div class="fi-section mt-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6 pb-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Top products</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">Ranked by revenue across both channels</p>
        </div>

        @if ($topProducts->isEmpty())
            <p class="px-6 pb-6 text-sm text-gray-500 dark:text-gray-400">Nothing sold in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-y border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium">Product</th>
                            <th class="px-6 py-3 text-right font-medium">Units</th>
                            <th class="px-6 py-3 text-right font-medium">Revenue</th>
                            <th class="px-6 py-3 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($topProducts as $product)
                            <tr>
                                <td class="px-6 py-3 text-gray-950 dark:text-white">{{ $product['name'] }}</td>
                                <td class="px-6 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($product['units']) }}</td>
                                <td class="px-6 py-3 text-right font-medium text-gray-950 dark:text-white">₦{{ number_format($product['revenue'], 2) }}</td>
                                <td @class([
                                    'px-6 py-3 text-right font-medium',
                                    'text-success-600 dark:text-success-400' => $product['profit'] >= 0,
                                    'text-danger-600 dark:text-danger-400' => $product['profit'] < 0,
                                ])>₦{{ number_format($product['profit'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</x-filament-panels::page>
