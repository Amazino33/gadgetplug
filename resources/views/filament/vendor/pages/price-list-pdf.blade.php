{{--
    A4 price list, built for density: three columns of name/price pairs at 7pt.

    Two constraints shape this file, both learned the hard way:

    1. dompdf implements CSS 2.1 — no column-count, flexbox or grid. The columns
       are therefore <td> cells, prepared in PriceList::buildPages().
    2. dompdf splits an overflowing table row very badly. So each page is its own
       self-contained table with an explicit page break between pages, and never
       relies on the engine to break a row. Do not merge these into one table.

    Avoid adding modern CSS here; it will silently do nothing.
--}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { size: A4 portrait; margin: 8mm; }

    body {
        font-family: DejaVu Sans, sans-serif;  /* ships with dompdf; has ₦ */
        font-size: 7pt;
        color: #111;
        margin: 0;
    }

    .sheet { page-break-after: always; }
    .sheet.last { page-break-after: auto; }

    .masthead { border-bottom: 1.2pt solid #111; padding-bottom: 1.2mm; margin-bottom: 1.8mm; }
    .store    { font-size: 12pt; font-weight: bold; }
    .subtitle { font-size: 6.5pt; color: #555; margin-top: 0.5mm; }

    table.layout { width: 100%; border-collapse: collapse; }
    table.layout td.col { vertical-align: top; width: 32%; }
    table.layout td.gutter { width: 2%; }

    /* Font size is repeated on the table and cells on purpose: dompdf does not
       reliably inherit body font-size into table cells, and without this the
       rows render at the default size and less than half as much fits. */
    table.items { width: 100%; border-collapse: collapse; font-size: 7pt; line-height: 1; }
    table.items td { font-size: 7pt; line-height: 1; }

    .cat td {
        font-size: 6.5pt;
        font-weight: bold;
        background: #eceff1;
        padding: 0.7mm 1mm;
        border-bottom: 0.5pt solid #b0bec5;
    }

    .item td { padding: 0.15mm 1mm; border-bottom: 0.25pt solid #eee; }
    .item .price { text-align: right; white-space: nowrap; font-weight: bold; }

    .out td { color: #90a4ae; }

    .footnote { margin-top: 1.8mm; border-top: 0.5pt solid #ccc; padding-top: 0.8mm; font-size: 6pt; color: #666; }
</style>
</head>
<body>

@foreach($pages as $pageIndex => $columns)
<div class="sheet {{ $loop->last ? 'last' : '' }}">

    <div class="masthead">
        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td class="store">{{ $vendor->name }} — Price List</td>
                <td style="text-align:right; font-size:6.5pt; color:#555;">
                    {{ $generatedAt->format('j M Y, g:ia') }}
                    @if(count($pages) > 1) · page {{ $pageIndex + 1 }} of {{ count($pages) }} @endif
                </td>
            </tr>
        </table>
        <div class="subtitle">
            {{ $total }} {{ Str::plural('product', $total) }} · prices in Naira (₦) · greyed items are out of stock
        </div>
    </div>

    <table class="layout">
        <tr>
            @foreach($columns as $i => $column)
                @if($i > 0)<td class="gutter"></td>@endif
                <td class="col">
                    <table class="items">
                        @foreach($column as $row)
                            @if($row['type'] === 'header')
                                <tr class="cat"><td colspan="2">{{ $row['text'] }}</td></tr>
                            @else
                                <tr class="item {{ $row['out'] ? 'out' : '' }}">
                                    <td>{{ $row['name'] }}{{ $row['out'] ? ' (out)' : '' }}</td>
                                    <td class="price">{{ $row['price'] }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </table>
                </td>
            @endforeach
        </tr>
    </table>

    @if($loop->last)
    <div class="footnote">
        Prices are live as of the time above and may change — always confirm at the till before quoting.
        Generated from {{ config('app.name') }}.
    </div>
    @endif

</div>
@endforeach

</body>
</html>
