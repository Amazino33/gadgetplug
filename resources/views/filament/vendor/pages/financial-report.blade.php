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
            Every cost below only counts once you've actually paid it — not the day it happened.
            Revenue only counts orders you've marked "Delivered" (or, for online prepaid orders, marked "Paid") — so if you forget to mark something delivered, this number can come in lower than what you really made, but it will never show more than you actually earned.
        </p>
    </div>

    @if ($report['cost_is_estimated'])
        <div class="mt-4 rounded-xl bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300">
            Some of what sold in this period was recorded before this system started saving the exact cost at the moment of sale, so today's cost price was used as a stand-in for those. The profit figure above is partly approximate because of this — anything sold from now on will be exact.
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
                ['Money In (Revenue)', $report['revenue'], false],
                ['Cost of Goods Sold', -$report['product_cost'], true],
                ['Transport — Getting Stock In', -$report['inbound_logistics'], true],
                ['Delivery — Getting Orders Out', -$report['outbound_delivery'], true],
                ['Advertising', -$report['advertising'], true],
                ['Other Transport Costs', -$report['other_logistics'], true],
                ['Other Costs', -$report['other_expenses'], true],
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
        <p class="text-sm text-gray-500 dark:text-gray-400">Where things stand right now, as of the end of the period above — a snapshot, separate from the profit figures above it.</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Bank', $report['balances']['bank']],
                ['Cash', $report['balances']['cash']],
                ['Total Cash + Bank', $report['balances']['total_liquid']],
                ['Value of Stock on Hand', $report['balances']['inventory_value']],
                ['Initial Capital (what you started with)', $report['balances']['initial_capital']],
                ['All-Time Profit', $report['balances']['cumulative_profit']],
            ] as [$label, $value])
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">₦{{ number_format($value, 2) }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-4 rounded-lg bg-gray-50 p-3 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
            "Total worth" here means your Bank + Cash balances plus the value of the stock you're currently holding. It does NOT include money customers still owe you, money you owe suppliers, or anything you've personally taken out of the business — none of that is tracked yet.
        </div>
    </div>

</x-filament-panels::page>
