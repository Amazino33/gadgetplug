<x-layouts.auth-storefront title="Become an Affiliate">

    <div x-data="{ show: false }" x-init="setTimeout(() => show = true, 50)" class="text-center">

        <div x-show="show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-3"
             x-transition:enter-end="opacity-100 translate-y-0">

            {{-- Badge --}}
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand/10 dark:bg-brand/20 border border-brand/20 text-brand text-[11px] font-semibold uppercase tracking-wider mb-4">
                <span>🤝</span> GadgetPlug Affiliate
            </div>

            {{-- Headline --}}
            <h1 class="font-montserrat font-black text-[22px] sm:text-[24px] text-brand-dark dark:text-[#e8f5e9] tracking-tight leading-snug mb-2">
                Earn with GadgetPlug
            </h1>

            {{-- Short Pitch (easy to edit) --}}
            <p class="text-[13px] text-brand-muted leading-relaxed mb-6">
                Earn with GadgetPlug — up to ₦10,000/week. No fees, no capital.
            </p>

            {{-- Value Props --}}
            <div class="grid grid-cols-3 gap-2 mb-6 p-3 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2]/60 dark:border-[#2a3a2a] rounded-xl text-center">
                <div>
                    <div class="text-[18px]">💰</div>
                    <p class="text-[10px] font-semibold text-brand-dark dark:text-[#e8f5e9] mt-1">Real Cash</p>
                </div>
                <div>
                    <div class="text-[18px]">⚡</div>
                    <p class="text-[10px] font-semibold text-brand-dark dark:text-[#e8f5e9] mt-1">Fast Payouts</p>
                </div>
                <div>
                    <div class="text-[18px]">📱</div>
                    <p class="text-[10px] font-semibold text-brand-dark dark:text-[#e8f5e9] mt-1">Zero Capital</p>
                </div>
            </div>

            {{-- Action Buttons --}}
            <div class="space-y-3">
                <a href="{{ route('register') }}"
                   class="w-full min-h-[48px] px-5 bg-brand hover:bg-[#055002] active:scale-[0.98] text-white font-montserrat font-bold text-[13px] rounded-xl shadow-md hover:shadow-lg transition-all duration-150 flex items-center justify-center gap-2 group">
                    <span>I'm new — Create account</span>
                    <svg class="w-4 h-4 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                    </svg>
                </a>

                <a href="{{ route('login') }}"
                   class="w-full min-h-[48px] px-5 bg-transparent hover:bg-brand-bg dark:hover:bg-[#1a2a1a] active:scale-[0.98] text-brand-dark dark:text-[#e8f5e9] hover:text-brand font-montserrat font-semibold text-[13px] border border-[#d0d9d2] dark:border-[#2a3a2a] hover:border-brand rounded-xl transition-all duration-150 flex items-center justify-center gap-2">
                    <span>I already have an account — Log in</span>
                </a>
            </div>

            <p class="mt-5 text-[11px] text-brand-muted">
                Takes less than a minute · Free to join
            </p>

        </div>

    </div>

</x-layouts.auth-storefront>
