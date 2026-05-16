{{--
    Usage: <x-password-checklist name="password" />
    Props:
      name        – input name attribute (default: "password")
      placeholder – input placeholder
      inputClass  – extra classes for the <input> element
--}}
@props([
    'name'        => 'password',
    'placeholder' => 'Enter password',
    'inputClass'  => '',
])

<div x-data="{
    pwd: '',
    show: false,
    get minLen()   { return this.pwd.length >= 8 },
    get hasLower() { return /[a-z]/.test(this.pwd) },
    get hasUpper() { return /[A-Z]/.test(this.pwd) },
    get hasNum()   { return /[0-9]/.test(this.pwd) },
    get hasSymbol(){ return /[^a-zA-Z0-9]/.test(this.pwd) },
    get allPassed(){ return this.minLen && this.hasLower && this.hasUpper && this.hasNum && this.hasSymbol },
}">

    {{-- Input + show/hide toggle --}}
    <div class="relative">
        <input
            :type="show ? 'text' : 'password'"
            name="{{ $name }}"
            x-model="pwd"
            required
            autocomplete="new-password"
            placeholder="{{ $placeholder }}"
            :class="pwd.length && !allPassed
                ? '!border-red-400 focus:!border-red-400'
                : pwd.length && allPassed
                    ? '!border-brand focus:!border-brand'
                    : ''"
            class="w-full h-11 px-3.5 pr-10 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors {{ $inputClass }} @error($name) !border-red-400 @enderror"
        >
        {{-- Show/hide toggle --}}
        <button type="button" @click="show = !show" tabindex="-1"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-brand-muted hover:text-brand transition-colors focus:outline-none">
            <svg x-show="!show" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
            <svg x-show="show" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
            </svg>
        </button>
    </div>

    @error($name)
        <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>
    @enderror

    {{-- Checklist --}}
    <div x-show="pwd.length > 0"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="mt-2.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl px-3.5 py-3 space-y-1.5"
         style="display:none">

        @foreach([
            ['get' => 'minLen',   'label' => 'At least 8 characters'],
            ['get' => 'hasLower', 'label' => 'One lowercase letter (a–z)'],
            ['get' => 'hasUpper', 'label' => 'One uppercase letter (A–Z)'],
            ['get' => 'hasNum',   'label' => 'One number (0–9)'],
            ['get' => 'hasSymbol','label' => 'One special character (!@#$…)'],
        ] as $rule)
        <div class="flex items-center gap-2"
             :class="{{ $rule['get'] }} ? 'text-brand' : 'text-[#aaa] dark:text-[#555]'">
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path x-show="{{ $rule['get'] }}" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                <path x-show="!{{ $rule['get'] }}" stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            <span class="text-[11px] font-medium">{{ $rule['label'] }}</span>
        </div>
        @endforeach
    </div>
</div>
