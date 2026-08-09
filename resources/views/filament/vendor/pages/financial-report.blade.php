<x-filament-panels::page>

    {{ $this->form }}

    @php
        $profitPositive = $report['net_profit'] >= 0;
    @endphp

    {{-- Headline --}}
    <div class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <p class="text-sm text-gray-500 dark:text-gray-400">Net Profit — {{ $period->label }}</p>
        <p @class([
            'mt-1 text-4xl font-bold tracking-tight',
            'text-success-600 dark:text-success-400' => $profitPositive,
            'text-danger-600 dark:text-danger-400' => ! $profitPositive,
        ])>₦{{ number_format($report['net_profit'], 2) }}</p>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Cash-basis: every cost below counts in the period it was actually paid, not when it was incurred or recorded.
            Revenue depends on orders being marked delivered — an order that's genuinely delivered but not yet marked so is not counted here, so this figure can undercount but never overcount.
        </p>
    </div>

    @if ($report['cost_is_estimated'])
        <div class="mt-4 rounded-xl bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300">
            Some items sold in this period have no recorded cost at the time of sale, so their product cost is calculated from the product's current cost price instead. The profit figure above is partly approximate.
        </div>
    @endif

    {{-- P&L breakdown --}}
    <div class="fi-section mt-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6 pb-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Profit &amp; Loss</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $period->label }} — {{ $period->from->format('d M Y') }} to {{ $period->to->format('d M Y') }}</p>
        </div>

        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ([
                ['Revenue', $report['revenue'], false],
                ['Product cost', -$report['product_cost'], true],
                ['Inbound logistics', -$report['inbound_logistics'], true],
                ['Outbound delivery', -$report['outbound_delivery'], true],
                ['Advertising', -$report['advertising'], true],
                ['Other logistics', -$report['other_logistics'], true],
                ['Other expenses', -$report['other_expenses'], true],
            ] as [$label, $amount, $isSubtraction])
                <div class="flex items-center justify-between px-6 py-3">
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ $label }}</span>
                    <span @class([
                        'text-sm font-medium',
                        'text-gray-950 dark:text-white' => ! $isSubtraction,
                        'text-danger-600 dark:text-danger-400' => $isSubtraction && $amount != 0,
                        'text-gray-400 dark:text-gray-500' => $isSubtraction && $amount == 0,
                    ])>
                        {{ $amount < 0 ? '−' : '' }}₦{{ number_format(abs($amount), 2) }}
                    </span>
                </div>
            @endforeach

            <div class="flex items-center justify-between bg-gray-50 px-6 py-4 dark:bg-white/5">
                <span class="text-sm font-bold text-gray-950 dark:text-white">Net Profit</span>
                <span @class([
                    'text-sm font-bold',
                    'text-success-600 dark:text-success-400' => $profitPositive,
                    'text-danger-600 dark:text-danger-400' => ! $profitPositive,
                ])>₦{{ number_format($report['net_profit'], 2) }}</span>
            </div>
        </div>
    </div>

    {{-- Balances — current state, not part of the P&L subtraction above --}}
    <div class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Balances</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">As of the end of the selected period — a snapshot, not part of the profit calculation above.</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Bank', $report['balances']['bank']],
                ['Cash', $report['balances']['cash']],
                ['Total Liquid', $report['balances']['total_liquid']],
                ['Inventory Value', $report['balances']['inventory_value']],
                ['Initial Capital', $report['balances']['initial_capital']],
                ['Cumulative Profit (all time)', $report['balances']['cumulative_profit']],
            ] as [$label, $value])
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">₦{{ number_format($value, 2) }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-4 rounded-lg bg-gray-50 p-3 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
            "Total worth" here = liquid balances (Bank + Cash) + inventory value. It does not include money owed to or by the business, or owner drawings — there is no accounts receivable/payable ledger yet.
        </div>
    </div>

</x-filament-panels::page>
