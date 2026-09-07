{{--
    One product, as a post.

    Reads `post` from the enclosing x-for scope rather than taking a Blade prop,
    so page one and page fifty render through exactly one template. Rendering the
    first page in Blade and the rest in Alpine would be two templates of the same
    thing, and they would drift.

    The order is a Facebook post's: who posted, what they said, the picture, then
    the actions. Here the product is the poster — its name is the headline and
    the store is the subline, because a customer scrolling a marketplace is
    looking for the thing, not the shop.

    content-visibility:auto is what lets the feed run to hundreds of posts on a
    cheap phone: the browser skips layout and paint for anything off-screen,
    which is native windowing for one line of CSS. contain-intrinsic-size gives
    it a height to reserve so the scrollbar does not jump as posts come into view.
--}}
<article
    class="border-b border-brand-border bg-white"
    style="content-visibility: auto; contain-intrinsic-size: auto 620px;"
    x-data="{ expanded: false }"
>
    {{-- 1. HEADER — product name leads, store identifies it.

         The avatar and the store line go to the store's page; the product name
         goes to the product. Two destinations in one header, so each is its own
         anchor rather than one wrapping the other — a link inside a link is
         invalid markup and browsers resolve it unpredictably. --}}
    <div class="flex items-center gap-2.5 px-4 py-3">
        <a :href="post.store.url || post.url"
           class="h-9 w-9 shrink-0 overflow-hidden rounded-full bg-brand-bg ring-1 ring-brand-border">
            <template x-if="post.store.logo">
                <img :src="post.store.logo" :alt="post.store.name" class="h-full w-full object-cover" loading="lazy" />
            </template>
            {{-- No logo is the normal case, not the exception: nothing uploads
                 one yet. The initials chip is the design, not a placeholder. --}}
            <template x-if="! post.store.logo">
                <span class="flex h-full w-full items-center justify-center font-montserrat text-[11px] font-black text-brand"
                      x-text="(post.store.name || '?').slice(0, 2).toUpperCase()"></span>
            </template>
        </a>

        <div class="min-w-0 flex-1">
            <a :href="post.url"
               class="block truncate font-montserrat text-[14px] font-bold leading-tight text-brand-dark"
               x-text="post.name"></a>

            {{-- Store, then location if the store has set one. Built as one
                 string with the separator baked in, so a store with no city
                 shows its name alone rather than a dangling "·".

                 Rendered as a span when there is no store page to reach, so it
                 never looks tappable without going anywhere. --}}
            <template x-if="post.store.url">
                <a :href="post.store.url"
                   class="block truncate text-[12px] leading-tight text-brand-muted underline-offset-2 active:underline"
                   x-text="post.store.location ? post.store.name + ' · ' + post.store.location : post.store.name"></a>
            </template>
            <template x-if="! post.store.url">
                <p class="truncate text-[12px] leading-tight text-brand-muted"
                   x-text="post.store.location ? post.store.name + ' · ' + post.store.location : post.store.name"></p>
            </template>
        </div>
    </div>

    {{-- 2. CAPTION — above the image, the way a post reads. --}}
    <template x-if="post.caption">
        <p class="px-4 pb-3 text-[13px] leading-snug text-brand-muted">
            <span x-text="expanded || post.caption.length <= 90 ? post.caption : post.caption.slice(0, 90).trimEnd()"></span><!--
            --><button type="button" x-show="! expanded && post.caption.length > 90" @click="expanded = true"
                      class="ml-1 font-semibold text-brand-dark">…more</button>
        </p>
    </template>

    {{-- 3. IMAGE — untouched. Lazy and async so a fast scroll never blocks on
         decoding an image the reader has already passed. --}}
    <a :href="post.url" class="block bg-brand-bg">
        <img
            :src="post.image.src"
            :srcset="post.image.srcset"
            sizes="100vw"
            :alt="post.name"
            loading="lazy"
            decoding="async"
            class="aspect-square w-full object-contain"
        />
    </a>

    {{-- 4. ACTIONS — like/save/share left, price and Buy Now together right,
         because the price is what the button is asking them to agree to. --}}
    <div class="flex items-center gap-5 px-4 py-3">
        <button type="button" @click="$store.feed.toggleLike(post)" class="flex items-center gap-1.5" :aria-pressed="post.liked">
            <svg class="h-6 w-6 transition-transform active:scale-90" :class="post.liked ? 'text-red-500' : 'text-brand-dark'"
                 :fill="post.liked ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
            </svg>
            <span class="text-[13px] font-semibold text-brand-dark" x-text="post.like_count" x-show="post.like_count > 0"></span>
        </button>

        <button type="button" @click="$store.feed.save(post)" aria-label="Save">
            <svg class="h-6 w-6 text-brand-dark transition-transform active:scale-90"
                 :fill="post.saved ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0Z" />
            </svg>
        </button>

        <button type="button" @click="$store.feed.share(post)" aria-label="Share">
            <svg class="h-6 w-6 text-brand-dark transition-transform active:scale-90" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" />
            </svg>
        </button>

        <div class="ml-auto flex items-center gap-2.5">
            <p class="font-montserrat text-[15px] font-black text-brand">
                ₦<span x-text="Number(post.price).toLocaleString()"></span>
            </p>
            <button type="button" @click="$store.feed.buyNow(post)"
                    class="rounded-full bg-brand px-5 py-2 font-montserrat text-[13px] font-black text-white active:scale-95 transition-transform">
                Buy Now
            </button>
        </div>
    </div>
</article>
