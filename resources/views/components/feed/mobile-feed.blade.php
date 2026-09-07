@props(['firstPage' => null, 'categories' => [], 'categoryId' => null, 'search' => ''])

{{--
    The mobile social feed.

    Mobile only, by breakpoint — lg:hidden here, and the desktop grid alongside
    carries hidden lg:block. No user-agent sniffing: a narrow desktop window is
    a phone as far as this is concerned, which is the honest reading.

    The scroll is Alpine rather than Livewire: appending a page must not send the
    whole component's state back and forth on a mobile connection, and the JSON
    endpoint already returns exactly what a post needs.
--}}
<div class="lg:hidden" x-data="feed(@js($firstPage), @js($categories), @js($categoryId), @js($search))" x-cloak>

    <x-feed.splash />

    {{-- Chips. Sticky so the reader can change their mind without scrolling back. --}}
    <div class="sticky top-0 z-30 border-b border-brand-border bg-white/95 backdrop-blur">
        <div class="flex items-center gap-2 px-4 py-2.5">
            <button type="button" @click="searchOpen = ! searchOpen" aria-label="Search"
                    class="shrink-0 rounded-full border border-brand-border p-2 text-brand-dark">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
            </button>

            <div class="flex gap-2 overflow-x-auto scrollbar-none">
                <button type="button" @click="filterBy(null)"
                        class="shrink-0 rounded-full px-3.5 py-1.5 text-[13px] font-semibold transition-colors"
                        :class="categoryId === null ? 'bg-brand text-white' : 'bg-brand-bg text-brand-dark'">
                    All
                </button>
                <template x-for="c in categories" :key="c.id">
                    <button type="button" @click="filterBy(c.id)"
                            class="shrink-0 rounded-full px-3.5 py-1.5 text-[13px] font-semibold whitespace-nowrap transition-colors"
                            :class="categoryId === c.id ? 'bg-brand text-white' : 'bg-brand-bg text-brand-dark'"
                            x-text="c.name"></button>
                </template>
            </div>
        </div>

        <div x-show="searchOpen" x-collapse class="px-4 pb-3">
            <input type="search" x-model.debounce.400ms="search" @input="filterBy(categoryId)"
                   placeholder="Search phones, laptops, gadgets…"
                   class="w-full rounded-full border border-brand-border bg-brand-bg px-4 py-2.5 text-[14px] outline-none focus:border-brand" />
        </div>
    </div>

    {{-- Posts. --}}
    <template x-for="post in posts" :key="post.id">
        <x-feed.post />
    </template>

    {{-- Skeletons while a page is in flight. Sized like a real post so the
         scroll position does not lurch when they are replaced. --}}
    <template x-if="loading">
        <div>
            <template x-for="n in 2" :key="n">
                <div class="border-b border-brand-border bg-white p-4">
                    <div class="mb-3 flex items-center gap-2.5">
                        <div class="h-8 w-8 animate-pulse rounded-full bg-brand-bg"></div>
                        <div class="h-3 w-28 animate-pulse rounded bg-brand-bg"></div>
                    </div>
                    <div class="aspect-square w-full animate-pulse rounded bg-brand-bg"></div>
                    <div class="mt-3 h-4 w-24 animate-pulse rounded bg-brand-bg"></div>
                    <div class="mt-2 h-3 w-3/4 animate-pulse rounded bg-brand-bg"></div>
                </div>
            </template>
        </div>
    </template>

    {{-- Nothing matched the chip or the search. --}}
    <template x-if="! loading && posts.length === 0">
        <div class="px-8 py-16 text-center">
            <p class="font-montserrat text-[15px] font-bold text-brand-dark">Nothing here yet</p>
            <p class="mt-1 text-[13px] text-brand-muted">
                <span x-show="search">No products match that search.</span>
                <span x-show="! search">This category has nothing in stock right now.</span>
            </p>
            <button type="button" @click="search = ''; filterBy(null)"
                    class="mt-4 rounded-full bg-brand px-5 py-2 text-[13px] font-bold text-white">
                Show everything
            </button>
        </div>
    </template>

    {{-- The end. Said explicitly rather than just stopping, so a reader knows
         they have seen it all rather than assuming the feed broke. --}}
    <template x-if="! loading && ! hasMore && posts.length > 0">
        <div class="px-8 py-12 text-center">
            <p class="font-montserrat text-[14px] font-bold text-brand-dark">You're all caught up</p>
            <p class="mt-1 text-[12px] text-brand-muted">You've seen everything in stock.</p>
        </div>
    </template>

    {{-- What the observer watches. Placed well above the true bottom so the
         next page is already arriving before the reader reaches it. --}}
    <div x-ref="sentinel" class="h-px"></div>
</div>
