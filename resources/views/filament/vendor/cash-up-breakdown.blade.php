{{--
    The working behind a cash-up.

    A cashier told only "you are short ₦5,000" has no way to check the claim, and
    the first thing anybody asks is why the drawer does not simply equal the day's
    sales. So the lines that produced each expected figure are shown in the order
    they were applied, summing to the figure the cashier was held to.

    Read entirely from the snapshot frozen at close, never recomputed: this is
    what the system claimed at the moment somebody was held to it, and a sale
    syncing afterwards must not quietly rewrite it.
--}}
@php
    $breakdown = $session->breakdown ?? [];
    $context = $breakdown['context'] ?? [];
    $warnings = $breakdown['warnings'] ?? [];

    $money = fn ($amount) => '₦' . number_format((float) $amount, 2);
    $signed = fn ($amount) => ((float) $amount < 0 ? '−' : '') . '₦' . number_format(abs((float) $amount), 2);
@endphp

<div class="space-y-6 text-sm">

    @if (! empty($warnings))
        <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 dark:border-warning-700 dark:bg-warning-900/30">
            <p class="font-semibold text-warning-800 dark:text-warning-200">Read this before trusting the figures</p>
            <ul class="mt-1 list-disc space-y-1 pl-5 text-warning-700 dark:text-warning-300">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 sm:grid-cols-2">
        @foreach ([
            ['Cash drawer', $breakdown['cash_lines'] ?? [], $session->counted_cash, $session->expected_cash, $session->resolvedCashVariance()],
            ['Moniepoint terminal', $breakdown['terminal_lines'] ?? [], $session->counted_terminal, $session->expected_terminal, $session->resolvedTerminalVariance()],
        ] as [$heading, $lines, $counted, $expected, $resolved])
            <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                <p class="border-b border-gray-200 px-3 py-2 font-semibold dark:border-gray-700">{{ $heading }}</p>

                <table class="w-full">
                    <tbody>
                        @forelse ($lines as $line)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="px-3 py-1.5 text-gray-600 dark:text-gray-400">{{ $line['label'] }}</td>
                                <td @class([
                                    'px-3 py-1.5 text-right tabular-nums',
                                    'text-danger-600 dark:text-danger-400' => (float) $line['amount'] < 0,
                                ])>{{ $signed($line['amount']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="px-3 py-1.5 text-gray-500">Nothing taken on this tender.</td>
                            </tr>
                        @endforelse

                        <tr class="border-t border-gray-300 font-semibold dark:border-gray-600">
                            <td class="px-3 py-1.5">Should have been</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ $money($expected) }}</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-1.5">Actually counted</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ $money($counted) }}</td>
                        </tr>
                        <tr @class([
                            'font-bold',
                            'text-danger-600 dark:text-danger-400' => $resolved < -0.009,
                            'text-warning-600 dark:text-warning-400' => $resolved > 0.009,
                        ])>
                            <td class="px-3 py-1.5">
                                {{ abs($resolved) < 0.01 ? 'Balances' : ($resolved < 0 ? 'Still missing' : 'Still over') }}
                            </td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ $money(abs($resolved)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    {{--
        Why the drawer does not equal the day's sales. Credit is nearly always
        the answer, and without it on the screen the figures simply look wrong.
    --}}
    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
        <p class="mb-2 font-semibold">The day, for context</p>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
            @foreach ([
                'Sales rung' => $context['sales_count'] ?? 0,
                'Gross sales' => $money($context['gross_sales'] ?? 0),
                'Sold on credit' => $money($context['debt_rung'] ?? 0),
                'Refunded as store credit' => $money($context['store_credit_refunds'] ?? 0),
            ] as $label => $value)
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="font-medium tabular-nums">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if (($context['debt_rung'] ?? 0) > 0)
            <p class="mt-2 text-gray-500 dark:text-gray-400">
                Money sold on credit is in nobody's hands, so it is not counted in either total above.
            </p>
        @endif
    </div>

    @if (filled($session->notes))
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <p class="font-semibold">What the cashier said</p>
            <p class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-400">{{ $session->notes }}</p>
        </div>
    @endif

    @if ($session->rectifications->isNotEmpty())
        <div class="rounded-lg border border-gray-200 dark:border-gray-700">
            <p class="border-b border-gray-200 px-3 py-2 font-semibold dark:border-gray-700">
                What has been accounted for
            </p>
            <table class="w-full">
                <tbody>
                    @foreach ($session->rectifications as $entry)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="px-3 py-2">
                                <span class="font-medium">
                                    {{ match ($entry->kind) {
                                        'expense' => 'Spent out of the drawer',
                                        'cash_out' => 'Cash handed over',
                                        'tender_reclass' => 'Rung on the wrong tender',
                                        'debt_paid' => 'Credit sale actually paid',
                                        default => $entry->kind,
                                    } }}
                                </span>
                                @if ($entry->relatedSale)
                                    <span class="text-gray-500">— {{ $entry->relatedSale->reference }}</span>
                                @endif
                                @if (filled($entry->note))
                                    <p class="text-gray-500 dark:text-gray-400">{{ $entry->note }}</p>
                                @endif
                                <p class="text-xs text-gray-400">
                                    {{ $entry->creator?->name ?? 'Unknown' }},
                                    {{ $entry->created_at?->format('d M Y, g:ia') }}
                                </p>
                            </td>
                            <td class="px-3 py-2 text-right align-top tabular-nums">{{ $money($entry->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @php
        $expenses = \App\Models\Expense::query()
            ->where('vendor_id', $session->vendor_id)
            ->where('store_id', $session->store_id)
            ->where('created_by', $session->cashier_id)
            ->whereNotNull('posted_at')
            ->whereDate('incurred_at', $session->business_date)
            ->get();

        $pickingPayments = \App\Models\PickingLedgerEntry::query()
            ->where('vendor_id', $session->vendor_id)
            ->where('user_id', $session->cashier_id)
            ->where('direction', 'payment')
            ->whereDate('created_at', $session->business_date)
            ->with('item.picking.picker', 'item.product')
            ->get();
    @endphp

    @if ($expenses->isNotEmpty())
        <div class="rounded-lg border border-gray-200 dark:border-gray-700">
            <p class="border-b border-gray-200 bg-gray-50 px-3 py-2 font-semibold dark:border-gray-700 dark:bg-gray-800/50">
                Expenses Paid Out of Drawer
            </p>
            <table class="w-full">
                <tbody>
                    @foreach ($expenses as $expense)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ \Illuminate\Support\Str::headline($expense->category) }}</span>
                                @if (filled($expense->description))
                                    <p class="text-gray-500 dark:text-gray-400">{{ $expense->description }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right align-top tabular-nums text-danger-600 dark:text-danger-400">
                                {{ $signed(-$expense->amount) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($pickingPayments->isNotEmpty())
        <div class="rounded-lg border border-gray-200 dark:border-gray-700">
            <p class="border-b border-gray-200 bg-gray-50 px-3 py-2 font-semibold dark:border-gray-700 dark:bg-gray-800/50">
                Picker Hand-overs (Payments Received)
            </p>
            <table class="w-full">
                <tbody>
                    @foreach ($pickingPayments as $payment)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ $payment->item?->picking?->picker?->name ?? 'Unknown Picker' }}</span>
                                <span class="text-gray-500">— {{ $payment->item?->product?->name ?? 'Unknown Product' }}</span>
                                @if (filled($payment->note))
                                    <p class="text-gray-500 dark:text-gray-400">{{ $payment->note }}</p>
                                @endif
                                <p class="text-xs text-gray-400">
                                    {{ $payment->quantity }} unit(s) @ {{ $money($payment->unit_price) }}
                                </p>
                            </td>
                            <td class="px-3 py-2 text-right align-top tabular-nums">
                                {{ $money($payment->amount) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
