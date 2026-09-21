{{--
    The Store Account Close.

    What a branch sold in a period, against where that value went — and the
    stock the next period opens with.

    A settlement statement is a photograph somebody took because a conversation
    was about to happen. This is the end of the period: the figures do not move
    again, and the closing count on it becomes the next period's opening. One
    template for both the screen and the PDF, so what is discussed on a laptop
    and what gets signed on paper cannot drift apart.

    Every figure comes from the frozen payload, never recomputed at render. A
    close that recalculated itself on open would answer a different question
    each time it was looked at — which is the one thing it exists to prevent.
--}}
@php
    $p        = $close->payload;
    $balance  = $p['balance'] ?? [];
    $check    = $p['checkmate'] ?? [];
    $variance = $p['count_variance'] ?? ['available' => false];
    $opening  = $p['opening'] ?? [];
    $subs     = $p['submissions'] ?? [];
    $procure  = $p['procurement'] ?? [];

    // Sign before the symbol. number_format on a negative yields "₦-5,000.00",
    // which reads as a typo on a document somebody is about to sign.
    $money = function ($v) {
        $v = (float) $v;

        return ($v < 0 ? '-₦' : '₦') . number_format(abs($v), 2);
    };

    $gap = (float) ($balance['shortage'] ?? 0);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $close->reference }} — {{ $close->store->name }}</title>
    @include('settlements.partials.paper-styles')
