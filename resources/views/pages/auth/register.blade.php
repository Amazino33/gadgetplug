<x-layouts.auth-storefront title="Create Account">

    <div class="mb-6 text-center">
        <h1 class="font-montserrat font-black text-[20px] text-brand-dark dark:text-[#e8f5e9]">Create an account</h1>
        <p class="text-[12px] text-brand-muted mt-1">Join GadgetPlug — Nigeria's #1 tech marketplace</p>
    </div>

    {{-- Session status --}}
    @if (session('status'))
    <div class="mb-4 bg-[#f0f8f0] dark:bg-[#1a2a1a] border border-brand/30 rounded-xl px-4 py-2.5 text-[12px] text-brand font-medium">
        {{ session('status') }}
    </div>
    @endif

    <form method="POST" action="{{ route('register.store') }}" class="space-y-4">
        @csrf

        <div>
            <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Full Name</label>
            <input name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name"
                placeholder="Your full name"
                class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('name') border-red-400 @enderror">
            @error('name')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Email Address</label>
            <input name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                placeholder="you@example.com"
                class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('email') border-red-400 @enderror">
            @error('email')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Password</label>
            <input name="password" type="password" required autocomplete="new-password"
                placeholder="At least 8 characters"
                class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('password') border-red-400 @enderror">
            @error('password')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Confirm Password</label>
            <input name="password_confirmation" type="password" required autocomplete="new-password"
                placeholder="Repeat your password"
                class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('password_confirmation') border-red-400 @enderror">
            @error('password_confirmation')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
        </div>

        <button type="submit"
            class="w-full h-11 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors mt-2">
            Create Account
        </button>
    </form>

    <p class="mt-5 text-center text-[12px] text-brand-muted">
        Already have an account?
        <a href="{{ route('login') }}" class="text-brand font-semibold hover:underline">Sign in</a>
    </p>

</x-layouts.auth-storefront>
