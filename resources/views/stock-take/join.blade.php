<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#0F766E">
    <title>{{ $vendor->name }} — Stock Take sign-in</title>
    <style>
        :root { --ink:#111827; --muted:#4b5563; --line:#d1d5db; --bg:#f9fafb; --card:#ffffff; --brand:#0f766e; --brandInk:#ffffff; --bad:#b91c1c; --badBg:#fef2f2; }
        @media (prefers-color-scheme: dark) {
            :root { --ink:#f3f4f6; --muted:#9ca3af; --line:#374151; --bg:#030712; --card:#111827; --brand:#14b8a6; --brandInk:#042f2e; --bad:#fca5a5; --badBg:#450a0a; }
        }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100dvh; background:var(--bg); color:var(--ink); font:18px/1.4 system-ui,-apple-system,'Segoe UI',sans-serif; display:flex; flex-direction:column; }
        header { background:#0f766e; color:#fff; text-align:center; padding:8px 12px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; font-size:14px; }
        main { width:100%; max-width:420px; margin:0 auto; padding:20px 16px 32px; }
        h1 { font-size:24px; margin:8px 0 2px; }
        p.lead { margin:0 0 20px; color:var(--muted); }
        label { display:block; font-weight:700; margin:16px 0 6px; }
        select, input { width:100%; min-height:56px; padding:10px 14px; font-size:20px; color:var(--ink); background:var(--card); border:2px solid var(--line); border-radius:14px; }
        input.code { letter-spacing:.4em; text-align:center; font-size:28px; font-weight:800; }
        input.code::placeholder { letter-spacing:0; font-size:18px; font-weight:500; }
        select:focus, input:focus { outline:none; border-color:var(--brand); }
        button { width:100%; min-height:60px; margin-top:24px; font-size:20px; font-weight:800; color:var(--brandInk); background:var(--brand); border:0; border-radius:16px; }
        .error { background:var(--badBg); color:var(--bad); border-radius:12px; padding:12px 14px; margin:0 0 8px; font-weight:600; }
        .none { background:var(--card); border:2px dashed var(--line); border-radius:14px; padding:16px; color:var(--muted); }
    </style>
</head>
<body>
    <header>Stock Take</header>
    <main>
        <h1>{{ $vendor->name }}</h1>
        <p class="lead">Pick your name and type your 6-digit code.</p>

        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif

        @if ($counters->isEmpty())
            <p class="none">No one has been set up to count yet. Ask your manager to add you.</p>
        @else
            <form method="POST" action="{{ route('stock-take.join.attempt', ['vendor' => $vendor->slug, 'k' => $key]) }}">
                @csrf
                <input type="hidden" name="k" value="{{ $key }}">

                <label for="counter">Your name</label>
                <select id="counter" name="counter" required>
                    <option value="" disabled @selected(! old('counter'))>Pick your name</option>
                    @foreach ($counters as $counter)
                        <option value="{{ $counter->id }}" @selected((int) old('counter') === $counter->id)>{{ $counter->name }}</option>
                    @endforeach
                </select>

                <label for="code">Your code</label>
                <input id="code" class="code" name="code" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" placeholder="6-digit code" required>

                <button type="submit">Start counting</button>
            </form>
        @endif
    </main>
</body>
</html>