</head>
<body>

    <h1>Store Account Close</h1>
    <p class="sub">
        <strong>{{ $close->store->name }}</strong> ·
        {{ $close->period_from->format('d M Y') }} to {{ $close->period_to->format('d M Y') }}
    </p>
    <p class="ref">
        {{ $close->reference }} · closed by {{ $close->closedBy->name }}
        on {{ $close->closed_at->format('d M Y, g:ia') }}
    </p>

    {{-- The number the whole page exists to produce, given its own box so
         nobody has to hunt for it among figures that merely support it. --}}
    <div class="headline">
        <div class="label">{{ $gap < -0.009 ? 'Overage' : 'Shortage' }}</div>
        <div class="value {{ $gap > 0.009 ? 'short' : 'clear' }}">{{ $money(abs($gap)) }}</div>
        <div class="muted">
            @if ($gap > 0.009)
                The value sold that has not turned up anywhere. Card, transfer and credit
                appear on both sides below and cancel, so only cash can actually be missing.
            @elseif ($gap < -0.009)
                More came back than the tills took. Worth explaining before it is signed.
            @else
                Every naira sold is accounted for on the right.
            @endif
        </div>
    </div>

    @if (! ($check['agrees'] ?? true))
        <div class="warn">
            <strong>These two sides do not reduce to the cash gap.</strong>
            The balance shows {{ $money($gap) }}; the cash reconciliation shows
            {{ $money($check['cash_shortage'] ?? 0) }}, a difference of
            {{ $money($check['difference'] ?? 0) }}. Something upstream is not what this
            arithmetic assumes — do not put this figure to anybody until it is understood.
        </div>
    @endif

    <h2>Value sold</h2>
    <table class="keep">
        <tr class="row"><td>Charged to customers</td><td class="num">{{ $money($balance['gross_sales'] ?? 0) }}</td></tr>
        <tr class="row"><td>Refunded</td><td class="num">({{ $money($balance['refunds'] ?? 0) }})</td></tr>
        <tr class="total"><td>Value sold</td><td class="num">{{ $money($balance['value_sold'] ?? 0) }}</td></tr>
    </table>
    <p class="muted" style="margin-top:6px">
        What the customer actually paid, VAT included, across every tender. This is not the
        revenue figure on the trading reports, which excludes VAT because VAT is collected for
        the government rather than earned — but the till took it and somebody has to hand it over.
    </p>

    <h2>Where that value went</h2>
    <table class="keep">
        <tr class="row"><td>Cash handed over and confirmed</td><td class="num">{{ $money($balance['submitted_total'] ?? 0) }}</td></tr>
        <tr class="row"><td>Spent from the till, declared</td><td class="num">{{ $money($balance['till_expenses'] ?? 0) }}</td></tr>
        <tr class="row"><td>Card, settled to the bank</td><td class="num">{{ $money($balance['card'] ?? 0) }}</td></tr>
        <tr class="row"><td>Transfer, settled to the bank</td><td class="num">{{ $money($balance['bank_transfer'] ?? 0) }}</td></tr>
        <tr class="row"><td>Left on credit</td><td class="num">{{ $money($balance['period_debt'] ?? 0) }}</td></tr>
        <tr class="total"><td>Accounted for</td><td class="num">{{ $money($balance['right_total'] ?? 0) }}</td></tr>
        <tr class="total">
            <td>{{ $gap < -0.009 ? 'Overage' : 'Shortage' }}</td>
            <td class="num {{ $gap > 0.009 ? 'short' : '' }}">{{ $money($gap) }}</td>
        </tr>
    </table>

    @if (($subs['pending'] ?? 0) > 0.009 || ($subs['disputed'] ?? 0) > 0.009)
        <div class="warn">
            <strong>Not every handover was settled when this was closed.</strong>
            @if (($subs['pending'] ?? 0) > 0.009)
                {{ $money($subs['pending']) }} was handed over and still waiting on a receiver.
            @endif
            @if (($subs['disputed'] ?? 0) > 0.009)
                {{ $money($subs['disputed']) }} is disputed between two people.
            @endif
            Neither is counted on the right above, so both are sitting inside the shortage.
        </div>
    @endif

    <h2>What happened to the goods</h2>
    @php $m = $p['stock_movement'] ?? ['available' => false]; @endphp
    <table class="keep">
        <tr><th></th><th class="num">At cost</th><th class="num">At selling price</th></tr>
        <tr class="row">
            <td>Opening stock{{ ($m['opening_units'] ?? 0) ? ' (' . $m['opening_units'] . ' units)' : '' }}</td>
            <td class="num">{{ $money($m['opening_value'] ?? 0) }}</td>
            <td class="num">{{ $money($m['opening_selling'] ?? 0) }}</td>
        </tr>
        <tr class="row">
            <td>Stock sent to this branch ({{ $m['purchases_count'] ?? 0 }})</td>
            <td class="num">{{ $money($m['purchases_value'] ?? 0) }}</td>
            <td class="num">{{ $money($m['purchases_selling'] ?? 0) }}</td>
        </tr>
        <tr class="total">
            <td>Available to sell</td>
            <td class="num">{{ $money($m['available_value'] ?? 0) }}</td>
            <td class="num">{{ $money($m['available_selling'] ?? 0) }}</td>
        </tr>
        <tr class="row">
            <td>Closing stock{{ ($m['closing_units'] ?? 0) ? ' (' . $m['closing_units'] . ' units)' : '' }}</td>
            <td class="num">({{ $money($m['closing_value'] ?? 0) }})</td>
            <td class="num">({{ $money($m['closing_selling'] ?? 0) }})</td>
        </tr>
        <tr class="total">
            <td>Left the shelf</td>
            <td class="num">{{ $money($m['left_at_cost'] ?? 0) }}</td>
            <td class="num">{{ $money($m['left_at_selling'] ?? 0) }}</td>
        </tr>
    </table>

    @unless ($m['available'] ?? false)
        <div class="warn">
            Both an opening and a closing count are needed before "left the shelf" means anything.
            Without them this is only part of the picture.
        </div>
    @endunless

    @if (($m['uncosted_lines'] ?? 0) > 0 || ($m['unpriced_lines'] ?? 0) > 0)
        <p class="muted" style="margin-top:6px">
            {{ $m['uncosted_lines'] ?? 0 }} counted line(s) have no cost price and
            {{ $m['unpriced_lines'] ?? 0 }} have no selling price, so they are left out of that
            column rather than counted as worth nothing. Those totals are understated.
        </p>
    @endif

    {{-- Printed in full, because on paper there is nobody to ask what these columns
         mean and the figure sits inches from a selling-price total. --}}
    <p class="muted" style="margin-top:6px">{{ $m['basis'] ?? '' }}</p>

    @if (! empty($m['purchases']))
        <table style="margin-top:8px">
            <tr><th>Arrived</th><th>Reference</th><th>Supplier</th><th>Recorded by</th><th>Approved by</th><th class="num">Cost</th><th class="num">Retail</th></tr>
            @foreach ($m['purchases'] as $row)
                <tr class="row">
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['reference'] }}{{ $row['paid_cash'] ? ' (cash)' : '' }}</td>
                    <td>{{ $row['supplier'] }}</td>
                    <td>{{ $row['recorded_by'] }}</td>
                    <td>{{ $row['approved_by'] }}</td>
                    <td class="num">{{ $money($row['amount']) }}</td>
                    <td class="num">{{ $money($row['selling']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Money handed over in this period</h2>
    @if (empty($subs['rows']))
        <p class="muted">Nobody handed any cash over in this period.</p>
    @else
        <table>
            <tr><th>Date</th><th>From</th><th>To</th><th>Status</th><th class="num">Amount</th></tr>
            @foreach ($subs['rows'] as $row)
                <tr class="row">
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['from'] }}</td>
                    <td>{{ $row['to'] }}</td>
                    <td>{{ str_replace('_', ' ', $row['status']) }}</td>
                    <td class="num">{{ $money($row['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="4">Confirmed — counts on the right above</td><td class="num">{{ $money($subs['confirmed'] ?? 0) }}</td></tr>
        </table>
        <p class="muted" style="margin-top:6px">
            Only confirmed handovers count as accounted for. Anything pending or disputed is real
            money that has moved and is still sitting inside the shortage at the top.
        </p>
    @endif

    <h2>Stock counted</h2>
    @if ($variance['available'] ?? false)
        <table class="keep">
            <tr>
                <th>Opening</th><th>Received</th><th>Sold</th><th class="num">Short by</th><th class="num">At selling price</th>
            </tr>
            <tr class="row">
                <td>{{ $opening['units'] ?? 0 }} units{{ ($opening['carried'] ?? false) ? ' (carried forward)' : ' (chosen)' }}</td>
                <td>{{ $procure['units_received'] ?? 0 }} units</td>
                <td>{{ $variance['products_counted'] ?? 0 }} products counted</td>
                <td class="num">{{ $variance['units'] ?? 0 }}</td>
                <td class="num {{ ($variance['at_selling'] ?? 0) > 0.009 ? 'short' : '' }}">{{ $money($variance['at_selling'] ?? 0) }}</td>
            </tr>
        </table>

        <p class="muted" style="margin-top:6px">
            {{ $variance['basis'] ?? '' }}
        </p>

        @if (($variance['unexplained_units'] ?? 0) !== ($variance['units'] ?? 0))
            <p class="muted">
                Of that, <strong>{{ $variance['unexplained_units'] }} units</strong>
                ({{ $money($variance['unexplained_at_selling'] ?? 0) }}) are unexplained. The rest
                moved for a recorded reason — a transfer to another branch, a picking, an
                adjustment — and is not missing.
            </p>
        @endif

        @if (! empty($variance['top_offenders']))
            <table style="margin-top:8px">
                <tr>
                    <th>Product</th><th class="num">Opening</th><th class="num">In</th>
                    <th class="num">Sold</th><th class="num">Expected</th><th class="num">Counted</th>
                    <th class="num">Short by</th><th class="num">At selling</th>
                </tr>
                @foreach ($variance['top_offenders'] as $row)
                    <tr class="row">
                        <td>{{ $row['product'] }}</td>
                        <td class="num">{{ $row['opening'] }}</td>
                        <td class="num">{{ $row['received'] }}</td>
                        <td class="num">{{ $row['sold'] }}</td>
                        <td class="num">{{ $row['expected'] }}</td>
                        <td class="num">{{ $row['counted'] }}</td>
                        <td class="num {{ $row['variance'] > 0 ? 'short' : '' }}">{{ $row['variance'] }}</td>
                        <td class="num">{{ $money($row['at_selling']) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        <p class="muted" style="margin-top:6px">
            This is the half a cash check cannot see. Goods that leave without a sale being rung
            leave the money side balancing perfectly, because expected cash is worked out from
            records that were never made.
        </p>
    @else
        <p class="muted">
            No stock was counted for this period. Only the money has been checked, and the money
            balances whether or not goods left the shelf unrecorded.
        </p>
    @endif

    <h2>The chain</h2>
    <table class="keep">
        <tr class="row">
            <td>Opened on</td>
            <td>
                @if ($close->opening_count_id)
                    Count #{{ $close->opening_count_id }},
                    {{ ($opening['carried'] ?? false) ? 'carried forward from the last close' : 'chosen at the first close' }}
                    — {{ $opening['units'] ?? 0 }} units
                @else
                    Nothing. This branch held no stock when the period opened.
                @endif
            </td>
        </tr>
        <tr class="row">
            <td>Closed on</td>
            <td>Count #{{ $close->closing_count_id }} — carries forward as the next period's opening</td>
        </tr>
        @if ($close->previous_close_id)
            <tr class="row">
                <td>Follows</td>
                <td>{{ optional($close->previousClose)->reference ?? ('Close #' . $close->previous_close_id) }}</td>
            </tr>
        @endif
    </table>

    <h2>Signoff</h2>
    <table class="sign">
        <tr>
            <td width="50%"><span class="muted">Storekeeper — name &amp; signature</span></td>
            <td width="50%"><span class="muted">Closed by — name &amp; signature</span></td>
        </tr>
        <tr>
            <td><span class="muted">Date</span></td>
            <td><span class="muted">Date</span></td>
        </tr>
    </table>

    @if ($gap > 0.009 || ($variance['unexplained_at_selling'] ?? 0) > 0.009)
        <h2>What was agreed about the gap</h2>
        <table class="sign">
            <tr><td colspan="2" style="height:70px"><span class="muted">Outcome — repayment, write-off, recount, or no issue found</span></td></tr>
            <tr>
                <td width="50%"><span class="muted">Agreed by — name &amp; signature</span></td>
                <td width="50%"><span class="muted">Date</span></td>
            </tr>
        </table>
    @endif

    <p class="ref" style="margin-top:14px">
        Figures frozen at {{ $close->closed_at->format('d M Y, g:ia') }}. Nothing about completed
        sales was locked by closing — a correction dated inside this period can still land, and it
        will not change this copy. Run the live view to see where things now stand.
    </p>

</body>
</html>
