<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Invitation — GadgetPlug</title>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: sans-serif; background: #f9fafb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 24px; }
        .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); width: 100%; max-width: 420px; }
        h1 { font-size: 22px; font-weight: 700; color: #111827; margin: 0 0 8px; }
        .subtitle { color: #6b7280; font-size: 14px; margin: 0 0 24px; }
        .alert { margin-bottom: 16px; padding: 12px; background: #fee2e2; border: 1px solid #fca5a5; color: #dc2626; font-size: 13px; border-radius: 8px; }
        .field { margin-bottom: 16px; }
        label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px; }
        input[type=text], input[type=email], input[type=password] {
            width: 100%; padding: 10px 12px; border: 1.5px solid #d1d5db; border-radius: 8px;
            font-size: 13px; color: #111827; outline: none; transition: border-color .15s;
        }
        input:focus { border-color: #068B03; }
        .error { color: #dc2626; font-size: 12px; margin-top: 4px; }
        .btn { width: 100%; background: #068B03; color: white; padding: 12px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 8px; transition: background .15s; }
        .btn:hover { background: #055002; }
        /* Checklist */
        .checklist { margin-top: 8px; background: #f9fafb; border: 1.5px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; display: flex; flex-direction: column; gap: 6px; }
        .check-item { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 500; transition: color .15s; }
        .check-item.pass { color: #068B03; }
        .check-item.fail { color: #9ca3af; }
        .check-item svg { flex-shrink: 0; }
        .pw-wrap { position: relative; }
        .pw-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #9ca3af; padding: 0; display: flex; }
        .pw-toggle:hover { color: #068B03; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Accept Invitation</h1>
        <p class="subtitle">You've been invited to join a vendor team on GadgetPlug.</p>

        <form method="POST" action="{{ route('vendor.invite.store', $token) }}">
            @csrf

            @if(session('error'))
                <div class="alert">{{ session('error') }}</div>
            @endif

            @if($errors->any())
                <div class="alert">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="field">
                <label>Email</label>
                <input type="email" value="{{ $email }}" disabled style="background:#f3f4f6;color:#6b7280;">
            </div>

            @if(!$userExists)
                <div class="field">
                    <label>Your Name</label>
                    <input type="text" name="name" required placeholder="Full name" value="{{ old('name') }}">
                    @error('name')<span class="error">{{ $message }}</span>@enderror
                </div>

                {{-- Password with checklist --}}
                <div class="field" x-data="{
                    pwd: '',
                    show: false,
                    get minLen()    { return this.pwd.length >= 8 },
                    get hasLower()  { return /[a-z]/.test(this.pwd) },
                    get hasUpper()  { return /[A-Z]/.test(this.pwd) },
                    get hasNum()    { return /[0-9]/.test(this.pwd) },
                    get hasSymbol() { return /[^a-zA-Z0-9]/.test(this.pwd) },
                    get allPassed() { return this.minLen && this.hasLower && this.hasUpper && this.hasNum && this.hasSymbol },
                }">
                    <label>Password</label>
                    <div class="pw-wrap">
                        <input :type="show ? 'text' : 'password'" name="password" x-model="pwd" required
                            placeholder="At least 8 characters" style="padding-right:36px;"
                            :style="pwd.length && !allPassed ? 'border-color:#f87171' : pwd.length && allPassed ? 'border-color:#068B03' : ''">
                        <button type="button" class="pw-toggle" @click="show = !show" tabindex="-1">
                            <svg x-show="!show" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg x-show="show"  width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                    @error('password')<span class="error">{{ $message }}</span>@enderror

                    <div x-show="pwd.length > 0" class="checklist" style="display:none">
                        @foreach([
                            ['get' => 'minLen',    'label' => 'At least 8 characters'],
                            ['get' => 'hasLower',  'label' => 'One lowercase letter (a–z)'],
                            ['get' => 'hasUpper',  'label' => 'One uppercase letter (A–Z)'],
                            ['get' => 'hasNum',    'label' => 'One number (0–9)'],
                            ['get' => 'hasSymbol', 'label' => 'One special character (!@#$…)'],
                        ] as $rule)
                        <div class="check-item" :class="{{ $rule['get'] }} ? 'pass' : 'fail'">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path x-show="{{ $rule['get'] }}"  stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                <path x-show="!{{ $rule['get'] }}" stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            {{ $rule['label'] }}
                        </div>
                        @endforeach
                    </div>
                </div>

                <div class="field">
                    <label>Confirm Password</label>
                    <div class="pw-wrap">
                        <input type="password" name="password_confirmation" required placeholder="Repeat your password" style="padding-right:36px;">
                    </div>
                </div>
            @endif

            <button type="submit" class="btn">Accept &amp; Join Team</button>
        </form>
    </div>
</body>
</html>
