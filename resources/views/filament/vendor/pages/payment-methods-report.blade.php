<x-filament-panels::page>

    {{ $this->form }}

    @php
        $received = $payments['sales']['cash'] + $payments['sales']['card'] + $payments['sales']['bank_transfer'];
        $money = fn (float $amount): string => '₦' . number_format($amount, 2);
    @endphp

    @if ($storeName)
        <div class="mt-6 flex items-center gap-2 rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 ring-1 ring-primary-600/10 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-400/20">
            <x-filament::icon icon="heroicon-m-building-storefront" class="h-5 w-5 shrink-0" />
            <span>Showing <span class="font-semibold">{{ $storeName }}</span> only. Every figure below covers this branch.</span>
        </div>
    @endif

    {{-- Headline figures --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Received from sales', $money($received), 'cash, card and transfer'],
            ['Sold on credit', $money($payments['sales']['debt']), 'still owed, not received'],
            ['Debts repaid', $money(array_sum($payments['repayments'])), 'old credit paid back'],
            ['Refunded', $money(array_sum($payments['refunds'])), 'returned to customers'],
        ] as [$label, $value, $note])
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
            </div>
        @endforeach
    </div>

    @include('filament.vendor.partials.payment-methods-breakdown', ['payments' => $payments])

    {{-- Day by day --}}
    <div class="fi-section mt-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6 pb-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Day by day</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Each trading day in {{ strtolower($period->label) }}. Days with no shop sales, repayments or refunds are left out.
            </p>
        </div>

        @if ($days->isEmpty())
            <p class="px-6 pb-6 text-sm text-gray-500 dark:text-gray-400">Nothing in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full whitespace-nowrap text-sm">
                    <thead class="border-y border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium">Date</th>
                            <th class="px-4 py-3 text-right font-medium">Cash</th>
                            <th class="px-4 py-3 text-right font-medium">Card (POS)</th>
                            <th class="px-4 py-3 text-right font-medium">Transfer</th>
                            <th class="px-4 py-3 text-right font-medium">On credit</th>
                            <th class="px-4 py-3 text-right font-medium">Total sales</th>
                            <th class="px-4 py-3 text-right font-medium">Debts repaid</th>
                            <th class="px-6 py-3 text-right font-medium">Refunds</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($days as $day)
                            <tr>
                                <td class="px-6 py-3 text-gray-950 dark:text-white">
                                    {{ \Carbon\CarbonImmutable::parse($day['date'])->format('D j M Y') }}
                                </td>
                                @foreach ($day['sales'] as $amount)
                                    <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ $amount > 0 ? $money($amount) : '—' }}</td>
                                @endforeach
                                <td class="px-4 py-3 text-right font-semibold text-gray-950 dark:text-white">{{ $money(array_sum($day['sales'])) }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ $day['repayments'] > 0 ? $money($day['repayments']) : '—' }}</td>
                                <td class="px-6 py-3 text-right text-gray-700 dark:text-gray-300">{{ $day['refunds'] > 0 ? $money($day['refunds']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-gray-200 font-semibold text-gray-950 dark:border-white/10 dark:text-white">
                        <tr>
                            <td class="px-6 py-3">Total</td>
                            @foreach ($payments['sales'] as $amount)
                                <td class="px-4 py-3 text-right">{{ $money($amount) }}</td>
                            @endforeach
                            <td class="px-4 py-3 text-right">{{ $money(array_sum($payments['sales'])) }}</td>
                            <td class="px-4 py-3 text-right">{{ $money(array_sum($payments['repayments'])) }}</td>
                            <td class="px-6 py-3 text-right">{{ $money(array_sum($payments['refunds'])) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

</x-filament-panels::page>
