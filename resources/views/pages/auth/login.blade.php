<x-layouts.auth-storefront title="Sign In">

    <div class="mb-6 text-center">
        <h1 class="font-montserrat font-black text-[20px] text-brand-dark dark:text-[#e8f5e9]">Welcome back</h1>
        <p class="text-[12px] text-brand-muted mt-1">Sign in to your GadgetPlug account</p>
    </div>

    {{-- Session status --}}
    @if (session('status'))
    <div class="mb-4 bg-[#f0f8f0] dark:bg-[#1a2a1a] border border-brand/30 rounded-xl px-4 py-2.5 text-[12px] text-brand font-medium">
        {{ session('status') }}
    </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <div>
            <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Email Address</label>
            <input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                placeholder="you@example.com"
                class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('email') border-red-400 @enderror">
            @error('email')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <div class="flex items-center justify-between mb-1.5">
                <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px]">Password</label>
                @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-[11px] text-brand hover:underline">Forgot password?</a>
                @endif
            </div>
            <input name="password" type="password" required autocomplete="current-password"
                placeholder="••••••••"
                class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('password') border-red-400 @enderror">
            @error('password')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="flex items-center gap-2">
            <input name="remember" type="checkbox" id="remember" {{ old('remember') ? 'checked' : '' }}
                class="w-4 h-4 rounded border-[#d0d9d2] text-brand focus:ring-brand">
            <label for="remember" class="text-[12px] text-brand-muted">Remember me</label>
        </div>

        <button type="submit"
            class="w-full h-11 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors mt-2">
            Sign In
        </button>
    </form>

    @if (Route::has('register'))
    <p class="mt-5 text-center text-[12px] text-brand-muted">
        Don't have an account?
        <a href="{{ route('register') }}" class="text-brand font-semibold hover:underline">Create one</a>
    </p>
    @endif

</x-layouts.auth-storefront>
