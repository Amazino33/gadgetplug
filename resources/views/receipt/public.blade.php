{{--
    The customer's copy, opened by the QR on the printed receipt.

    Read on a phone, immediately after paying, often on a poor connection — so
    it is a single self-contained page with no build step, no framework and no
    external requests beyond the store's own banner.

    It never renders the customer's name or phone. The paper can be photographed
    or left on a counter, and whoever holds it is not necessarily who bought.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
{{-- The loyalty claim is a POST through the web middleware, so it needs the
     session's CSRF token — guests get a session too. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Receipt {{ $sale->reference }} — {{ $settings->displayName($vendor) }}</title>
<style>
    :root {
        --paper:#f4f6f4; --card:#ffffff; --ink:#14261a; --soft:#5d7063;
        --line:#e2e8e3; --brand:#068b03; --brand-ink:#046002; --gold:#b8860b;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --paper:#0e1511; --card:#16201a; --ink:#e8f0e8; --soft:#9db0a2;
            --line:#25332a; --brand:#4caf50; --brand-ink:#8fd98f; --gold:#e5b84b;
        }
    }
    * { box-sizing:border-box; }
    body {
        margin:0; background:var(--paper); color:var(--ink);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
        font-size:15px; line-height:1.5; -webkit-font-smoothing:antialiased;
    }
    .wrap { max-width:460px; margin:0 auto; padding:16px 14px 40px; }
    .card {
        background:var(--card); border:1px solid var(--line);
        border-radius:14px; padding:18px; margin-bottom:12px;
    }
    .store { text-align:center; }
    .store img { max-width:120px; max-height:56px; margin-bottom:8px; }
    .store h1 { margin:0; font-size:20px; font-weight:800; letter-spacing:-.01em; }
    .store p { margin:2px 0 0; font-size:13px; color:var(--soft); }
    .paid {
        display:inline-block; margin-top:10px; padding:4px 12px; border-radius:999px;
        background:color-mix(in srgb, var(--brand) 14%, transparent);
        color:var(--brand-ink); font-size:12px; font-weight:700;
    }
    .meta { display:flex; justify-content:space-between; gap:10px; font-size:13px; padding:4px 0; }
    .meta .k { color:var(--soft); }
    .meta .v { font-weight:600; text-align:right; }
    hr { border:0; border-top:1px solid var(--line); margin:12px 0; }
    .item { display:flex; justify-content:space-between; gap:10px; padding:7px 0; }
    .item .n { font-size:14px; }
    .item .q { font-size:12px; color:var(--soft); margin-top:1px; }
    .item .a { font-weight:600; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .tot { display:flex; justify-content:space-between; font-size:13px; padding:3px 0; color:var(--soft); }
    .tot.grand {
        font-size:19px; font-weight:800; color:var(--ink);
        border-top:2px solid var(--line); margin-top:8px; padding-top:10px;
    }
    .tot .v { font-variant-numeric:tabular-nums; }
    .btn {
        display:block; width:100%; text-align:center; padding:13px 16px; border-radius:12px;
        font-weight:700; font-size:15px; text-decoration:none; border:0; cursor:pointer;
        font-family:inherit;
    }
    .btn-primary { background:var(--brand); color:#fff; }
    .btn-ghost { background:transparent; color:var(--brand-ink); border:1.5px solid var(--brand); }
    .stack > * + * { margin-top:9px; }
    .banner { display:block; width:100%; border-radius:12px; overflow:hidden; }
    .banner img { width:100%; display:block; }

    /* Loyalty */
    .loyal { text-align:center; }
    .loyal h2 { margin:0 0 4px; font-size:17px; font-weight:800; }
    .loyal p { margin:0; font-size:13px; color:var(--soft); }
    .stamps { display:flex; flex-wrap:wrap; gap:7px; justify-content:center; margin:14px 0 12px; }
    .stamp {
        width:26px; height:26px; border-radius:50%; border:2px dashed var(--line);
        display:flex; align-items:center; justify-content:center; font-size:13px; color:var(--soft);
    }
    .stamp.on { border:2px solid var(--brand); background:var(--brand); color:#fff; }
    .prize { color:var(--gold); font-weight:800; }
    .hidden { display:none; }
    .foot { text-align:center; font-size:12px; color:var(--soft); padding:6px 2px 0; white-space:pre-line; }
</style>
</head>
<body>
<div class="wrap">

@php $money = fn ($n) => '&#8358;' . number_format((float) $n, 2); @endphp

<div class="card store">
    @if($logoUrl)<img src="{{ $logoUrl }}" alt="">@endif
    <h1>{{ $settings->displayName($vendor) }}</h1>
    @foreach($settings->headerLines() as $line)
        <p>{{ $line }}</p>
    @endforeach
    <span class="paid">Paid &middot; {{ $soldAt->format('j M Y, g:ia') }}</span>
</div>

<div class="card">
    <div class="meta"><span class="k">Receipt</span><span class="v">{{ $sale->reference }}</span></div>
    <div class="meta"><span class="k">Payment</span><span class="v">
        {{ $payments->isNotEmpty() ? 'Split' : ucwords(str_replace('_', ' ', (string) $sale->payment_method)) }}
    </span></div>

    <hr>

    @foreach($items as $item)
    <div class="item">
        <div>
            <div class="n">{{ $item['name'] }}</div>
            <div class="q">{{ $item['quantity'] }} &times; {!! $money($item['unit_price']) !!}</div>
        </div>
        <div class="a">{!! $money($item['total']) !!}</div>
    </div>
    @endforeach

    <hr>

    <div class="tot"><span>Subtotal</span><span class="v">{!! $money($sale->subtotal) !!}</span></div>
    @if((float) $sale->discount_amount > 0)
    <div class="tot"><span>Discount</span><span class="v">-{!! $money($sale->discount_amount) !!}</span></div>
    @endif
    @if($vatEnabled && (float) $sale->vat_amount > 0)
    <div class="tot"><span>VAT ({{ rtrim(rtrim(number_format((float) $vatRate, 2), '0'), '.') }}%)</span><span class="v">{!! $money($sale->vat_amount) !!}</span></div>
    @endif
    <div class="tot grand"><span>Total</span><span class="v">{!! $money($sale->total) !!}</span></div>
</div>

{{-- Loyalty. Progress is the till's own transaction count, so the number is
     real; a walk-in with no record gets the nudge to give their number. --}}
@if($settings->loyalty_enabled)
<div class="card loyal" id="loyalty">
    <h2>Your loyalty card</h2>
    <p id="loyalty-lead">
        Every {{ $settings->loyalty_goal }} purchases earns you
        <span class="prize">{{ $settings->loyalty_reward_text ?: 'a reward from us' }}</span>.
    </p>

    <div class="stamps hidden" id="stamps"></div>
    <p id="loyalty-msg" class="hidden"></p>

    <div style="margin-top:14px">
        <button class="btn btn-primary" id="claim">Mark my loyalty card</button>
    </div>
</div>
@endif

{{-- Store's own promo slot --}}
@if($bannerUrl)
<a class="banner" @if($settings->banner_link) href="{{ $settings->banner_link }}" target="_blank" rel="noopener" @endif>
    <img src="{{ $bannerUrl }}" alt="">
</a>
@endif

<div class="card stack">
    <a class="btn btn-primary" href="{{ route('receipt.public.pdf', $sale->public_token) }}">Save as PDF</a>

    @if($settings->cta_label && $settings->cta_link)
    <a class="btn btn-ghost" href="{{ $settings->cta_link }}" target="_blank" rel="noopener">{{ $settings->cta_label }}</a>
    @endif
</div>

@if(trim((string) $settings->footer_text) !== '')
<div class="foot">{{ $settings->footer_text }}</div>
@endif

</div>

@if($settings->loyalty_enabled)
<script>
(function () {
    var btn = document.getElementById('claim');
    if (!btn) return;

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.textContent = 'Marking…';

        fetch(@json(route('receipt.public.loyalty', $sale->public_token)), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            },
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var msg = document.getElementById('loyalty-msg');
            msg.classList.remove('hidden');

            if (!d.claimed) {
                msg.textContent = d.message || 'Could not mark your card just now.';
                btn.textContent = 'Mark my loyalty card';
                btn.disabled = false;
                return;
            }

            // Draw the card so progress is something you can see, not just read
            var wrap = document.getElementById('stamps');
            wrap.innerHTML = '';
            for (var i = 1; i <= d.goal; i++) {
                var s = document.createElement('span');
                s.className = 'stamp' + (i <= d.position ? ' on' : '');
                s.textContent = i <= d.position ? '✓' : i;
                wrap.appendChild(s);
            }
            wrap.classList.remove('hidden');

            document.getElementById('loyalty-lead').classList.add('hidden');

            if (d.earned) {
                msg.innerHTML = '<strong class="prize">Card full — ask for your ' + d.reward + ' on your next visit.</strong>';
            } else {
                msg.innerHTML = '<strong>' + d.position + ' of ' + d.goal + '</strong> &middot; only <strong>' +
                    d.to_go + '</strong> more to earn <span class="prize">' + d.reward + '</span>';
            }

            btn.textContent = 'Marked ✓';
        })
        .catch(function () {
            btn.textContent = 'Mark my loyalty card';
            btn.disabled = false;
        });
    });
})();
</script>
@endif

</body>
</html>
