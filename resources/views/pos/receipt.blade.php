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
        font-size: 13px;
        line-height: 1.4;
        width: 72mm;          /* 80mm paper minus the printer's own margins */
        margin: 0 auto;

        /* Everything is bold on purpose. A thermal head burns dots: it has no
           grey, so the driver halftones anti-aliased edges and thin strokes come
           out banded and broken — which is what a light Courier at 11px does on
           real paper. Bold gives every stroke enough width to survive that.
           Monospace advance widths do not change when bold, so the columns hold. */
        font-weight: bold;

        /* Stops the browser lightening anything on its way to the driver, which
           would hand it more grey to halftone. */
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .al-left   { text-align: left; }
    .al-center { text-align: center; }
    .al-right  { text-align: right; }

    .store-name {
        font-size: 19px;
        font-weight: bold;
        letter-spacing: 1px;
        margin: 0 0 1mm;
        text-transform: uppercase;
        line-height: 1.15;
    }
    .header-line { margin: 0; font-size: 12px; }

    .logo { max-width: 40mm; max-height: 18mm; margin: 0 auto 4px; display: block; }

    /* Solid black hairline, never a grey or a dashed one. A thermal head is
       1-bit: any grey is dithered into a speckled line, and dashes at 203dpi
       come out chewed. Weight is varied with thickness instead of colour. */
    hr {
        border: 0;
        border-top: 1px solid #000;
        margin: 2mm 0;
    }

    /* Label/value rows — the label is allowed to wrap, the value never is. */
    .row {
        display: flex;
        justify-content: space-between;
        gap: 4mm;
        font-size: 12px;
        padding: .3mm 0;
    }
    .row .v { white-space: nowrap; text-align: right; }

    /* One item = a full-width name, then its money on the line below. Three
       columns across 72mm leave the amount about 20mm, which is fine for
       44,000.00 and breaks 1,122,300.00 across two lines. */
    .items { margin: 1mm 0; }
    .item { margin-bottom: 1.6mm; }
    .item-name { word-break: break-word; font-size: 13px; }
    .item-line {
        display: flex;
        justify-content: space-between;
        gap: 4mm;
        font-size: 12px;
    }
    .item-qty { color: #000; }
    .item-amt { white-space: nowrap; font-weight: bold; }

    .totals .row { font-size: 12px; }
    .grand {
        font-size: 17px;
        font-weight: bold;
        border-top: 2px solid #000;
        border-bottom: 2px solid #000;
        padding: 1.2mm 0;
        margin-top: 1.5mm;
    }

    .footer { margin-top: 2.5mm; font-size: 12px; white-space: pre-line; line-height: 1.4; }

    /* 30mm square. Smaller than this and a thermal head's dot size starts
       eating the finder patterns, which is when phones stop reading it. */
    .qr-block { margin-top: 3mm; }
    /* Inline SVG rather than an <img> data URI, so the code stays vector all
       the way into the print raster instead of being rasterised at screen dpi
       and upscaled by the printer. */
    .qr { width: 30mm; height: 30mm; margin: 0 auto 2px; }
    .qr svg { width: 100%; height: 100%; display: block; }
    .qr-caption { margin: .5mm 0 0; font-size: 11px; }
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

{{-- ── Items ──────────────────────────────────────────────────────────────
     The name gets the full width and the money sits on its own line beneath it.
     Squeezing name, quantity and amount into three columns of 72mm leaves the
     amount barely 20mm — enough for 44,000.00 but not 1,122,300.00, which then
     wraps mid-number. Two lines per item is more paper and far more readable. --}}
<div class="items">
    @foreach($items as $item)
    <div class="item">
        <div class="item-name">{{ $item['name'] }}</div>
        <div class="item-line">
            <span class="item-qty">
                {{ $item['quantity'] }}
                @if($settings->show_item_unit_price)
                    &times; {{ $money($item['unit_price']) }}
                @endif
            </span>
            <span class="item-amt">{{ $money($item['total']) }}</span>
        </div>
    </div>
    @endforeach
</div>

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
