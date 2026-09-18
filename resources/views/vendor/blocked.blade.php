{{--
    Shown in place of the vendor panel and the POS till while an account is
    blocked. Deliberately a plain standalone page rather than a Filament error
    screen: the panel is exactly what this person may not load, and rendering it
    to say so would mean booting the thing we are keeping them out of.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Suspended — {{ config('app.name') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: sans-serif; background: #f9fafb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 24px; }
        .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); width: 100%; max-width: 480px; text-align: center; }
        .badge { width: 56px; height: 56px; border-radius: 50%; background: #fee2e2; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        h1 { font-size: 22px; font-weight: 700; color: #111827; margin: 0 0 8px; }
        .store { color: #6b7280; font-size: 13px; margin: 0 0 20px; }
        .reason { text-align: left; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 14px; line-height: 1.55; border-radius: 8px; padding: 14px 16px; margin-bottom: 20px; }
        .reason-label { display: block; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #dc2626; margin-bottom: 6px; }
        .next { color: #4b5563; font-size: 14px; line-height: 1.6; margin: 0 0 24px; }
        .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 11px 18px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; font-family: inherit; }
        .btn-primary { background: #068B03; color: white; }
        .btn-primary:hover { background: #055002; }
        .btn-ghost { background: #f3f4f6; color: #374151; }
        .btn-ghost:hover { background: #e5e7eb; }
        .since { color: #9ca3af; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>

        <h1>Account access suspended</h1>
        <p class="store">{{ $vendor->name }}</p>

        <div class="reason">
            <span class="reason-label">Reason</span>
            {{ $vendor->dashboardBlockMessage() }}
        </div>

        <p class="next">
            Your dashboard and point of sale are unavailable until this is resolved.
            Please contact the {{ config('app.name') }} team to settle it — access is
            restored as soon as an administrator lifts the suspension.
        </p>

        <div class="actions">
            <a class="btn btn-ghost" href="{{ route('home') }}">Back to {{ config('app.name') }}</a>
            <form method="POST" action="{{ route('logout') }}" style="margin:0">
                @csrf
                <button type="submit" class="btn btn-primary">Sign out</button>
            </form>
        </div>

        @if ($vendor->dashboard_blocked_at)
            <p class="since">Suspended {{ $vendor->dashboard_blocked_at->diffForHumans() }}</p>
        @endif
    </div>
</body>
</html>
