{{--
    A suspended free shop sees only this. Standalone, like vendor/blocked: the
    panel is exactly what this person may not load.
--}}
@php
    // GadgetPlug's own support WhatsApp, resolved the way checkout resolves it:
    // SUPPORT_WHATSAPP first, then the platform storekeeper number.
    $support = \App\Support\WhatsAppNumber::normalise(
        config('app.support_whatsapp') ?: \App\Models\PlatformMessagingSetting::current()->fallback_storekeeper_whatsapp
    );
    $message = 'Hi '.config('app.name').', my shop '.$vendor->name.' ('.$vendor->shopLinkLabel().') has been suspended. Please help.';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Suspended — {{ config('app.name') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: sans-serif; background: #f9fafb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 16px; }
        .card { background: white; padding: 32px 24px; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); width: 100%; max-width: 480px; text-align: center; }
        .badge { width: 56px; height: 56px; border-radius: 50%; background: #fee2e2; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        h1 { font-size: 20px; font-weight: 700; color: #111827; margin: 0 0 8px; }
        .store { color: #6b7280; font-size: 13px; margin: 0 0 20px; }
        .next { color: #374151; font-size: 15px; line-height: 1.6; margin: 0 0 24px; }
        .reason { text-align: left; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 14px; line-height: 1.55; border-radius: 8px; padding: 14px 16px; margin-bottom: 20px; }
        .actions { display: flex; flex-direction: column; gap: 10px; }
        .btn { display: flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 18px; border-radius: 8px; font-size: 15px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; font-family: inherit; width: 100%; }
        .btn-wa { background: #25D366; color: white; }
        .btn-ghost { background: #f3f4f6; color: #374151; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>

        <h1>Your shop has been suspended.</h1>
        <p class="store">{{ $vendor->name }}</p>

        @if (filled($vendor->review_note))
            <div class="reason">{{ $vendor->review_note }}</div>
        @endif

        @if ($support)
            <p class="next">Contact {{ config('app.name') }} support on WhatsApp: <strong>{{ \App\Support\WhatsAppNumber::local($support) }}</strong></p>
        @else
            <p class="next">Please contact {{ config('app.name') }} support to find out more.</p>
        @endif

        <div class="actions">
            @if ($support)
                <a class="btn btn-wa" href="{{ \App\Support\WhatsAppNumber::link($support, $message) }}" target="_blank" rel="noopener">Chat with support on WhatsApp</a>
            @endif
            <form method="POST" action="{{ route('logout') }}" style="margin:0">
                @csrf
                <button type="submit" class="btn btn-ghost">Sign out</button>
            </form>
        </div>
    </div>
</body>
</html>
