{{--
    A code that has expired, been used, or never existed.

    Deliberately does not say which. A page that distinguished "already used"
    from "never existed" would let somebody probe for live codes, and the person
    who legitimately needs one only ever needs the same answer: ask for a new one.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Code no longer valid</title>
    <style>
        body { margin:0; padding:24px 16px; font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
               background:#f4f4f5; color:#18181b; display:flex; justify-content:center; }
        .card { width:100%; max-width:420px; background:#fff; border-radius:16px; padding:32px 24px; text-align:center;
                box-shadow:0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size:20px; margin:0 0 8px; }
        p { color:#52525b; margin:0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>This code is no longer valid</h1>
        <p>{{ $message }}</p>
    </div>
</body>
</html>
