@props(['active' => 'account.profile'])

<x-layouts.storefront>
    <div class="min-h-[calc(100vh-120px)] bg-brand-bg dark:bg-[#0d1a0d]">
    <div class="max-w-[1100px] mx-auto px-4 md:px-6 py-6 md:py-8">

        {{-- Page header --}}
        <div class="mb-5 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-brand flex items-center justify-center text-white font-montserrat font-black text-[15px] flex-shrink-0">
                {{ auth()->user()->initials() }}
            </div>
            <div>
                <h1 class="font-montserrat font-black text-[18px] md:text-[22px] text-brand-dark dark:text-[#e8f5e9] leading-tight">
                    {{ auth()->user()->name }}
                </h1>
                <p class="text-[11px] text-brand-muted">{{ auth()->user()->email }}</p>
            </div>
        </div>

        {{-- Mobile tabs --}}
        <div class="md:hidden flex gap-1.5 overflow-x-auto scrollbar-none pb-3 mb-3 border-b border-brand-border dark:border-[#2a3a2a]">
            @foreach([
                ['route' => 'account.profile',       'label' => 'Profile'],
                ['route' => 'account.orders',         'label' => 'Orders'],
                ['route' => 'account.wishlist',       'label' => 'Wishlist'],
                ['route' => 'account.vendor-apply',   'label' => 'Become a Plug'],
            ] as $nav)
            <a href="{{ route($nav['route']) }}"
               class="flex-shrink-0 px-3.5 py-1.5 rounded-lg text-[12px] font-semibold transition-colors whitespace-nowrap
                   {{ $active === $nav['route']
                       ? 'bg-brand text-white'
                       : 'bg-brand-bg dark:bg-[#1a2a1a] text-[#444] dark:text-[#b0c8b0] border border-brand-border dark:border-[#2a3a2a]' }}">
                {{ $nav['label'] }}
            </a>
            @endforeach
        </div>

        <div class="flex gap-6">

            {{-- Desktop sidebar --}}
            <aside class="hidden md:flex flex-col w-52 flex-shrink-0 gap-0.5">
                @php
                    $navItems = [
                        ['route' => 'account.profile',     'label' => 'Profile',         'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'],
                        ['route' => 'account.orders',       'label' => 'My Orders',       'icon' => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>'],
                        ['route' => 'account.wishlist',     'label' => 'Wishlist',        'icon' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>'],
                        ['route' => 'account.vendor-apply','label' => 'Become a Plug',  'icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'],
                    ];
                @endphp
                @foreach($navItems as $nav)
                <a href="{{ route($nav['route']) }}"
                   class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-[13px] font-medium transition-colors
                       {{ $active === $nav['route']
                           ? 'bg-brand text-white font-semibold'
                           : 'text-[#444] dark:text-[#b0c8b0] hover:bg-brand-bg dark:hover:bg-[#1a2a1a] hover:text-brand' }}">
                    <svg class="w-[18px] h-[18px] fill-none flex-shrink-0" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        {!! $nav['icon'] !!}
                    </svg>
                    {{ $nav['label'] }}
                </a>
                @endforeach

                <div class="mt-4 pt-4 border-t border-brand-border dark:border-[#2a3a2a]">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                            class="w-full flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-[13px] font-medium text-red-500 hover:bg-red-50 dark:hover:bg-[#2a1a1a] transition-colors text-left">
                            <svg class="w-[18px] h-[18px] fill-none flex-shrink-0" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                            </svg>
                            Sign Out
                        </button>
                    </form>
                </div>
            </aside>

            {{-- Page content --}}
            <div class="flex-1 min-w-0">
                {{ $slot }}
            </div>
        </div>
    </div>
    </div>
</x-layouts.storefront>
