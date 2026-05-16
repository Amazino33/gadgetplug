<x-layouts::auth :title="__('Reset password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <flux:input
                name="email"
                value="{{ request('email') }}"
                :label="__('Email')"
                type="email"
                required
                autocomplete="email"
            />

            {{-- Password with strength checklist --}}
            <div x-data="{
                pwd: '',
                show: false,
                get minLen()    { return this.pwd.length >= 8 },
                get hasLower()  { return /[a-z]/.test(this.pwd) },
                get hasUpper()  { return /[A-Z]/.test(this.pwd) },
                get hasNum()    { return /[0-9]/.test(this.pwd) },
                get hasSymbol() { return /[^a-zA-Z0-9]/.test(this.pwd) },
                get allPassed() { return this.minLen && this.hasLower && this.hasUpper && this.hasNum && this.hasSymbol },
            }">
                <flux:input
                    name="password"
                    :label="__('New Password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    placeholder="At least 8 characters"
                    viewable
                    x-model="pwd"
                />

                <div x-show="pwd.length > 0"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="mt-2 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 px-3.5 py-3 space-y-1.5"
                     style="display:none">
                    @foreach([
                        ['get' => 'minLen',    'label' => 'At least 8 characters'],
                        ['get' => 'hasLower',  'label' => 'One lowercase letter (a–z)'],
                        ['get' => 'hasUpper',  'label' => 'One uppercase letter (A–Z)'],
                        ['get' => 'hasNum',    'label' => 'One number (0–9)'],
                        ['get' => 'hasSymbol', 'label' => 'One special character (!@#$…)'],
                    ] as $rule)
                    <div class="flex items-center gap-2 text-[11px] font-medium transition-colors"
                         :class="{{ $rule['get'] }} ? 'text-green-600 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500'">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path x-show="{{ $rule['get'] }}"  stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            <path x-show="!{{ $rule['get'] }}" stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        {{ $rule['label'] }}
                    </div>
                    @endforeach
                </div>
            </div>

            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                viewable
            />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Reset password') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
