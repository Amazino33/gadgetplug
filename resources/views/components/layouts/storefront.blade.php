@props([
    'title'       => "GadgetPlug — Nigeria's #1 Tech Marketplace",
    'description' => 'Buy phones, laptops and gadgets from CAC-verified Nigerian vendors. Test your item before you pay, with 2-hour dispatch across Uyo, Lagos, Abuja and 20 more cities.',
    'image'       => asset('images/logo.svg'),
    'url'         => request()->url(),
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">

    {{-- Open Graph --}}
    <meta property="og:type"        content="website">
    <meta property="og:site_name"   content="GadgetPlug">
    <meta property="og:title"       content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image"       content="{{ $image }}">
    <meta property="og:url"         content="{{ $url }}">

    {{-- Twitter Card --}}
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image"       content="{{ $image }}">
    <link rel="icon" type="image/svg+xml" href="/images/logo.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#068B03">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="GadgetPlug">
    {{-- Apply saved dark preference only — light mode is the default --}}
    <script>
        (function() {
            if (localStorage.getItem('gp-theme') === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/pwa.js'])
    @livewireStyles
    @include('partials.meta-pixel')
    @include('partials.ms-clarity')
</head>
<body
    x-data="{
        mobileMenu: false,
        dark: document.documentElement.classList.contains('dark'),
        scrolled: false,
        toggleDark() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            localStorage.setItem('gp-theme', this.dark ? 'dark' : 'light');
        }
    }"
    @scroll.window.throttle.100ms="scrolled = window.scrollY > 50"
    class="bg-brand-bg dark:bg-[#0d1a0d] font-inter text-[#111] dark:text-[#e8f5e9] overflow-x-hidden text-[13px] antialiased transition-colors duration-200">

    <a href="#main-content" class="gp-skip-link">Skip to main content</a>

{{-- Cached: this runs on every page of the storefront, and the category list
     changes a few times a year at most. Short TTL so an edit still shows up
     without anyone needing to clear anything by hand. --}}
@php
$navCategories = \Illuminate\Support\Facades\Cache::remember(
    'storefront.nav-categories',
    now()->addMinutes(10),
    fn () => \App\Models\Category::orderBy('name')->get(['name', 'slug']),
);
@endphp

    {{-- ─── HEADER ──────────────────────────────────────────────────────────── --}}
    <header class="bg-white dark:bg-[#162016] border-b border-brand-border dark:border-[#2a3a2a] sticky top-0 z-[100] transition-colors duration-200">
      <div class="max-w-[1440px] mx-auto px-4 md:px-6">

        {{-- Row 1: Logo · Search · Icons --}}
        <div class="flex items-center gap-3 md:gap-4 py-3">

            {{-- Logo --}}
            <a href="{{ route('home') }}" class="flex items-center flex-shrink-0">
                <img src="/images/logo.svg" alt="GadgetPlug" width="140" height="44" class="h-11 w-auto" draggable="false">
            </a>

            {{-- All Categories dropdown (desktop only) --}}
            <div x-data="{ catOpen: false }" @click.outside="catOpen = false" class="hidden md:block relative flex-shrink-0">
                <button @click="catOpen = !catOpen"
                    class="flex items-center gap-1.5 h-10 px-3.5 bg-brand hover:bg-[#055002] text-white rounded-xl text-[12px] font-semibold font-montserrat transition-colors">
                    <svg class="w-4 h-4 fill-none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <line x1="3" y1="6" x2="21" y2="6" stroke-linecap="round"/>
                        <line x1="3" y1="12" x2="21" y2="12" stroke-linecap="round"/>
                        <line x1="3" y1="18" x2="21" y2="18" stroke-linecap="round"/>
                    </svg>
                    All
                    <svg class="w-3 h-3 fill-none transition-transform duration-200" :class="catOpen ? 'rotate-180' : ''" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div x-show="catOpen"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
                     x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     class="absolute top-[46px] left-0 z-[200] bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-xl shadow-xl w-[200px] py-2"
                     style="display:none">
                    <a href="{{ route('home') }}#products" @click="catOpen = false"
                       class="flex items-center gap-2 px-4 py-3 text-[12px] font-semibold text-brand-orange hover:bg-brand-bg dark:hover:bg-[#1a2a1a] transition-colors">
                        <x-gp-icon name="bolt" class="w-3.5 h-3.5" /> Flash Sale
                    </a>
                    @foreach($navCategories as $cat)
                    <a href="{{ route('home', ['category' => $cat->slug]) }}" @click="catOpen = false"
                       class="flex items-center px-4 py-2.5 text-[12px] transition-colors
                           {{ request('category') === $cat->slug ? 'text-brand font-semibold bg-[#f0f8f0] dark:bg-[#1a2a1a]' : 'text-[#333] dark:text-[#b0c8b0] hover:bg-brand-bg dark:hover:bg-[#1a2a1a] hover:text-brand' }}">
                        {{ $cat->name }}
                    </a>
                    @endforeach
                    {{-- "Verified Plugs" removed: there is no vendor directory
                         route to point it at, and a dead link is worse than an
                         absent one. Restore it once that page exists. --}}
                </div>
            </div>

            {{-- Search bar (md+) --}}
            <div class="hidden md:block flex-1 max-w-[520px] mx-auto">
                {{-- The trailing icon here used to be a camera that did nothing.
                     It is now a real submit button, so the form can be completed
                     by mouse as well as by pressing Enter. --}}
                <form method="GET" action="{{ route('home') }}" role="search">
                    <div class="flex items-center bg-brand-bg dark:bg-[#0d1a0d] border-[1.5px] border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl pl-3.5 pr-1 h-11 gap-2 focus-within:border-brand transition-colors">
                        <x-gp-icon name="search" class="w-4 h-4 text-[#8a9e8c] flex-shrink-0" />
                        <label for="site-search" class="sr-only">Search products</label>
                        <input
                            id="site-search"
                            type="search"
                            name="search"
                            aria-label="Search products"
                            placeholder="Search phones, laptops, audio, accessories…"
                            value="{{ request('search') }}"
                            class="flex-1 bg-transparent border-none outline-none text-[13px] text-[#111] dark:text-[#e8f5e9] placeholder-[#8a9e8c] font-inter"
                        >
                        <button type="submit" aria-label="Search"
                            class="flex-shrink-0 w-9 h-9 flex items-center justify-center rounded-lg bg-brand text-white hover:bg-[#055002] transition-colors">
                            <x-gp-icon name="search" class="w-[17px] h-[17px]" />
                        </button>
                    </div>
                </form>
                <div class="flex items-center gap-1 mt-[3px] pl-0.5 text-[11px] text-brand-muted">
                    <svg class="w-[11px] h-[11px] fill-brand-orange flex-shrink-0" viewBox="0 0 24 24">
                        <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                    </svg>
                    Delivering to: <strong class="text-[#111] dark:text-[#e8f5e9] ml-0.5">Uyo · Mkpat Enin · Eket, Akwa Ibom</strong>
                </div>
            </div>

            {{-- Right icons --}}
            <div class="flex items-center gap-2 md:gap-[18px] flex-shrink-0 ml-auto md:ml-0">

                {{-- Account dropdown (desktop only) --}}
                <div x-data="{ acctOpen: false }" @click.outside="acctOpen = false" class="hidden md:block relative">
                    <button @click="acctOpen = !acctOpen"
                        aria-label="Account menu"
                        :aria-expanded="acctOpen ? 'true' : 'false'"
                        class="flex flex-col items-center justify-center gap-0.5 min-w-[44px] min-h-[44px] cursor-pointer text-[#444] dark:text-[#c0d8c0] hover:text-brand dark:hover:text-brand transition-colors">
                        @auth
                        <div class="w-[22px] h-[22px] rounded-full bg-brand flex items-center justify-center text-white text-[9px] font-black font-montserrat">
                            {{ auth()->user()->initials() }}
                        </div>
                        @else
                        <svg class="w-[22px] h-[22px] fill-none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        </svg>
                        @endauth
                        <span class="text-[10px]">Account</span>
                    </button>

                    <div x-show="acctOpen"
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
                         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                         x-transition:leave="transition ease-in duration-100"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="absolute right-0 top-[46px] z-[200] w-[220px] bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl shadow-xl py-2"
                         style="display:none">

                        @auth
                        {{-- User header --}}
                        <div class="px-4 py-2.5 border-b border-brand-border dark:border-[#2a3a2a] mb-1">
                            <p class="text-[12px] font-bold text-brand-dark dark:text-[#e8f5e9] truncate">{{ auth()->user()->name }}</p>
                            <p class="text-[10px] text-brand-muted truncate">{{ auth()->user()->email }}</p>
                        </div>
                        @foreach([
                            ['href' => route('account.profile'),      'label' => 'My Profile'],
                            ['href' => route('account.orders'),        'label' => 'My Orders'],
                            ['href' => route('account.wishlist'),      'label' => 'Wishlist'],
                            ['href' => route('account.vendor-apply'),  'label' => 'Become a Plug'],
                        ] as $link)
                        <a href="{{ $link['href'] }}" @click="acctOpen = false"
                           class="flex items-center px-4 py-2 text-[12px] text-[#333] dark:text-[#b0c8b0] hover:bg-brand-bg dark:hover:bg-[#1a2a1a] hover:text-brand transition-colors">
                            {{ $link['label'] }}
                        </a>
                        @endforeach
                        <div class="border-t border-brand-border dark:border-[#2a3a2a] mt-1 pt-1">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" @click="acctOpen = false"
                                    class="w-full text-left px-4 py-2 text-[12px] text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                                    Sign Out
                                </button>
                            </form>
                        </div>
                        @else
                        <a href="{{ route('login') }}" @click="acctOpen = false"
                           class="flex items-center px-4 py-2.5 text-[12px] font-semibold text-brand hover:bg-brand-bg dark:hover:bg-[#1a2a1a] transition-colors">
                            Sign In
                        </a>
                        <a href="{{ route('register') }}" @click="acctOpen = false"
                           class="flex items-center px-4 py-2.5 text-[12px] text-[#333] dark:text-[#b0c8b0] hover:bg-brand-bg dark:hover:bg-[#1a2a1a] hover:text-brand transition-colors">
                            Create Account
                        </a>
                        @endauth
                    </div>
                </div>

                {{-- Wishlist (desktop only) --}}
                <a href="{{ auth()->check() ? route('account.wishlist') : route('login') }}"
                   class="hidden md:flex flex-col items-center gap-0.5 cursor-pointer text-[#444] dark:text-[#c0d8c0] hover:text-brand dark:hover:text-brand transition-colors">
                    <svg class="gp-icon w-[22px] h-[22px] fill-none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                    </svg>
                    <span class="text-[10px]">Wishlist</span>
                </a>

                {{-- Dark mode toggle (desktop only).
                     Was a div with a click handler, so it could not be reached
                     or activated from the keyboard at all. --}}
                <button type="button" @click="toggleDark()"
                    :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'"
                    :aria-pressed="dark ? 'true' : 'false'"
                    class="hidden md:flex flex-col items-center justify-center gap-0.5 min-w-[44px] min-h-[44px] cursor-pointer text-[#444] dark:text-[#c0d8c0] hover:text-brand dark:hover:text-brand transition-colors">
                    <svg x-show="!dark" class="w-[22px] h-[22px] fill-none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                    <svg x-show="dark" class="w-[22px] h-[22px] fill-none" style="stroke:#8ab08a;stroke-width:1.8" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                    <span class="text-[10px]" x-text="dark ? 'Light' : 'Dark'"></span>
                </button>

                {{-- Dark mode toggle (mobile only) --}}
                <button class="md:hidden w-9 h-9 flex items-center justify-center rounded-lg bg-brand-bg dark:bg-[#1a2a1a] border border-brand-border dark:border-[#2a3a2a] transition-colors"
                    @click="toggleDark()" aria-label="Toggle dark mode">
                    <svg x-show="!dark" class="w-[18px] h-[18px] fill-none" style="stroke:#333;stroke-width:1.7" viewBox="0 0 24 24">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                    <svg x-show="dark" class="w-[18px] h-[18px] fill-none" style="stroke:#8ab08a;stroke-width:1.7" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                </button>

                {{-- Cart dropdown --}}
                <livewire:components.cart-dropdown />

                {{-- Hamburger menu button (mobile only) --}}
                <button class="md:hidden w-9 h-9 flex items-center justify-center rounded-lg hover:bg-brand-bg dark:hover:bg-[#1a2a1a] transition-colors"
                    @click="mobileMenu = true" aria-label="Open menu">
                    <svg class="w-[22px] h-[22px] fill-none" style="stroke:#5a7a5c;stroke-width:2.2" viewBox="0 0 24 24">
                        <line x1="3" y1="6" x2="21" y2="6" stroke-linecap="round"/>
                        <line x1="3" y1="12" x2="21" y2="12" stroke-linecap="round"/>
                        <line x1="3" y1="18" x2="21" y2="18" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Mobile search row — collapses on scroll --}}
        <div class="md:hidden overflow-hidden"
             x-show="!scrolled"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 max-h-0"
             x-transition:enter-end="opacity-100 max-h-16"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 max-h-16"
             x-transition:leave-end="opacity-0 max-h-0"
             style="max-height: 4rem;">
            <div class="pb-2">
                <form method="GET" action="{{ route('home') }}" role="search">
                    <div class="flex items-center bg-brand-bg dark:bg-[#0d1a0d] border-[1.5px] border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl pl-3 pr-1 h-11 gap-2 focus-within:border-brand transition-colors">
                        <x-gp-icon name="search" class="w-4 h-4 text-[#8a9e8c] flex-shrink-0" />
                        <label for="site-search-mobile" class="sr-only">Search products</label>
                        <input id="site-search-mobile" type="search" name="search"
                            aria-label="Search products"
                            placeholder="Search phones, laptops…"
                            value="{{ request('search') }}"
                            class="flex-1 bg-transparent border-none outline-none text-[12px] text-[#111] dark:text-[#e8f5e9] placeholder-[#8a9e8c]">
                        <button type="submit" aria-label="Search"
                            class="flex-shrink-0 w-9 h-9 flex items-center justify-center rounded-lg bg-brand text-white hover:bg-[#055002] transition-colors">
                            <x-gp-icon name="search" class="w-4 h-4" />
                        </button>
                    </div>
                </form>
            </div>
        </div>

      </div>{{-- /max-w header inner --}}
    </header>

    {{-- ─── CATEGORY NAV STRIP (non-sticky, scrolls with page, desktop only) ─── --}}
    <nav id="category-nav" class="hidden md:block bg-white dark:bg-[#162016] border-b border-[#f0f4f1] dark:border-[#2a3a2a] h-[36px]">
      <div class="max-w-[1440px] mx-auto px-6 flex items-center overflow-x-auto scrollbar-none h-full">
        <a href="{{ route('home') }}#products"
           class="px-3.5 h-full flex items-center gap-1.5 text-[12px] font-semibold text-brand-orange whitespace-nowrap border-b-2 border-transparent hover:border-brand-orange transition-colors">
            <x-gp-icon name="bolt" class="w-3.5 h-3.5" /> Flash Sale
        </a>
        @foreach($navCategories as $cat)
        <a href="{{ route('home', ['category' => $cat->slug]) }}"
           class="px-3.5 h-full flex items-center text-[12px] font-medium whitespace-nowrap border-b-2 border-transparent hover:text-brand hover:border-brand transition-colors
               {{ request('category') === $cat->slug ? 'text-brand border-brand' : 'text-[#333] dark:text-[#b0c8b0]' }}">
            {{ $cat->name }}
        </a>
        @endforeach
      </div>{{-- /max-w nav inner --}}
    </nav>

    {{-- ─── MOBILE MENU BACKDROP ───────────────────────────────────────────────── --}}
    <div
        x-show="mobileMenu"
        @click="mobileMenu = false"
        class="fixed inset-0 z-[150] bg-black/50 md:hidden"
        style="display:none"
        x-transition:enter="transition-opacity duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0">
    </div>

    {{-- ─── MOBILE MENU DRAWER ──────────────────────────────────────────────────── --}}
    <div
        x-show="mobileMenu"
        class="fixed top-0 right-0 bottom-0 z-[151] w-[280px] bg-white dark:bg-[#162016] shadow-2xl flex flex-col md:hidden"
        style="display:none"
        x-transition:enter="transition transform duration-300"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition transform duration-200"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full">

        {{-- Drawer header --}}
        <div class="flex items-center justify-between px-5 h-[58px] border-b border-brand-border dark:border-[#2a3a2a] flex-shrink-0">
            <img src="/images/logo.svg" alt="GadgetPlug" width="102" height="32" class="h-8 w-auto" draggable="false">
            <button @click="mobileMenu = false"
                class="w-9 h-9 flex items-center justify-center rounded-lg bg-brand-bg dark:bg-[#1a2a1a] border border-brand-border dark:border-[#2a3a2a] transition-colors"
                aria-label="Close menu">
                <svg class="w-5 h-5 fill-none" style="stroke:#5a7a5c;stroke-width:2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Nav links --}}
        <nav class="flex-1 px-4 py-3 overflow-y-auto space-y-0.5">
            <a href="{{ route('home') }}#products" @click="mobileMenu = false"
               class="flex items-center gap-3 px-3 min-h-[44px] rounded-xl text-[13px] font-semibold text-brand-orange hover:bg-brand-bg dark:hover:bg-[#1a2a1a] transition-colors">
                <x-gp-icon name="bolt" class="w-4 h-4" /> Flash Sale
            </a>
            @foreach($navCategories as $cat)
            <a href="{{ route('home', ['category' => $cat->slug]) }}"
               @click="mobileMenu = false"
               class="flex items-center gap-3 px-3 min-h-[44px] rounded-xl text-[13px] font-medium transition-colors
                   {{ request('category') === $cat->slug
                       ? 'text-brand bg-[#f0f8f0] dark:bg-[#1a2a1a]'
                       : 'text-[#333] dark:text-[#b0c8b0] hover:bg-brand-bg dark:hover:bg-[#1a2a1a] hover:text-brand' }}">
                <x-gp-icon :name="\App\Support\CategoryIcon::for($cat->name)" class="w-4 h-4 flex-shrink-0" />
                {{ $cat->name }}
            </a>
            @endforeach
        </nav>

        {{-- Account row --}}
        <div class="px-4 py-3 border-t border-brand-border dark:border-[#2a3a2a]">
            <a href="{{ auth()->check() ? route('account.profile') : route('login') }}"
               @click="mobileMenu = false"
               class="flex items-center gap-3 px-3 py-3 rounded-xl text-[13px] font-semibold text-brand-dark dark:text-[#e8f5e9] hover:bg-brand-bg dark:hover:bg-[#1a2a1a] transition-colors">
                <svg class="w-5 h-5 fill-none flex-shrink-0" style="stroke:#5a7a5c;stroke-width:1.7" viewBox="0 0 24 24">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                {{ auth()->check() ? auth()->user()->name : 'Sign In / Register' }}
            </a>
        </div>
    </div>

    {{-- ─── PAY-ON-DELIVERY SUCCESS BANNER ─────────────────────────────────── --}}
    @if (session('pod_success'))
    <div class="bg-brand text-white px-4 py-4">
        <div class="max-w-[1440px] mx-auto flex items-start gap-3">
            <svg class="w-6 h-6 fill-brand-lime flex-shrink-0 mt-0.5" viewBox="0 0 24 24">
                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div>
                <p class="font-montserrat font-bold text-[14px]">Order placed! Your rider is on the way.</p>
                <p class="text-[12px] text-[#b0e8b0] mt-0.5">
                    Reference: <strong>{{ session('pod_success') }}</strong> · Pay cash to the rider after inspecting your item.
                </p>
            </div>
        </div>
    </div>
    @endif

    {{-- ─── PAGE CONTENT ────────────────────────────────────────────────────── --}}
    <main id="main-content" class="pb-16 md:pb-0">
        <div class="max-w-[1440px] mx-auto">
            {{ $slot }}
        </div>
    </main>

    {{-- ─── FOOTER ──────────────────────────────────────────────────────────── --}}
    <footer class="bg-[#e8f0e9] dark:bg-brand-footer transition-colors duration-200">
      <div class="max-w-[1440px] mx-auto px-4 md:px-6 pt-9 pb-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.8fr_1fr_1fr_1fr] gap-7 mb-7">
            <div>
                <div class="mb-3">
                    <img src="/images/logo.svg" alt="GadgetPlug" width="178" height="56" loading="lazy" class="h-14 w-auto dark:brightness-[1.1]" draggable="false">
                </div>
                <p class="text-[12px] text-[#4a6a4c] dark:text-[#7a9e7c] leading-relaxed max-w-[220px] mb-3.5">
                    Nigeria's premium tech marketplace. Verified vendors, authentic products, localized trust.
                </p>
                {{-- Were unicode glyphs in plain divs: not links, not
                     focusable, and announced as stray characters. --}}
                <div class="flex gap-2">
                    @foreach([
                        ['icon' => 'x',        'label' => 'GadgetPlug on X',        'url' => 'https://x.com/gadgetplugng'],
                        ['icon' => 'facebook', 'label' => 'GadgetPlug on Facebook', 'url' => 'https://facebook.com/gadgetplugng'],
                        ['icon' => 'linkedin', 'label' => 'GadgetPlug on LinkedIn', 'url' => 'https://linkedin.com/company/gadgetplugng'],
                        ['icon' => 'youtube',  'label' => 'GadgetPlug on YouTube',  'url' => 'https://youtube.com/@gadgetplugng'],
                    ] as $social)
                    <a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer"
                       aria-label="{{ $social['label'] }}"
                       class="w-11 h-11 bg-[#c8deca] dark:bg-[#1a3a1a] rounded-lg flex items-center justify-center hover:bg-brand transition-colors text-[#2a5a2c] dark:text-white hover:text-white">
                        <x-gp-icon :name="$social['icon']" class="w-4 h-4" />
                    </a>
                    @endforeach
                </div>
            </div>
            {{-- Every entry below points at a route that actually exists. The
                 columns previously listed eighteen links all pointing at "#" —
                 About, Careers, Press, Investor Relations, Help Centre, Returns
                 and the rest — none of which had a page behind them. Rather than
                 leave a footer full of links that go nowhere, only the ones with
                 a real destination are rendered; add a route here when the
                 corresponding page is built. --}}
            @php
                $footerColumns = [
                    'Corporate' => [
                        ['label' => 'Privacy Policy', 'url' => route('privacy-policy')],
                    ],
                    'Sell on GadgetPlug' => [
                        ['label' => 'Become a Vendor', 'url' => route('account.vendor-apply')],
                    ],
                    'Support' => [
                        ['label' => 'Track My Order', 'url' => route('track-order')],
                        ['label' => 'My Orders',      'url' => auth()->check() ? route('account.orders') : null],
                    ],
                ];
            @endphp

            @foreach ($footerColumns as $heading => $links)
            @php $links = array_filter($links, fn ($l) => filled($l['url'])); @endphp
            @if ($links)
            <div>
                <h4 class="font-montserrat font-bold text-[12px] text-brand dark:text-brand-lime tracking-[0.8px] uppercase mb-3">{{ $heading }}</h4>
                <ul class="space-y-1">
                    @foreach($links as $link)
                    <li>
                        <a href="{{ $link['url'] }}"
                           class="flex items-center min-h-[44px] text-[12px] text-[#4a6a4c] dark:text-[#7a9e7c] hover:text-brand dark:hover:text-white transition-colors">
                            {{ $link['label'] }}
                        </a>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif
            @endforeach
        </div>
        <div class="border-t border-[#c0d4c2] dark:border-[#1a3a1a] pt-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-3">
            <span class="text-[11px] text-[#5a7a5c] dark:text-[#4a6a4c]">© {{ date('Y') }} GadgetPlug Nigeria Ltd. All rights reserved. RC: 1234567</span>
            <div class="flex items-center gap-2 flex-wrap">
                <div class="flex items-center gap-1 bg-[#c8deca] dark:bg-[#1a3a1a] rounded-md px-2 py-1 text-[10px] text-[#3a6a3c] dark:text-[#7a9e7c]">
                    <svg class="w-3 h-3 fill-brand" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    SSL Secured
                </div>
                <div class="flex items-center gap-1 bg-[#c8deca] dark:bg-[#1a3a1a] rounded-md px-2 py-1 text-[10px] text-[#3a6a3c] dark:text-[#7a9e7c]">
                    <svg class="w-3 h-3 fill-brand" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    CAC Verified
                </div>
                <div class="flex items-center gap-1 bg-[#c8deca] dark:bg-[#1a3a1a] rounded-md px-2 py-1 text-[10px] text-brand-orange">
                    <svg class="w-3.5 h-2.5 flex-shrink-0 rounded-[1px]" viewBox="0 0 9 6" aria-hidden="true" focusable="false">
                        <rect width="9" height="6" fill="#fff"/>
                        <rect width="3" height="6" fill="#008751"/>
                        <rect x="6" width="3" height="6" fill="#008751"/>
                    </svg>
                    Made for Nigeria
                </div>
            </div>
        </div>
      </div>{{-- /max-w footer inner --}}
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
        class="fixed bottom-24 md:bottom-6 right-4 md:right-6 bg-brand-dark text-white px-5 py-3.5 rounded-xl shadow-2xl z-[102] flex items-center gap-3 border border-[#1a5a1a]"
        style="display: none;"
    >
        <svg class="w-5 h-5 text-brand-lime flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span class="font-medium text-[13px]">Item added to cart!</span>
    </div>

    {{-- ─── MOBILE BOTTOM NAV ───────────────────────────────────────────────── --}}
    <nav class="fixed bottom-0 left-0 right-0 bg-white dark:bg-[#162016] border-t border-brand-border dark:border-[#2a3a2a] flex md:hidden z-[100] transition-colors duration-200">
        <a href="{{ route('home') }}" class="flex-1 flex flex-col items-center justify-center gap-0.5 min-h-[44px] py-2 cursor-pointer">
            <svg class="w-[22px] h-[22px] fill-none" style="stroke:#068B03;stroke-width:1.8" viewBox="0 0 24 24">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            <span class="text-[9px] text-brand font-montserrat font-semibold">Home</span>
        </a>
        {{-- Was a plain div: not focusable, not activatable, and it did nothing
             when tapped. Now scrolls to the search field at the top of the page. --}}
        <a href="#site-search-mobile"
           @click="scrolled = false"
           aria-label="Search products"
           class="flex-1 flex flex-col items-center justify-center gap-0.5 min-h-[44px] py-2 cursor-pointer">
            <x-gp-icon name="search" class="w-[22px] h-[22px] text-[#aaa]" />
            <span class="text-[9px] text-[#aaa] dark:text-[#4a6a4a] font-montserrat font-semibold">Search</span>
        </a>
        <button class="flex-1 flex flex-col items-center justify-center gap-0.5 min-h-[44px] py-2 cursor-pointer bg-transparent border-0"
            @click="mobileMenu = !mobileMenu">
            <svg class="gp-icon-inactive w-[22px] h-[22px] fill-none" style="stroke:#aaa;stroke-width:1.8" viewBox="0 0 24 24">
                <rect x="2" y="3" width="20" height="14" rx="2"/>
                <line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
            <span class="text-[9px] text-[#aaa] dark:text-[#4a6a4a] font-montserrat font-semibold">Categories</span>
        </button>
        <a href="{{ route('cart') }}" class="flex-1 flex flex-col items-center justify-center gap-0.5 min-h-[44px] py-2 cursor-pointer relative">
            <svg class="gp-icon-inactive w-[22px] h-[22px] fill-none" style="stroke:#aaa;stroke-width:1.8" viewBox="0 0 24 24">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            <span class="text-[9px] text-[#aaa] dark:text-[#4a6a4a] font-montserrat font-semibold">Cart</span>
        </a>
        <a href="{{ auth()->check() ? route('account.profile') : route('login') }}" class="flex-1 flex flex-col items-center justify-center gap-0.5 min-h-[44px] py-2 cursor-pointer">
            <svg class="gp-icon-inactive w-[22px] h-[22px] fill-none" style="stroke:#aaa;stroke-width:1.8" viewBox="0 0 24 24">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
            <span class="text-[9px] text-[#aaa] dark:text-[#4a6a4a] font-montserrat font-semibold">Account</span>
        </a>
    </nav>

    @livewireScripts
</body>
</html>
