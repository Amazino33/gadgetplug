{{--
    Where a scanned handover code lands.

    Read on a phone, standing in a shop, holding somebody else's money. So it is
    one self-contained page with no build step and nothing on it but the amount
    being claimed and the two answers — agreeing is the common case and must be
    one tap, disagreeing must be just as reachable and never the harder path.

    The submitter's name is shown deliberately: the whole value of the record is
    that two named people stand behind it, and the receiver should see whose
    account they are about to settle before they settle it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm cash handover</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px 16px;
            font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f4f4f5; color: #18181b;
            display: flex; justify-content: center;
        }
        .card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        .label { font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: #71717a; margin: 0 0 4px; }
        .amount { font-size: 40px; font-weight: 700; margin: 0 0 4px; letter-spacing: -.02em; }
        .meta { color: #52525b; font-size: 14px; margin: 0 0 20px; }
        .meta strong { color: #18181b; }
        .ref { font-family: ui-monospace, monospace; font-size: 12px; color: #a1a1aa; }
        button { width: 100%; padding: 14px; font-size: 16px; font-weight: 600; border: 0; border-radius: 10px; cursor: pointer; }
        .confirm { background: #16a34a; color: #fff; margin-bottom: 10px; }
        .dispute { background: #fff; color: #dc2626; border: 1px solid #fecaca; }
        .err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
        details { margin-top: 10px; }
        summary { cursor: pointer; color: #dc2626; font-size: 14px; }
        input, textarea { width: 100%; padding: 10px; border: 1px solid #d4d4d8; border-radius: 8px; font: inherit; margin-top: 8px; }
        .hint { font-size: 13px; color: #71717a; margin-top: 12px; }
    </style>
</head>
<body>
    <div class="card">
        @if (session('handoff_error'))
            <div class="err">{{ session('handoff_error') }}</div>
        @endif

        <p class="label">Being handed to you</p>
        <p class="amount">₦{{ number_format((float) $submission->amount, 2) }}</p>
        <p class="meta">
            from <strong>{{ $submission->submitter->name }}</strong>
            @if ($submission->store) at {{ $submission->store->name }} @endif
            <br><span class="ref">{{ $submission->reference }}</span>
        </p>

        <form method="POST" action="{{ route('cash.handoff.confirm', $token) }}">
            @csrf
            <button type="submit" class="confirm">I received ₦{{ number_format((float) $submission->amount, 2) }}</button>
        </form>

        <details>
            <summary>That's not what I received</summary>
            <form method="POST" action="{{ route('cash.handoff.dispute', $token) }}">
                @csrf
                <input type="number" step="0.01" min="0" name="disputed_amount" placeholder="What actually reached you (₦)">
                <textarea name="note" rows="2" required placeholder="What happened?"></textarea>
                <button type="submit" class="dispute" style="margin-top:10px">Record a dispute</button>
            </form>
        </details>

        {{-- Said plainly, because a receiver who taps confirm to be polite and
             sorts it out later has destroyed the only record of the disagreement. --}}
        <p class="hint">Count the money before you confirm. Once confirmed, this is the record.</p>
    </div>
</body>
</html>
