<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>GadgetPlug — Nigeria's #1 Tech Marketplace</title>
    {{-- Dark mode: prevent flash of unstyled content --}}
    <script>
        (function() {
            var t = localStorage.getItem('gp-theme');
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-brand-bg dark:bg-[#0d1a0d] font-inter text-[#111] dark:text-[#e8f5e9] overflow-x-hidden text-[13px] antialiased transition-colors duration-200">

    {{-- ─── HEADER ──────────────────────────────────────────────────────────── --}}
    <header class="bg-white dark:bg-[#162016] border-b border-brand-border dark:border-[#2a3a2a] px-4 md:px-6 sticky top-0 z-[100] transition-colors duration-200">

        {{-- Row 1: Logo · Search · Icons --}}
        <div class="flex items-center gap-3 md:gap-4 h-[58px]">

            {{-- Logo --}}
            <a href="{{ route('home') }}" class="flex items-center gap-2 flex-shrink-0">
                <div class="w-8 h-8 bg-brand rounded-lg flex items-center justify-center">
                    <svg class="w-[18px] h-[18px] fill-brand-lime" viewBox="0 0 24 24">
                        <path d="M13 2L4 14h8l-1 8 9-12h-8z"/>
                    </svg>
                </div>
                <span class="font-montserrat font-black text-[18px] text-brand tracking-tight leading-none">
                    Gadget<span class="text-brand-orange">Plug</span>
                </span>
            </a>

            {{-- Search bar (md+) --}}
            <div class="hidden md:block flex-1 max-w-[520px] mx-auto">
                <form method="GET" action="{{ route('home') }}">
                    <div class="flex items-center bg-brand-bg dark:bg-[#0d1a0d] border-[1.5px] border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl px-3.5 h-10 gap-2 focus-within:border-brand transition-colors">
                        <svg class="w-4 h-4 text-[#8a9e8c] flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                        </svg>
                        <input
                            type="text"
                            name="search"
                            placeholder="Search phones, laptops, audio, accessories…"
                            value="{{ request('search') }}"
                            class="flex-1 bg-transparent border-none outline-none text-[13px] text-[#111] dark:text-[#e8f5e9] placeholder-[#8a9e8c] font-inter"
                        >
                        <svg class="w-[17px] h-[17px] text-brand flex-shrink-0 cursor-pointer" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                            <circle cx="12" cy="13" r="4"/>
                        </svg>
                    </div>
                </form>
                <div class="flex items-center gap-1 mt-[3px] pl-0.5 text-[11px] text-brand-muted">
                    <svg class="w-[11px] h-[11px] fill-brand-orange flex-shrink-0" viewBox="0 0 24 24">
                        <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                    </svg>
                    Deliver to: <strong class="text-[#111] dark:text-[#e8f5e9] ml-0.5">Uyo, Akwa Ibom</strong>&nbsp;· Change
                </div>
            </div>

            {{-- Right icons --}}
            <div class="flex items-center gap-[18px] flex-shrink-0 ml-auto md:ml-0">

                {{-- Account --}}
                <a href="{{ auth()->check() ? route('dashboard') : route('login') }}"
                   class="hidden md:flex flex-col items-center gap-0.5 cursor-pointer">
                    <svg class="gp-icon w-[22px] h-[22px] fill-none" style="stroke:#333;stroke-width:1.7" viewBox="0 0 24 24">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    <span class="text-[10px] text-brand-muted">Account</span>
                </a>

                {{-- Wishlist --}}
                <div class="hidden md:flex flex-col items-center gap-0.5 cursor-pointer">
                    <svg class="gp-icon w-[22px] h-[22px] fill-none" style="stroke:#333;stroke-width:1.7" viewBox="0 0 24 24">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                    </svg>
                    <span class="text-[10px] text-brand-muted">Wishlist</span>
                </div>

                {{-- Dark mode toggle --}}
                <div x-data="{
                        dark: document.documentElement.classList.contains('dark'),
                        toggle() {
                            this.dark = !this.dark;
                            document.documentElement.classList.toggle('dark', this.dark);
                            localStorage.setItem('gp-theme', this.dark ? 'dark' : 'light');
                        }
                    }"
                    class="hidden md:flex flex-col items-center gap-0.5 cursor-pointer"
                    @click="toggle()">
                    {{-- Moon (shown in light mode) --}}
                    <svg x-show="!dark" class="w-[22px] h-[22px] fill-none" style="stroke:#333;stroke-width:1.7" viewBox="0 0 24 24">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                    {{-- Sun (shown in dark mode) --}}
                    <svg x-show="dark" class="w-[22px] h-[22px] fill-none" style="stroke:#8ab08a;stroke-width:1.7" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                    <span class="text-[10px] text-brand-muted" x-text="dark ? 'Light' : 'Dark'"></span>
                </div>

                {{-- Cart dropdown --}}
                <livewire:components.cart-dropdown />
            </div>
        </div>

        {{-- Mobile search row --}}
        <div class="md:hidden pb-2">
            <form method="GET" action="{{ route('home') }}">
                <div class="flex items-center bg-brand-bg dark:bg-[#0d1a0d] border-[1.5px] border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl px-3 h-9 gap-2 focus-within:border-brand transition-colors">
                    <svg class="w-4 h-4 text-[#8a9e8c] flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input type="text" name="search" placeholder="Search phones, laptops…"
                        value="{{ request('search') }}"
                        class="flex-1 bg-transparent border-none outline-none text-[12px] text-[#111] dark:text-[#e8f5e9] placeholder-[#8a9e8c]">
                </div>
            </form>
        </div>

        {{-- Nav strip (desktop only) --}}
        <nav class="hidden md:flex items-center h-[34px] border-t border-[#f0f4f1] dark:border-[#2a3a2a] overflow-x-auto scrollbar-none">
            <a href="{{ route('home') }}"
               class="px-3.5 h-full flex items-center text-[12px] font-semibold text-brand-orange whitespace-nowrap border-b-2 border-transparent hover:border-brand-orange transition-colors cursor-pointer">
                🔥 Flash Sale
            </a>
            @foreach(['Phones','Laptops','Audio','Wearables','Gaming','Accessories','Smart Home','Cameras','Refurbished'] as $cat)
            <a href="{{ route('home', ['category' => strtolower($cat)]) }}"
               class="px-3.5 h-full flex items-center text-[12px] font-medium text-[#333] dark:text-[#b0c8b0] whitespace-nowrap border-b-2 border-transparent hover:text-brand hover:border-brand transition-colors cursor-pointer {{ request('category') === strtolower($cat) ? '!text-brand border-brand' : '' }}">
                {{ $cat }}
            </a>
            @endforeach
            <a href="#"
               class="px-3.5 h-full flex items-center text-[12px] font-semibold text-brand-orange whitespace-nowrap border-b-2 border-transparent hover:border-brand-orange transition-colors cursor-pointer">
                Verified Plugs
            </a>
        </nav>
    </header>

    {{-- ─── PAGE CONTENT ────────────────────────────────────────────────────── --}}
    <main class="pb-16 md:pb-0">
        {{ $slot }}
    </main>

    {{-- ─── FOOTER (already dark — no changes needed) ───────────────────────── --}}
    <footer class="bg-brand-footer pt-9 pb-5 px-4 md:px-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.8fr_1fr_1fr_1fr] gap-7 mb-7">
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-8 h-8 bg-brand rounded-lg flex items-center justify-center">
                        <svg class="w-[18px] h-[18px] fill-brand-lime" viewBox="0 0 24 24"><path d="M13 2L4 14h8l-1 8 9-12h-8z"/></svg>
                    </div>
                    <span class="font-montserrat font-black text-[18px] text-white">Gadget<span class="text-brand-lime">Plug</span></span>
                </div>
                <p class="text-[12px] text-[#7a9e7c] leading-relaxed max-w-[220px] mb-3.5">
                    Nigeria's premium tech marketplace. Verified vendors, authentic products, localized trust.
                </p>
                <div class="flex gap-2">
                    @foreach(['𝕏', 'f', 'in', '▶'] as $s)
                    <div class="w-8 h-8 bg-[#1a3a1a] rounded-lg flex items-center justify-center cursor-pointer hover:bg-brand transition-colors text-sm text-white select-none">{{ $s }}</div>
                    @endforeach
                </div>
            </div>
            <div>
                <h4 class="font-montserrat font-bold text-[12px] text-brand-lime tracking-[0.8px] uppercase mb-3">Corporate</h4>
                <ul class="space-y-2">
                    @foreach(['About GadgetPlug','Careers','Press & Media','Investor Relations','Sustainability','CAC Registration'] as $link)
                    <li><a href="#" class="text-[12px] text-[#7a9e7c] hover:text-white transition-colors">{{ $link }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <h4 class="font-montserrat font-bold text-[12px] text-brand-lime tracking-[0.8px] uppercase mb-3">Sell on GadgetPlug</h4>
                <ul class="space-y-2">
                    @foreach(['Become a Vendor','Vendor Verification','Seller Dashboard','Fees & Commissions','Vendor Success Stories','Partner Program'] as $link)
                    <li><a href="#" class="text-[12px] text-[#7a9e7c] hover:text-white transition-colors">{{ $link }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <h4 class="font-montserrat font-bold text-[12px] text-brand-lime tracking-[0.8px] uppercase mb-3">Support</h4>
                <ul class="space-y-2">
                    @foreach(['Help Centre','Track My Order','Returns & Refunds','Dispute Resolution','Payment Issues','Contact Us'] as $link)
                    <li><a href="#" class="text-[12px] text-[#7a9e7c] hover:text-white transition-colors">{{ $link }}</a></li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="border-t border-[#1a3a1a] pt-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-3">
            <span class="text-[11px] text-[#4a6a4c]">© {{ date('Y') }} GadgetPlug Nigeria Ltd. All rights reserved. RC: 1234567</span>
            <div class="flex items-center gap-2 flex-wrap">
                <div class="flex items-center gap-1 bg-[#1a3a1a] rounded-md px-2 py-1 text-[10px] text-[#7a9e7c]">
                    <svg class="w-3 h-3 fill-brand" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    SSL Secured
                </div>
                <div class="flex items-center gap-1 bg-[#1a3a1a] rounded-md px-2 py-1 text-[10px] text-[#7a9e7c]">
                    <svg class="w-3 h-3 fill-brand" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    CAC Verified
                </div>
                <div class="flex items-center gap-1 bg-[#1a3a1a] rounded-md px-2 py-1 text-[10px] text-brand-orange">
                    🇳🇬 Made for Nigeria
                </div>
            </div>
        </div>
    </footer>

    {{-- ─── CART TOAST ──────────────────────────────────────────────────────── --}}
    <div
        x-data="{ show: false }"
        x-on:cart-updated.window="show = true; setTimeout(() => show = false, 3000)"
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed bottom-20 md:bottom-6 right-4 md:right-6 bg-brand-dark text-white px-5 py-3.5 rounded-xl shadow-2xl z-50 flex items-center gap-3 border border-[#1a5a1a]"
        style="display: none;"
    >
        <svg class="w-5 h-5 text-brand-lime flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span class="font-medium text-[13px]">Item added to cart!</span>
    </div>

    {{-- ─── MOBILE BOTTOM NAV ───────────────────────────────────────────────── --}}
    <nav class="fixed bottom-0 left-0 right-0 bg-white dark:bg-[#162016] border-t border-brand-border dark:border-[#2a3a2a] flex md:hidden z-[100] transition-colors duration-200">
        <a href="{{ route('home') }}" class="flex-1 flex flex-col items-center gap-0.5 py-2 cursor-pointer">
            <svg class="w-[22px] h-[22px] fill-none" style="stroke:#068B03;stroke-width:1.8" viewBox="0 0 24 24">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            <span class="text-[9px] text-brand font-montserrat font-semibold">Home</span>
        </a>
        <div class="flex-1 flex flex-col items-center gap-0.5 py-2 cursor-pointer">
            <svg class="gp-icon-inactive w-[22px] h-[22px] fill-none" style="stroke:#aaa;stroke-width:1.8" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <span class="text-[9px] text-[#aaa] dark:text-[#4a6a4a] font-montserrat font-semibold">Search</span>
        </div>
        <div class="flex-1 flex flex-col items-center gap-0.5 py-2 cursor-pointer">
            <svg class="gp-icon-inactive w-[22px] h-[22px] fill-none" style="stroke:#aaa;stroke-width:1.8" viewBox="0 0 24 24">
                <rect x="2" y="3" width="20" height="14" rx="2"/>
                <line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
            <span class="text-[9px] text-[#aaa] dark:text-[#4a6a4a] font-montserrat font-semibold">Categories</span>
        </div>
        <a href="{{ route('cart') }}" class="flex-1 flex flex-col items-center gap-0.5 py-2 cursor-pointer relative">
            <svg class="gp-icon-inactive w-[22px] h-[22px] fill-none" style="stroke:#aaa;stroke-width:1.8" viewBox="0 0 24 24">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            <span class="text-[9px] text-[#aaa] dark:text-[#4a6a4a] font-montserrat font-semibold">Cart</span>
        </a>
        <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="flex-1 flex flex-col items-center gap-0.5 py-2 cursor-pointer">
            <svg class="gp-icon-inactive w-[22px] h-[22px] fill-none" style="stroke:#aaa;stroke-width:1.8" viewBox="0 0 24 24">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
            <span class="text-[9px] text-[#aaa] dark:text-[#4a6a4a] font-montserrat font-semibold">Account</span>
        </a>
    </nav>

    @livewireScripts
</body>
</html>
