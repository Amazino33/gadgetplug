{{--
    Checkmate, on screen.

    Ordered the way the conversation actually goes: what is missing, then what
    explains it, then what is still owed and by whom. The unexplained figure
    leads because every other number on the page exists to shrink it.
--}}
<x-filament-panels::page>

    {{ $this->form }}

    @if (! $store)
        <div class="mt-6 rounded-xl bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300">
            Pick a branch to settle. Nothing here applies to a whole business at once — cash is held by a person at a place.
        </div>
    @else
        @php
            $cash   = $recon['cash'];
            $out    = $recon['outstanding'];
            $profit = $recon['profit'];
            $rev    = $recon['reversals'];
            $debt   = $out['unpaid_debt'];
            $short  = (float) $out['true_shortage'];
            // Sign before the symbol — "₦-5,000.00" reads as a typo.
            $money  = fn ($v) => ((float) $v < 0 ? '-₦' : '₦') . number_format(abs((float) $v), 2);
        @endphp

        {{-- The number the whole page is about. --}}
        <div class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Unexplained shortage — {{ $store->name }}, {{ $period->label }}
            </p>
            <p @class([
                'mt-1 text-4xl font-bold tracking-tight',
                'text-danger-600 dark:text-danger-400' => $short > 0.009,
                'text-success-600 dark:text-success-400' => $short <= 0.009,
            ])>{{ $money($short) }}</p>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                @if ($short > 0.009)
                    Everything else below is accounted for. This is what is not.
                @else
                    Every naira is accounted for by the buckets below.
                @endif
            </p>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            {{-- Cash --}}
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Cash</h3>
                {{-- Plain words on purpose: this is read by storekeepers, not
                     accountants. "Cash collected" is what actually happened. --}}
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Cash collected</dt><dd class="font-mono">{{ $money($cash['takings']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Spent from the cash</dt><dd class="font-mono">({{ $money($cash['till_expenses']) }})</dd></div>
                    <div class="flex justify-between border-t border-gray-200 pt-2 font-semibold dark:border-gray-700"><dt>Should be handed over</dt><dd class="font-mono">{{ $money($cash['expected']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Handed over &amp; confirmed</dt><dd class="font-mono">{{ $money($cash['confirmed']) }}</dd></div>
                </dl>
            </div>

            {{-- Profit, kept visibly apart from cash: they answer different
                 questions and conflating them is how a credit sale gets
                 mistaken for missing money. --}}
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Trading</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Revenue</dt><dd class="font-mono">{{ $money($profit['revenue']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Cost of goods</dt><dd class="font-mono">({{ $money($profit['cogs']) }})</dd></div>
                    <div class="flex justify-between border-t border-gray-200 pt-2 font-semibold dark:border-gray-700"><dt>Profit</dt><dd class="font-mono">{{ $money($profit['profit']) }}</dd></div>
                </dl>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Counts every sale however it was paid for. The cash panel counts only the cash tender.
                </p>
            </div>
        </div>

        {{-- The second signal, deliberately next to the cash gap.
             Cash alone cannot see goods that left without being rung up: the
             sale never existed, so expected cash never counted it and the books
             balance perfectly. Only counting the goods finds that. --}}
        <div class="fi-section mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Stock check</h3>

            @if (! $stockCount)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    No stock counted for this period yet. Use <strong>Count stock</strong> above.
                    Without it, this settlement has only checked the money — goods that left without
                    being rung up would not show anywhere on this page.
                </p>
            @else
                @php
                    $v = $stockCount->variance();
                    $rows = $stockCount->discrepancies();
                @endphp

                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Counted by {{ $stockCount->countedBy->name }} on {{ $stockCount->counted_at->format('d M Y, g:ia') }}.
                </p>

                {{-- What has been decided about it. A count nobody has signed
                     off has not corrected anything, and the page should not let
                     anyone assume otherwise. --}}
                @if ($stockCount->isSubmitted())
                    <p class="mt-2 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-300">
                        Waiting to be signed off. The stock has <strong>not</strong> been corrected yet, and nobody has
                        been made answerable for the gap.
                        @if ($this->canApproveCount($stockCount))
                            Use <strong>Approve count</strong> above.
                        @else
                            Somebody other than {{ $stockCount->countedBy->name }}, with permission to approve counts at
                            this branch, has to sign it off.
                        @endif
                    </p>
                @elseif ($stockCount->isApproved())
                    <p class="mt-2 rounded-lg bg-success-50 p-3 text-sm text-success-800 dark:bg-success-400/10 dark:text-success-400">
                        Signed off by {{ $stockCount->approvedBy?->name }}
                        on {{ $stockCount->approved_at?->format('d M Y, g:ia') }} — stock corrected.
                        @if ($stockCount->outcome === App\Models\PhysicalStockCount::OUTCOME_CHARGED)
                            The shortage is owed by {{ $stockCount->chargedTo?->name }}.
                        @elseif ($stockCount->outcome === App\Models\PhysicalStockCount::OUTCOME_WRITTEN_OFF)
                            Written off against the business.
                        @else
                            Nothing was missing.
                        @endif
                        @if ($stockCount->decision_note)
                            <span class="block mt-1 italic">"{{ $stockCount->decision_note }}"</span>
                        @endif
                    </p>
                @else
                    <p class="mt-2 rounded-lg bg-gray-100 p-3 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        Not accepted by {{ $stockCount->approvedBy?->name }} — a recount was asked for.
                        @if ($stockCount->decision_note)
                            <span class="block mt-1 italic">"{{ $stockCount->decision_note }}"</span>
                        @endif
                    </p>
                @endif

                @if ($rows->isEmpty())
                    <p class="mt-3 rounded-lg bg-success-50 p-3 text-sm text-success-800 dark:bg-success-400/10 dark:text-success-400">
                        Everything counted matches the books.
                    </p>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                    <th class="py-2 text-left font-medium">Product</th>
                                    <th class="py-2 text-right font-medium">Should be</th>
                                    <th class="py-2 text-right font-medium">Counted</th>
                                    <th class="py-2 text-right font-medium">Missing</th>
                                    <th class="py-2 text-right font-medium">At selling price</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="py-2">{{ $row['product'] }}</td>
                                        <td class="py-2 text-right font-mono">{{ number_format($row['system']) }}</td>
                                        <td class="py-2 text-right font-mono">{{ number_format($row['counted']) }}</td>
                                        <td @class([
                                            'py-2 text-right font-mono font-semibold',
                                            'text-danger-600 dark:text-danger-400' => $row['missing'] > 0,
                                            'text-warning-600 dark:text-warning-400' => $row['missing'] < 0,
                                        ])>{{ $row['missing'] > 0 ? $row['missing'] : '+' . abs($row['missing']) }}</td>
                                        <td class="py-2 text-right font-mono">{{ $row['missing'] > 0 ? $money($row['at_selling']) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- The whole point of the section: put the two numbers side
                         by side and let the reader draw the obvious conclusion. --}}
                    <div class="mt-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                        <dl class="space-y-1.5 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Missing stock, at selling price</dt>
                                <dd class="font-mono font-semibold">{{ $money($v['missing_at_selling']) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Unexplained cash shortage</dt>
                                <dd class="font-mono font-semibold">{{ $money($short) }}</dd>
                            </div>
                        </dl>

                        <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">
                            @if ($v['missing_at_selling'] > 0.009 && $short > 0.009)
                                Both are short. Goods left and the money for them did not arrive — the likeliest
                                reading is sales made off the books.
                            @elseif ($v['missing_at_selling'] > 0.009)
                                Stock is short but the cash balances. The goods left without a sale being rung up.
                            @elseif ($short > 0.009)
                                Cash is short but the stock is right. The goods were sold properly; the money went
                                missing afterwards.
                            @else
                                Stock and cash both balance for this period.
                            @endif
                        </p>
                    </div>

                    @if ($v['lines_over'] > 0)
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                            {{ $v['lines_over'] }} product(s) counted higher than the books. Extra stock is not good news
                            either — it usually means goods arrived without being received. Overages are not netted off
                            the missing figure above.
                        </p>
                    @endif
                @endif
            @endif
        </div>

        {{-- Per branch, then per person.
             A vendor-wide total is not something anybody can act on: cash is
             held by a named person at a named place, and this is the section
             that says which. --}}
        <div class="fi-section mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Cash collected by branch</h3>

            @if ($branches->isEmpty())
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No branch collected cash in this period.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 text-left font-medium">Branch / cashier</th>
                                <th class="py-2 text-right font-medium">Cash collected</th>
                                <th class="py-2 text-right font-medium">Handed over</th>
                                <th class="py-2 text-right font-medium">Still to hand over</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($branches as $branch)
                                <tr class="border-b border-gray-100 font-semibold dark:border-gray-800">
                                    <td class="py-2">
                                        {{ $branch['store_name'] }}
                                        @if ($branch['store_id'] === $store->id)
                                            <span class="ml-1 rounded bg-primary-50 px-1.5 py-0.5 text-xs font-normal text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">viewing</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right font-mono">{{ $money($branch['collected']) }}</td>
                                    <td class="py-2 text-right font-mono">{{ $money($branch['confirmed'] + $branch['pending']) }}</td>
                                    <td @class([
                                        'py-2 text-right font-mono',
                                        'text-danger-600 dark:text-danger-400' => (float) $branch['outstanding'] > 0.009,
                                    ])>{{ $money($branch['outstanding']) }}</td>
                                </tr>

                                {{-- The names under each branch. "The branch is short"
                                     is not actionable; "Chioma collected X and has
                                     handed over none of it" is. --}}
                                @forelse ($branch['cashiers'] as $cashier)
                                    <tr class="border-b border-gray-50 text-gray-600 dark:border-gray-800/50 dark:text-gray-300">
                                        <td class="py-1.5 pl-6">{{ $cashier['name'] }}</td>
                                        <td class="py-1.5 text-right font-mono">{{ $money($cashier['collected']) }}</td>
                                        <td class="py-1.5 text-right font-mono">{{ $money($cashier['handed_over']) }}</td>
                                        <td @class([
                                            'py-1.5 text-right font-mono',
                                            'text-danger-600 dark:text-danger-400' => (float) $cashier['outstanding'] > 0.009,
                                        ])>{{ $money($cashier['outstanding']) }}</td>
                                    </tr>
                                @empty
                                    <tr class="border-b border-gray-50 dark:border-gray-800/50">
                                        <td colspan="4" class="py-1.5 pl-6 text-xs text-gray-400">No cashier took cash here in this period.</td>
                                    </tr>
                                @endforelse
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Cash collected is what stayed in the drawer — what the customer handed over, less any change given.
                    Card and transfer sales are not here; they never passed through anyone's hands.
                </p>
            @endif
        </div>

        {{-- Where the gap sits --}}
        <div class="fi-section mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Where the difference sits</h3>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Owed by customers — not cash yet</dt>
                    <dd class="font-mono">{{ $money($debt['total']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Taken but not handed over</dt>
                    <dd class="font-mono">{{ $money($out['unsubmitted']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Handed over, awaiting confirmation</dt>
                    <dd class="font-mono">{{ $money($out['pending_confirmation']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Disputed</dt>
                    <dd class="font-mono">{{ $money($out['disputed']) }}</dd>
                </div>
                <div class="flex justify-between border-t border-gray-200 pt-2 font-semibold dark:border-gray-700">
                    <dt>Unexplained</dt>
                    <dd @class(['font-mono', 'text-danger-600 dark:text-danger-400' => $short > 0.009])>{{ $money($short) }}</dd>
                </div>
                @if ((float) $out['overage'] > 0.009)
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Overage — more came back than was taken</dt>
                        <dd class="font-mono">{{ $money($out['overage']) }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- A void removes money from expected cash after the fact, so an
             unexplained one is the shape of a covered theft. Surfaced rather
             than left to be inferred from a total that silently got smaller. --}}
        @if ($rev['count'] > 0)
            <div class="mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Sales withdrawn in this period</h3>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    {{ $rev['count'] }} sale(s) voided or returned.
                    {{ $rev['voided_cash_count'] }} of them were cash sales, worth {{ $money($rev['voided_cash']) }}.
                </p>
                @if ($rev['unexplained'] > 0)
                    <p class="mt-2 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-300">
                        {{ $rev['unexplained'] }} were voided with no reason given.
                    </p>
                @endif
            </div>
        @endif

        {{-- Debtors --}}
        @if ((float) $debt['total'] > 0.009)
            <div class="fi-section mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Owed by customers</h3>
                <div class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    @foreach (['current' => 'Up to 7 days', '8_30' => '8–30 days', '31_60' => '31–60 days', 'over_60' => 'Over 60 days'] as $key => $label)
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                            <p class="mt-1 font-mono font-semibold">{{ $money($debt['buckets'][$key] ?? 0) }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Aged by applying each payment to the oldest unpaid charge first. Shrinks on its own as customers pay.
                </p>
            </div>
        @endif

        {{-- What the branch is holding or owed.
             A different question from the cash reconciliation above: not "did
             the right amount come back" but "what is tied up here". --}}
        @php $pos = $recon['position']; @endphp
        <div class="fi-section mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Where the money is tied up</h3>

            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">
                        Stock on hand <span class="text-xs">(at cost, {{ number_format($pos['stock_units']) }} units)</span>
                    </dt>
                    <dd class="font-mono">{{ $money($pos['stock_at_cost']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Cash still to be handed over</dt>
                    <dd class="font-mono">{{ $money($pos['cash_outstanding']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Owed by customers</dt>
                    <dd class="font-mono">{{ $money($pos['customer_debt']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">
                        Pickings out on trust
                        {{-- Labelled, not silently mixed: this one line is at
                             selling price while every other is at cost. --}}
                        <span class="text-xs">(at selling price, {{ number_format($pos['pickings_units']) }} units)</span>
                    </dt>
                    <dd class="font-mono">{{ $money($pos['pickings_retail']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Owed by staff</dt>
                    <dd class="font-mono">{{ $money($pos['staff_debts']) }}</dd>
                </div>
                <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold dark:border-gray-700">
                    <dt>Total balance</dt>
                    <dd class="font-mono">{{ $money($pos['total_balance']) }}</dd>
                </div>
            </dl>

            @if ($pos['stock_uncosted'] > 0)
                <p class="mt-3 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-300">
                    {{ $pos['stock_uncosted'] }} product(s) on the shelf have no cost price recorded, so they are left
                    out of the stock value above. The total is understated by whatever they are worth.
                </p>
            @endif

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Every line is at cost except pickings, which are valued at selling price.
                Staff debts recorded before branches existed are not attributed to any branch and do not appear here.
            </p>
        </div>

        {{-- Trading through the period, as opposed to the standing position above. --}}
        <div class="fi-section mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Trading in this period</h3>
            <dl class="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Total sold</dt><dd class="font-mono">{{ $money($pos['sold_in_range']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Cost of what sold</dt><dd class="font-mono">{{ $money($pos['cogs_in_range']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Stock purchased</dt><dd class="font-mono">{{ $money($pos['purchases']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Expenses</dt><dd class="font-mono">{{ $money($pos['expenses']) }}</dd></div>
                <div class="flex justify-between border-t border-gray-200 pt-2 font-semibold dark:border-gray-700 sm:col-span-2">
                    <dt>Gross profit</dt><dd class="font-mono">{{ $money($pos['profit_in_range']) }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Gross profit is what sold less what it cost. Expenses and stock purchased are shown beside it but not
                subtracted from it — buying stock moves money into the shelf, it does not lose it.
            </p>
        </div>

        {{-- Frozen copies --}}
        <div class="fi-section mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Frozen statements</h3>
            @if ($statements->isEmpty())
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    None yet. Freezing one takes a copy of these figures with your name and the time on it.
                </p>
            @else
                <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                    @foreach ($statements as $statement)
                        <li class="flex items-center justify-between py-2">
                            <div>
                                <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $statement->reference }}</span>
                                <span class="ml-2">{{ $statement->period_start->format('d M') }} – {{ $statement->period_end->format('d M Y') }}</span>
                                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                                    by {{ $statement->generatedBy->name }}, {{ $statement->generated_at->format('d M, g:ia') }}
                                </span>
                            </div>
                            <div class="flex gap-3">
                                <a href="{{ route('settlement.show', $statement) }}" target="_blank"
                                   class="text-primary-600 hover:underline dark:text-primary-400">Open</a>
                                <a href="{{ route('settlement.pdf', $statement) }}"
                                   class="text-primary-600 hover:underline dark:text-primary-400">PDF</a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

</x-filament-panels::page>
