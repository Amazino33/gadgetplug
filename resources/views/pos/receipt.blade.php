{{--
    Thermal receipt, 80mm.

    Rendered server-side and printed from its own document rather than out of the
    POS modal. The old approach hid the app with `visibility: hidden` and pinned
    the receipt with `position: fixed`, which cannot paginate — anything past one
    page was silently cut off, and the modal's own padding leaked into the print.
    A standalone page has none of those problems and lets the layout follow the
    vendor's settings.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt {{ $sale->reference }}</title>
<style>
    /* 80mm roll, printed edge to edge. The 4mm margin keeps text off the
       tear-off edge on the common Xprinter/Epson heads. */
    @page { size: 80mm auto; margin: 4mm; }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
        background: #fff;
        color: #000;
    }

    body {
        /* Monospace keeps the money column aligned — the single biggest reason
           thermal receipts look "not arranged" is a proportional font. */
        font-family: "Courier New", "DejaVu Sans Mono", monospace;
        font-size: 12px;
        line-height: 1.35;
        width: 72mm;          /* 80mm paper minus the printer's own margins */
        margin: 0 auto;
    }

    .al-left   { text-align: left; }
    .al-center { text-align: center; }
    .al-right  { text-align: right; }

    .store-name {
        font-size: 16px;
        font-weight: bold;
        letter-spacing: .5px;
        margin: 0 0 2px;
        text-transform: uppercase;
    }
    .header-line { margin: 0; font-size: 11px; }

    .logo { max-width: 40mm; max-height: 18mm; margin: 0 auto 4px; display: block; }

    hr {
        border: 0;
        border-top: 1px dashed #000;
        margin: 6px 0;
    }

    /* Label/value rows — the label is allowed to wrap, the value never is. */
    .row {
        display: flex;
        justify-content: space-between;
        gap: 6px;
        font-size: 11px;
    }
    .row .v { white-space: nowrap; text-align: right; }

    table.items { width: 100%; border-collapse: collapse; }
    table.items td { padding: 1px 0; vertical-align: top; font-size: 11px; }
    td.qty   { width: 8mm;  text-align: left; white-space: nowrap; }
    td.amt   { width: 20mm; text-align: right; white-space: nowrap; }
    .item-name { word-break: break-word; }
    .unit-price { font-size: 10px; }

    .totals .row { font-size: 11px; }
    .grand {
        font-size: 15px;
        font-weight: bold;
        border-top: 1px solid #000;
        padding-top: 3px;
        margin-top: 3px;
    }

    .footer { margin-top: 8px; font-size: 11px; white-space: pre-line; }

    /* 30mm square. Smaller than this and a thermal head's dot size starts
       eating the finder patterns, which is when phones stop reading it. */
    .qr-block { margin-top: 4px; }
    /* Inline SVG rather than an <img> data URI, so the code stays vector all
       the way into the print raster instead of being rasterised at screen dpi
       and upscaled by the printer. */
    .qr { width: 30mm; height: 30mm; margin: 0 auto 2px; }
    .qr svg { width: 100%; height: 100%; display: block; }
    .qr-caption { margin: 0; font-size: 10px; }
    .feed { height: 0; }

    /* On screen (the POS preview / a phone) give it a paper-like frame; when
       printing that framing must disappear. */
    @media screen {
        body { padding: 8mm 4mm; box-shadow: 0 0 0 1px #e5e5e5; margin: 12px auto; }
    }
</style>
</head>
<body>

@php
    $align  = 'al-' . ($settings->header_alignment ?? 'center');
    $falign = 'al-' . ($settings->footer_alignment ?? 'center');
    $money  = fn ($n) => number_format((float) $n, 2);
@endphp

{{-- ── Header ─────────────────────────────────────────────────────────── --}}
<div class="{{ $align }}">
    @if($settings->show_logo && $logoUrl)
        <img src="{{ $logoUrl }}" alt="" class="logo">
    @endif

    <p class="store-name">{{ $settings->displayName($vendor) }}</p>

    @foreach($settings->headerLines() as $line)
        <p class="header-line">{{ $line }}</p>
    @endforeach
</div>

<hr>

{{-- ── Sale meta ──────────────────────────────────────────────────────── --}}
@if($settings->show_receipt_number)
<div class="row"><span>Receipt</span><span class="v">{{ $sale->reference }}</span></div>
@endif

@if($settings->show_datetime)
<div class="row"><span>Date</span><span class="v">{{ $soldAt->format('d/m/Y') }}</span></div>
<div class="row"><span>Time</span><span class="v">{{ $soldAt->format('g:i A') }}</span></div>
@endif

@if($settings->show_cashier && $cashierName)
<div class="row"><span>Cashier</span><span class="v">{{ $cashierName }}</span></div>
@endif

@if($settings->show_customer && $customerName)
<div class="row"><span>Customer</span><span class="v">{{ $customerName }}</span></div>
@endif

<hr>

{{-- ── Items ──────────────────────────────────────────────────────────── --}}
<table class="items">
    @foreach($items as $item)
    <tr>
        <td class="qty">{{ $item['quantity'] }}x</td>
        <td>
            <div class="item-name">{{ $item['name'] }}</div>
            @if($settings->show_item_unit_price)
                <div class="unit-price">@ {{ $money($item['unit_price']) }}</div>
            @endif
        </td>
        <td class="amt">{{ $money($item['total']) }}</td>
    </tr>
    @endforeach
</table>

<hr>

{{-- ── Totals ─────────────────────────────────────────────────────────── --}}
<div class="totals">
    <div class="row"><span>Subtotal</span><span class="v">{{ $money($sale->subtotal) }}</span></div>

    @if((float) $sale->discount_amount > 0)
    <div class="row"><span>Discount</span><span class="v">-{{ $money($sale->discount_amount) }}</span></div>
    @endif

    @if($vatEnabled && (float) $sale->vat_amount > 0)
    <div class="row"><span>VAT ({{ rtrim(rtrim(number_format((float) $vatRate, 2), '0'), '.') }}%)</span><span class="v">{{ $money($sale->vat_amount) }}</span></div>
    @endif

    <div class="row grand"><span>TOTAL</span><span class="v">{{ $money($sale->total) }}</span></div>
</div>

<hr>

{{-- ── Payment ────────────────────────────────────────────────────────── --}}
@if($payments->isNotEmpty())
    <div class="row"><span>Payment</span><span class="v">Split</span></div>
    @foreach($payments as $p)
    <div class="row">
        <span>{{ ucwords(str_replace('_', ' ', $p->method)) }}{{ $p->reference ? ' (' . $p->reference . ')' : '' }}</span>
        <span class="v">{{ $money($p->amount) }}</span>
    </div>
    @endforeach
@else
    <div class="row"><span>Payment</span><span class="v">{{ ucwords(str_replace('_', ' ', (string) $sale->payment_method)) }}</span></div>

    @if($sale->payment_method === 'cash')
        <div class="row"><span>Tendered</span><span class="v">{{ $money($sale->amount_tendered) }}</span></div>
    @endif

    @if($sale->payment_method === 'bank_transfer' && $sale->bank_transfer_reference)
        <div class="row"><span>Reference</span><span class="v">{{ $sale->bank_transfer_reference }}</span></div>
    @endif
@endif

@if((float) $sale->change_given > 0)
<div class="row"><span>Change</span><span class="v">{{ $money($sale->change_given) }}</span></div>
@endif

{{-- ── Footer ─────────────────────────────────────────────────────────── --}}
@if(trim((string) $settings->footer_text) !== '')
<hr>
<div class="footer {{ $falign }}">{{ $settings->footer_text }}</div>
@endif

{{-- QR to the customer's own copy. Printed last so a scanner is not hunting
     for it among the figures, and sized generously: a thermal head renders a
     small code as mush, and an unscannable QR is worse than none. --}}
@if($qrSvg)
<hr>
<div class="al-center qr-block">
    <div class="qr">{!! $qrSvg !!}</div>
    <p class="qr-caption">{{ $settings->qr_caption ?: 'Scan for your receipt' }}</p>
</div>
@endif

{{-- Blank lines so the cut lands clear of the text --}}
@for($i = 0; $i < (int) ($settings->feed_lines ?? 2); $i++)
<p class="feed">&nbsp;</p>
@endfor

@if($autoPrint)
<script>
    setTimeout(function () {
        window.print();
        // Close only when this page opened itself as a print window
        window.onafterprint = function () { if (window.opener) { window.close(); } };
    }, 250);
</script>
@endif

</body>
</html>
