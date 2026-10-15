{{--
    Shown when a receipt's QR is scanned before the sale has reached the server.

    The till prints the code offline, so a customer can legitimately scan it
    minutes before the sale syncs. A bare 404 would read as a fake receipt; this
    says what is actually going on. Same palette as the receipt it will become.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Receipt on its way</title>
<style>
    :root { --paper:#f4f6f4; --card:#ffffff; --ink:#14261a; --soft:#5d7063; --line:#e2e8e3; --brand:#068b03; }
    @media (prefers-color-scheme: dark) {
        :root { --paper:#0e1511; --card:#16201a; --ink:#e8f0e8; --soft:#9db0a2; --line:#25332a; --brand:#4caf50; }
    }
    * { box-sizing:border-box; }
    body {
        margin:0; background:var(--paper); color:var(--ink);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
        font-size:15px; line-height:1.5; -webkit-font-smoothing:antialiased;
    }
    .wrap { max-width:460px; margin:0 auto; padding:48px 14px; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:28px 20px; text-align:center; }
    .dot { width:44px; height:44px; margin:0 auto 14px; border-radius:50%; border:4px solid var(--line); border-top-color:var(--brand); animation:spin 1s linear infinite; }
    @media (prefers-reduced-motion: reduce) { .dot { animation:none; } }
    @keyframes spin { to { transform:rotate(360deg); } }
    h1 { margin:0 0 6px; font-size:19px; font-weight:800; }
    p { margin:0; color:var(--soft); font-size:14px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="dot" aria-hidden="true"></div>
        <h1>This receipt will be available shortly</h1>
        <p>Please check back in a few minutes.</p>
    </div>
</div>
</body>
</html>
