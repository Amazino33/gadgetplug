{{--
    The answer, confirmed back to the person who gave it.

    A dispute gets the same weight of page as a confirmation rather than an
    error treatment: disagreeing is a legitimate, expected answer, and making it
    feel like a failure is how people get talked into confirming instead.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $disputed ? 'Dispute recorded' : 'Handover confirmed' }}</title>
    <style>
        body { margin:0; padding:24px 16px; font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
               background:#f4f4f5; color:#18181b; display:flex; justify-content:center; }
        .card { width:100%; max-width:420px; background:#fff; border-radius:16px; padding:32px 24px; text-align:center;
                box-shadow:0 1px 3px rgba(0,0,0,.1); }
        .mark { width:56px; height:56px; border-radius:50%; margin:0 auto 16px; display:flex; align-items:center;
                justify-content:center; font-size:28px; }
        .ok { background:#dcfce7; color:#16a34a; }
        .flag { background:#fef3c7; color:#b45309; }
        h1 { font-size:20px; margin:0 0 8px; }
        p { color:#52525b; margin:0 0 6px; }
        .ref { font-family:ui-monospace,monospace; font-size:12px; color:#a1a1aa; }
    </style>
</head>
<body>
    <div class="card">
        <div class="mark {{ $disputed ? 'flag' : 'ok' }}">{{ $disputed ? '!' : '✓' }}</div>

        @if ($disputed)
            <h1>Dispute recorded</h1>
            <p>
                {{ $submission->submitter->name }} claimed
                ₦{{ number_format((float) $submission->amount, 2) }}@if ($submission->disputed_amount !== null),
                you recorded ₦{{ number_format((float) $submission->disputed_amount, 2) }}@endif.
            </p>
            {{-- Stated outright so nobody leaves thinking it is settled. --}}
            <p>Both figures are kept. The money stays on {{ $submission->submitter->name }}'s account until it is sorted out in person.</p>
        @else
            <h1>Handover confirmed</h1>
            <p>You received ₦{{ number_format((float) $submission->amount, 2) }} from {{ $submission->submitter->name }}.</p>
            <p>It is off their account and on yours.</p>
        @endif

        <p class="ref">{{ $submission->reference }}</p>
    </div>
</body>
</html>
