{{--
    One product, as a post.

    Reads `post` from the enclosing x-for scope rather than taking a Blade prop,
    so page one and page fifty render through exactly one template. Rendering the
    first page in Blade and the rest in Alpine would be two templates of the same
    thing, and they would drift.

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
    <div class="flex items-center gap-2.5 px-4 py-3">
        <div class="h-8 w-8 shrink-0 overflow-hidden rounded-full bg-brand-bg ring-1 ring-brand-border">
            <template x-if="post.store.logo">
                <img :src="post.store.logo" :alt="post.store.name" class="h-full w-full object-cover" loading="lazy" />
            </template>
            <template x-if="! post.store.logo">
                <span class="flex h-full w-full items-center justify-center font-montserrat text-[11px] font-black text-brand"
                      x-text="(post.store.name || '?').slice(0, 2).toUpperCase()"></span>
            </template>
        </div>
        {{-- Not a link: there is no public store page on this platform yet. --}}
        <span class="truncate font-montserrat text-[13px] font-bold text-brand-dark" x-text="post.store.name"></span>
    </div>

    {{-- Lazy and async so a fast scroll never blocks on decoding an image the
         reader has already passed. --}}
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

    <div class="flex items-center gap-5 px-4 pt-3">
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

        <button type="button" @click="$store.feed.buyNow(post)"
                class="ml-auto rounded-full bg-brand px-5 py-2 font-montserrat text-[13px] font-black text-white active:scale-95 transition-transform">
            Buy Now
        </button>
    </div>

    <div class="px-4 pb-4 pt-2">
        <p class="font-montserrat text-[15px] font-black text-brand">
            ₦<span x-text="Number(post.price).toLocaleString()"></span>
        </p>
        <a :href="post.url" class="mt-0.5 block font-montserrat text-[14px] font-bold text-brand-dark" x-text="post.name"></a>

        <template x-if="post.caption">
            <p class="mt-1 text-[13px] leading-snug text-brand-muted">
                <span x-text="expanded || post.caption.length <= 90 ? post.caption : post.caption.slice(0, 90).trimEnd()"></span><!--
                --><button type="button" x-show="! expanded && post.caption.length > 90" @click="expanded = true"
                          class="ml-1 font-semibold text-brand-dark">…more</button>
            </p>
        </template>
    </div>
</article>
