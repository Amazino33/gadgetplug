{{--
    The Account Close, on screen.

    Two columns, one question: the value that left the shelf against every place
    that value could have gone. What does not appear on the right is the gap,
    and because card, transfer and credit sit on both sides and cancel, the only
    thing that can actually be missing is cash.

    The stock check sits underneath rather than beside, because it answers a
    different question and must not be read as part of the money. A cash
    reconciliation balances perfectly when goods walk out unrecorded — expected
    cash is worked out from records that were never made — so counting the goods
    is the only thing that sees it.
--}}
<x-filament-panels::page>

    {{ $this->form }}

    @if (! $store || ! $view)
        <div class="mt-6 rounded-xl bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300">
            Pick a branch to close. Nothing here applies to a whole business at once — stock sits on a shelf and cash is held by a person, both at a place.
        </div>
    @else
        @php
            $balance  = $view['balance'];
            $check    = $view['checkmate'];
            $subs     = $view['submissions'];
            $variance = $view['count_variance'];
            $opening  = $view['opening'];
            $procure  = $view['procurement'];
            $gap      = (float) $balance['shortage'];
            // Sign before the symbol — "₦-5,000.00" reads as a typo.
            $money    = fn ($v) => ((float) $v < 0 ? '-₦' : '₦') . number_format(abs((float) $v), 2);
        @endphp

        {{-- The number the whole page is about. --}}
        <div class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $gap < -0.009 ? 'Overage' : 'Shortage' }} — {{ $store->name }},
                {{ $period['from']->format('d M Y') }} to {{ $period['to']->format('d M Y') }}
            </p>
            <p @class([
                'mt-1 text-4xl font-bold tracking-tight',
                'text-danger-600 dark:text-danger-400' => $gap > 0.009,
                'text-success-600 dark:text-success-400' => $gap <= 0.009,
            ])>{{ $money(abs($gap)) }}</p>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                @if ($gap > 0.009)
                    Value sold that has not turned up anywhere on the right. Only cash can actually go missing — everything else appears on both sides and cancels.
                @elseif ($gap < -0.009)
                    More came back than the tills took. Worth explaining before this is closed.
                @else
                    Every naira sold is accounted for on the right.
                @endif
            </p>
        </div>

        @unless ($check['agrees'])
            <div class="mt-4 rounded-xl bg-danger-50 p-4 text-sm text-danger-800 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-300">
                <p class="font-semibold">These two sides do not reduce to the cash gap.</p>
                <p class="mt-1">
                    The balance shows {{ $money($gap) }}; Checkmate's cash reconciliation shows
                    {{ $money($check['cash_shortage']) }}, a difference of {{ $money($check['difference']) }}.
                    Something upstream is not what this arithmetic assumes — do not put this figure to anybody until it is understood.
                </p>
            </div>
        @endunless

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            {{-- LEFT --}}
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Value sold</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Charged to customers</dt><dd class="font-mono">{{ $money($balance['gross_sales']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Refunded</dt><dd class="font-mono">({{ $money($balance['refunds']) }})</dd></div>
                    <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold dark:border-gray-700"><dt>Value sold</dt><dd class="font-mono">{{ $money($balance['value_sold']) }}</dd></div>
                </dl>
                {{-- Said here because somebody will compare this against the
                     trading report within a week and think one of them is broken. --}}
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    What the customer actually paid, VAT included, across every tender, from this branch's till only. The trading reports exclude VAT because it is collected for the government rather than earned — but the till took it and somebody has to hand it over.
                </p>
            </div>

            {{-- RIGHT --}}
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Where that value went</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Cash handed over &amp; confirmed</dt><dd class="font-mono">{{ $money($balance['submitted_total']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Spent from the till, declared</dt><dd class="font-mono">{{ $money($balance['till_expenses']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Card</dt><dd class="font-mono">{{ $money($balance['card']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Transfer</dt><dd class="font-mono">{{ $money($balance['bank_transfer']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Left on credit</dt><dd class="font-mono">{{ $money($balance['period_debt']) }}</dd></div>
                    <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold dark:border-gray-700"><dt>Accounted for</dt><dd class="font-mono">{{ $money($balance['right_total']) }}</dd></div>
                </dl>
            </div>
        </div>

        @if ($subs['pending'] > 0.009 || $subs['disputed'] > 0.009)
            <div class="mt-4 rounded-xl bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300">
                <p class="font-semibold">Not every handover is settled.</p>
                <ul class="mt-1 list-inside list-disc">
                    @if ($subs['pending'] > 0.009)
                        <li>{{ $money($subs['pending']) }} handed over, still waiting on a receiver.</li>
                    @endif
                    @if ($subs['disputed'] > 0.009)
                        <li>{{ $money($subs['disputed']) }} disputed between two people.</li>
                    @endif
                </ul>
                <p class="mt-1">Neither counts as accounted for, so both are sitting inside the shortage above. You can still close — but close knowing it.</p>
            </div>
        @endif

        {{-- The independent signal. Deliberately below the money and visually
             separate: it is not part of the balance and must never be added to it. --}}
        <div class="fi-section mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Stock counted</h3>

            @if (! $variance['available'])
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    No closing count selected. A period closed on the money alone has only checked the half that balances whether or not goods left the shelf unrecorded — pick a count above, or take one with the button at the top.
                </p>
            @else
                <div class="mt-3 grid gap-4 sm:grid-cols-3">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Unexplained, at selling price</p>
                        <p @class([
                            'mt-1 font-mono text-2xl font-bold',
                            'text-danger-600 dark:text-danger-400' => $variance['unexplained_at_selling'] > 0.009,
                            'text-success-600 dark:text-success-400' => $variance['unexplained_at_selling'] <= 0.009,
                        ])>{{ $money($variance['unexplained_at_selling']) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $variance['unexplained_units'] }} units</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Gap before accounted movements</p>
                        <p class="mt-1 font-mono text-2xl font-bold text-gray-700 dark:text-gray-200">{{ $money($variance['at_selling']) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $variance['units'] }} units</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Opened with</p>
                        <p class="mt-1 font-mono text-2xl font-bold text-gray-700 dark:text-gray-200">{{ $opening['units'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            units, {{ $opening['carried'] ? 'carried forward' : 'chosen' }} · {{ $procure['units_received'] }} received in period
                        </p>
                    </div>
                </div>

                {{-- The label is not decoration. Recorded sales is the exact
                     number; this is a flag, and presenting it as a truth is how
                     somebody gets accused over a discount. --}}
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ $variance['basis'] }}</p>

                @if ($variance['unexplained_units'] !== $variance['units'])
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        The two figures differ because stock moved for recorded reasons that are neither a sale nor a delivery — a transfer to another branch, a picking, an adjustment. Those are not missing.
                    </p>
                @endif

                @if (! empty($variance['top_offenders']))
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <th class="py-2">Product</th>
                                    <th class="py-2 text-right">Opening</th>
                                    <th class="py-2 text-right">In</th>
                                    <th class="py-2 text-right">Sold</th>
                                    <th class="py-2 text-right">Other</th>
                                    <th class="py-2 text-right">Expected</th>
                                    <th class="py-2 text-right">Counted</th>
                                    <th class="py-2 text-right">Short by</th>
                                    <th class="py-2 text-right">At selling</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($variance['top_offenders'] as $row)
                                    <tr class="border-t border-gray-100 dark:border-gray-800">
                                        <td class="py-2">{{ $row['product'] }}</td>
                                        <td class="py-2 text-right font-mono">{{ $row['opening'] }}</td>
                                        <td class="py-2 text-right font-mono">{{ $row['received'] }}</td>
                                        <td class="py-2 text-right font-mono">{{ $row['sold'] }}</td>
                                        <td class="py-2 text-right font-mono">{{ $row['other_moves'] ?: '—' }}</td>
                                        <td class="py-2 text-right font-mono">{{ $row['expected'] }}</td>
                                        <td class="py-2 text-right font-mono">{{ $row['counted'] }}</td>
                                        <td @class(['py-2 text-right font-mono', 'text-danger-600 dark:text-danger-400' => $row['variance'] > 0])>{{ $row['variance'] }}</td>
                                        <td class="py-2 text-right font-mono">{{ $money($row['at_selling']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>

        @unless ($canClose)
            <div class="mt-4 rounded-xl bg-gray-50 p-4 text-sm text-gray-600 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-400 dark:ring-white/10">
                You can read this, but closing a period is a separate permission from confirming cash and from anything that edits a sale. Somebody who can both move the money and declare it settled is not being checked by anybody.
            </div>
        @endunless

        {{-- What has already been closed, so the chain is visible rather than
             something you have to trust is there. --}}
        <div class="fi-section mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Periods already closed</h3>

            @if ($closes->isEmpty())
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    This branch has never been closed. The first period opens on whichever count you choose above — after that, each period opens on what the one before it closed on.
                </p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="py-2">Reference</th>
                                <th class="py-2">Period</th>
                                <th class="py-2">Opened on</th>
                                <th class="py-2 text-right">Value sold</th>
                                <th class="py-2 text-right">Shortage</th>
                                <th class="py-2">Closed by</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($closes as $c)
                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="py-2 font-mono text-xs">{{ $c->reference }}</td>
                                    <td class="py-2">{{ $c->period_from->format('d M') }} – {{ $c->period_to->format('d M Y') }}</td>
                                    <td class="py-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $c->opening_source === \App\Models\StoreAccountClose::OPENING_CARRIED ? 'Carried forward' : 'Chosen' }}
                                    </td>
                                    <td class="py-2 text-right font-mono">{{ $money($c->value_sold) }}</td>
                                    <td @class(['py-2 text-right font-mono', 'text-danger-600 dark:text-danger-400' => (float) $c->shortage > 0.009])>{{ $money($c->shortage) }}</td>
                                    <td class="py-2">{{ $c->closedBy?->name }}</td>
                                    <td class="py-2 text-right">
                                        <a href="{{ route('account-close.show', $c) }}" target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">Open</a>
                                        <span class="text-gray-300 dark:text-gray-700">·</span>
                                        <a href="{{ route('account-close.pdf', $c) }}" class="text-primary-600 hover:underline dark:text-primary-400">PDF</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

</x-filament-panels::page>
