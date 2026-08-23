{{--
    The keepable copy, downloaded from the customer's receipt page.

    Built to CSS 2.1 only: dompdf has no flexbox and no grid, so every row here
    is a table. Modern layout added to this file will silently do nothing.

    Like the web version, it carries no customer name or phone.
--}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Receipt {{ $sale->reference }}</title>
<style>
    @page { size: A4 portrait; margin: 18mm 16mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #14261a; margin: 0; }

    .masthead { border-bottom: 2pt solid #14261a; padding-bottom: 6mm; margin-bottom: 6mm; }
    .store { font-size: 20pt; font-weight: bold; margin: 0 0 1mm; }
    .sub   { font-size: 9pt; color: #5d7063; margin: 0; }

    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }

    table.meta td { padding: 1mm 0; font-size: 10pt; }
    table.meta td.k { color: #5d7063; }
    table.meta td.v { text-align: right; font-weight: bold; }

    table.items { margin-top: 5mm; }
    table.items th {
        text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .5pt;
        color: #5d7063; border-bottom: 1pt solid #14261a; padding-bottom: 1.5mm;
    }
    table.items td { padding: 2mm 0; border-bottom: 0.5pt solid #e2e8e3; font-size: 10pt; }
    td.num { text-align: right; white-space: nowrap; }

    table.totals { margin-top: 5mm; }
    table.totals td { padding: 1mm 0; font-size: 10pt; color: #5d7063; }
    table.totals td.v { text-align: right; }
    table.totals tr.grand td {
        font-size: 14pt; font-weight: bold; color: #14261a;
        border-top: 1.5pt solid #14261a; padding-top: 2.5mm;
    }

    .footer {
        margin-top: 10mm; border-top: 0.5pt solid #e2e8e3; padding-top: 3mm;
        font-size: 8.5pt; color: #5d7063; text-align: center; white-space: pre-line;
    }
</style>
</head>
<body>

@php $money = fn ($n) => 'NGN ' . number_format((float) $n, 2); @endphp

<div class="masthead">
    <p class="store">{{ $settings->displayName($vendor) }}</p>
    @foreach($settings->headerLines() as $line)
        <p class="sub">{{ $line }}</p>
    @endforeach
</div>

<table class="meta">
    <tr><td class="k">Receipt</td><td class="v">{{ $sale->reference }}</td></tr>
    <tr><td class="k">Date</td><td class="v">{{ $soldAt->format('j M Y, g:ia') }}</td></tr>
    <tr>
        <td class="k">Payment</td>
        <td class="v">{{ $payments->isNotEmpty() ? 'Split' : ucwords(str_replace('_', ' ', (string) $sale->payment_method)) }}</td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>Item</th>
            <th style="text-align:right">Qty</th>
            <th style="text-align:right">Price</th>
            <th style="text-align:right">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $item)
        <tr>
            <td>{{ $item['name'] }}</td>
            <td class="num">{{ $item['quantity'] }}</td>
            <td class="num">{{ $money($item['unit_price']) }}</td>
            <td class="num">{{ $money($item['total']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="v">{{ $money($sale->subtotal) }}</td></tr>
    @if((float) $sale->discount_amount > 0)
    <tr><td>Discount</td><td class="v">-{{ $money($sale->discount_amount) }}</td></tr>
    @endif
    @if($vatEnabled && (float) $sale->vat_amount > 0)
    <tr><td>VAT ({{ rtrim(rtrim(number_format((float) $vatRate, 2), '0'), '.') }}%)</td><td class="v">{{ $money($sale->vat_amount) }}</td></tr>
    @endif
    <tr class="grand"><td>Total</td><td class="v">{{ $money($sale->total) }}</td></tr>
</table>

@if(trim((string) $settings->footer_text) !== '')
<div class="footer">{{ $settings->footer_text }}</div>
@endif

</body>
</html>
