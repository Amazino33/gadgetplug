<x-layouts.auth-storefront title="Become an Affiliate" :app-mode="true">

    <div class="min-h-screen sm:min-h-screen sm:py-8 sm:px-4 flex flex-col items-center justify-center relative overflow-hidden bg-[#071107] selection:bg-brand selection:text-white">

        {{-- Ambient decorative glow on desktop --}}
        <div class="hidden sm:block absolute -top-32 -left-32 w-80 h-80 bg-brand/15 rounded-full blur-[100px] pointer-events-none"></div>
        <div class="hidden sm:block absolute -bottom-32 -right-32 w-80 h-80 bg-emerald-500/10 rounded-full blur-[100px] pointer-events-none"></div>

        {{-- Native App Shell: Edge-to-edge on mobile, sleek iPhone chassis on desktop --}}
        <div class="w-full sm:max-w-[400px] min-h-screen sm:min-h-[760px] sm:max-h-[840px] bg-white dark:bg-[#0e170e] sm:rounded-[48px] sm:border-[9px] sm:border-[#1a281a] sm:shadow-[0_25px_80px_rgba(0,0,0,0.85),0_0_40px_rgba(6,139,3,0.18)] sm:ring-1 sm:ring-white/10 flex flex-col justify-between overflow-hidden relative">

            {{-- Phone Status Bar & Dynamic Island (Desktop only) --}}
            <div class="hidden sm:flex items-center justify-between px-7 pt-3 pb-1 select-none flex-shrink-0 bg-white/90 dark:bg-[#0e170e]/90 backdrop-blur-md z-30">
                <span class="text-[12px] font-bold text-zinc-600 dark:text-zinc-400 font-mono">9:41</span>
                {{-- Dynamic Island pill --}}
                <div class="w-24 h-5 bg-black rounded-full flex items-center justify-between px-2.5 shadow-inner">
                    <span class="w-2 h-2 rounded-full bg-zinc-800"></span>
                    <span class="w-2 h-2 rounded-full bg-emerald-500/90 animate-pulse"></span>
                </div>
                {{-- Status indicators --}}
                <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-400">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9 0 2.12.74 4.07 1.97 5.61L4.35 19.4c-.39.39-.39 1.02 0 1.41.39.39 1.02.39 1.41 0l1.9-1.9C9.22 19.57 10.57 20 12 20c4.97 0 9-4.03 9-9s-4.03-9-9-9zm0 15c-3.31 0-6-2.69-6-6s2.69-6 6-6 6 2.69 6 6-2.69 6-6 6z"/></svg>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12 18.75h.007v.008H12v-.008z"/></svg>
                    <div class="w-5 h-2.5 rounded-sm border border-current p-0.5 flex items-center">
                        <div class="h-full w-3 bg-current rounded-2xs"></div>
                    </div>
                </div>
            </div>

            {{-- App Navigation Bar --}}
            <div class="px-4 py-2.5 flex items-center justify-between border-b border-zinc-100 dark:border-[#1a2a1a] bg-white/95 dark:bg-[#0e170e]/95 backdrop-blur-md sticky top-0 z-20 flex-shrink-0">
                <a href="{{ route('home') }}" class="min-h-[44px] min-w-[44px] -ml-1 rounded-full flex items-center justify-center text-zinc-500 hover:text-brand dark:text-zinc-400 dark:hover:text-white transition-colors" title="Back to Store">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </a>

                <div class="flex items-center gap-2">
                    <img src="/images/logo.svg" alt="GadgetPlug" class="h-5 w-auto">
                    <span class="text-[10px] font-black tracking-wider uppercase text-brand dark:text-brand-lime bg-emerald-500/10 dark:bg-emerald-500/20 px-2 py-0.5 rounded-full border border-brand/20">
                        Affiliate
                    </span>
                </div>

                <div class="w-9 flex justify-end pr-1">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-brand dark:bg-brand-lime"></span>
                    </span>
                </div>
            </div>

            {{-- App Scrollable Body --}}
            <div class="flex-1 overflow-y-auto px-4 py-4 space-y-4 scrollbar-none"
                 x-data="{ show: false }" x-init="setTimeout(() => show = true, 50)">

                <div x-show="show"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-3"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="space-y-4">

                    {{-- Fintech Digital Affiliate Card --}}
                    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-tr from-[#056002] via-[#068B03] to-[#0a380a] p-4 text-white shadow-[0_8px_24px_rgba(6,139,3,0.3)] border border-emerald-400/30">
                        {{-- Card sheen overlay --}}
                        <div class="absolute -right-8 -bottom-8 w-36 h-36 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>

                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-1.5">
                                <div class="w-6 h-6 rounded-lg bg-white/20 backdrop-blur-sm flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                    </svg>
                                </div>
                                <span class="text-[10px] font-extrabold tracking-widest uppercase text-white/90">Affiliate Pass</span>
                            </div>

                            {{-- Chip & Wave symbol --}}
                            <div class="flex items-center gap-1.5 text-white/70">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0"/>
                                </svg>
                                <div class="w-5 h-3.5 rounded bg-amber-300/80 border border-amber-200"></div>
                            </div>
                        </div>

                        <div class="my-2">
                            <span class="text-[10px] uppercase font-bold tracking-wider text-emerald-200/90 block">Estimated Weekly Payout</span>
                            <div class="flex items-baseline gap-2 mt-0.5">
                                <span class="font-montserrat font-black text-[24px] sm:text-[26px] tracking-tight leading-none text-white">₦10,000+</span>
                                <span class="text-[10px] font-semibold text-emerald-100 bg-black/25 px-2 py-0.5 rounded-full border border-white/15">/ week</span>
                            </div>
                        </div>

                        <div class="mt-3 pt-2.5 border-t border-white/15 flex items-center justify-between text-[10px] text-emerald-100 font-mono tracking-wider">
                            <span>NO CAPITAL REQUIRED</span>
                            <span class="font-bold text-white">0% FEES</span>
                        </div>
                    </div>

                    {{-- Pitch & Title --}}
                    <div class="text-center pt-0.5">
                        <h1 class="font-montserrat font-black text-[20px] text-brand-dark dark:text-[#e8f5e9] tracking-tight leading-snug">
                            Earn with GadgetPlug
                        </h1>
                        <p class="text-[12px] text-brand-muted mt-1 leading-relaxed max-w-[320px] mx-auto">
                            Earn with GadgetPlug — up to ₦10,000/week. No fees, no capital.
                        </p>
                    </div>

                    {{-- How It Works: Native App Feature Rows --}}
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between px-1">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-brand dark:text-brand-lime">How It Works</span>
                            <span class="text-[10px] text-brand-muted font-medium">3 simple steps</span>
                        </div>

                        <div class="rounded-2xl border border-zinc-200/80 dark:border-[#1e2e1e] bg-zinc-50/70 dark:bg-[#132013] divide-y divide-zinc-200/60 dark:divide-[#1e2e1e] overflow-hidden shadow-sm">

                            {{-- Step 1 --}}
                            <div class="flex items-center gap-3 p-3 hover:bg-white/80 dark:hover:bg-[#192819] transition-colors">
                                <div class="w-9 h-9 rounded-xl bg-emerald-500/15 text-brand dark:text-emerald-400 flex items-center justify-center flex-shrink-0 border border-emerald-500/25 shadow-xs">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0 text-left">
                                    <h4 class="text-[12px] font-bold text-brand-dark dark:text-[#e8f5e9] leading-tight">1. Get Referral Link</h4>
                                    <p class="text-[11px] text-brand-muted leading-tight mt-0.5 truncate">Unique link & QR code ready in seconds</p>
                                </div>
                                <span class="text-[10px] font-bold text-brand dark:text-emerald-400 bg-emerald-500/10 dark:bg-emerald-500/20 px-2 py-0.5 rounded-md">Instant</span>
                            </div>

                            {{-- Step 2 --}}
                            <div class="flex items-center gap-3 p-3 hover:bg-white/80 dark:hover:bg-[#192819] transition-colors">
                                <div class="w-9 h-9 rounded-xl bg-emerald-500/15 text-brand dark:text-emerald-400 flex items-center justify-center flex-shrink-0 border border-emerald-500/25 shadow-xs">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0 text-left">
                                    <h4 class="text-[12px] font-bold text-brand-dark dark:text-[#e8f5e9] leading-tight">2. Share Everywhere</h4>
                                    <p class="text-[11px] text-brand-muted leading-tight mt-0.5 truncate">WhatsApp status, TikTok, IG & groups</p>
                                </div>
                                <span class="text-[10px] font-bold text-brand dark:text-emerald-400 bg-emerald-500/10 dark:bg-emerald-500/20 px-2 py-0.5 rounded-md">Socials</span>
                            </div>

                            {{-- Step 3 --}}
                            <div class="flex items-center gap-3 p-3 hover:bg-white/80 dark:hover:bg-[#192819] transition-colors">
                                <div class="w-9 h-9 rounded-xl bg-emerald-500/15 text-brand dark:text-emerald-400 flex items-center justify-center flex-shrink-0 border border-emerald-500/25 shadow-xs">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0 text-left">
                                    <h4 class="text-[12px] font-bold text-brand-dark dark:text-[#e8f5e9] leading-tight">3. Direct Cash Out</h4>
                                    <p class="text-[11px] text-brand-muted leading-tight mt-0.5 truncate">Automatic payout to any Nigerian bank</p>
                                </div>
                                <span class="text-[10px] font-bold text-brand dark:text-emerald-400 bg-emerald-500/10 dark:bg-emerald-500/20 px-2 py-0.5 rounded-md">Cash</span>
                            </div>

                        </div>
                    </div>

                </div>

            </div>

            {{-- App Bottom Action Dock (Sticky at base) --}}
            <div class="p-4 bg-white/95 dark:bg-[#0e170e]/95 backdrop-blur-md border-t border-zinc-100 dark:border-[#1a2a1a] space-y-2.5 z-20 flex-shrink-0">
                <a href="{{ route('register') }}"
                   class="gp-animate-cta relative overflow-hidden w-full min-h-[50px] px-6 bg-gradient-to-r from-brand to-[#046802] hover:from-[#079b04] hover:to-brand text-white font-montserrat font-bold text-[13px] rounded-xl active:scale-[0.98] transition-all duration-150 flex items-center justify-center gap-2 group">
                    {{-- Luminous Shimmer Light Beam --}}
                    <div class="absolute inset-0 w-3/4 h-full bg-gradient-to-r from-transparent via-white/30 to-transparent pointer-events-none gp-animate-shimmer"></div>

                    <span class="relative z-10 tracking-wide">I'm new — Create account</span>
                    <svg class="w-4 h-4 relative z-10 gp-animate-arrow" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                    </svg>
                </a>

                <a href="{{ route('login') }}"
                   class="w-full min-h-[46px] px-6 bg-zinc-100/90 dark:bg-[#162316] hover:bg-zinc-200/80 dark:hover:bg-[#1e2e1e] text-brand-dark dark:text-[#e8f5e9] font-montserrat font-semibold text-[13px] rounded-xl active:scale-[0.98] transition-all duration-150 flex items-center justify-center gap-2">
                    <span>I already have an account — Log in</span>
                </a>

                <div class="flex items-center justify-center gap-1.5 pt-0.5 text-[11px] text-brand-muted">
                    <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <span>Free registration · Quick review process</span>
                </div>
            </div>

            {{-- iOS Home Bar (Desktop only) --}}
            <div class="hidden sm:block pb-2 pt-0.5 bg-white/95 dark:bg-[#0e170e]/95 select-none flex-shrink-0">
                <div class="w-28 h-1 bg-zinc-300 dark:bg-zinc-700 rounded-full mx-auto"></div>
            </div>

        </div>

        {{-- Desktop Back to Store link below phone frame --}}
        <div class="hidden sm:flex items-center gap-2 mt-4 text-[12px] text-zinc-400">
            <a href="{{ route('home') }}" class="hover:text-emerald-400 transition-colors flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                </svg>
                <span>Back to GadgetPlug Store</span>
            </a>
        </div>

    </div>

</x-layouts.auth-storefront>
