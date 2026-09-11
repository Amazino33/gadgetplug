/**
 * The mobile social feed.
 *
 * Alpine rather than Livewire for the scroll: appending a page must not send a
 * component's whole state back and forth on a mobile connection. The JSON
 * endpoint already returns exactly what a post needs, so this only has to fetch
 * and append.
 */
document.addEventListener('alpine:init', () => {
    /**
     * Actions live in a store, not in each post.
     *
     * A post card is rendered once per product; putting the handlers on the card
     * would mean hundreds of copies of the same four closures. The store is one.
     *
     * Phase 3 wires the optimistic UI only — the endpoints these will call are
     * Phase 4. Each is deliberately safe to tap now: nothing silently fails, and
     * nothing persists a lie.
     */
    Alpine.store('feed', {
        /** Laravel's CSRF token, needed on every write below. */
        token() {
            return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        },

        async post(url, body = {}) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.token(),
                    Accept: 'application/json',
                },
                body: JSON.stringify(body),
            });
        },

        async toggleLike(post) {
            // Optimistic: the heart moves on tap, not on the round trip. On a
            // slow connection that difference is the whole feel of the thing.
            const wasLiked = post.liked;
            const wasCount = post.like_count;

            post.liked = ! wasLiked;
            post.like_count = Math.max(0, wasCount + (post.liked ? 1 : -1));

            try {
                const res = await this.post(`/feed/${post.id}/like`);

                if (! res.ok) throw new Error(res.status);

                // The server's count wins. A double tap, a second device or a
                // lost request would otherwise leave the guess drifting further
                // with every tap.
                const data = await res.json();
                post.liked = data.liked;
                post.like_count = data.like_count;
            } catch {
                // Put it back. Showing a like that was never recorded is worse
                // than the tap appearing not to register.
                post.liked = wasLiked;
                post.like_count = wasCount;
            }
        },

        async save(post) {
            const wasSaved = post.saved;
            post.saved = ! wasSaved;

            try {
                const res = await this.post(`/feed/${post.id}/save`);

                if (res.status === 401) {
                    // Saving needs an account. The intent is already stashed
                    // server-side, so signing in completes the save rather than
                    // dropping them back on a feed that forgot what they wanted.
                    const data = await res.json();
                    post.saved = wasSaved;
                    window.location.href = data.login_url;

                    return;
                }

                if (! res.ok) throw new Error(res.status);

                post.saved = (await res.json()).saved;
            } catch {
                post.saved = wasSaved;
            }
        },

        async share(post) {
            const url = post.url;

            // Logged first: it is the intent to share that is worth counting,
            // and the native sheet never tells us whether they went through
            // with it.
            this.post(`/feed/${post.id}/share`)
                .then((res) => res.ok && res.json())
                .then((data) => { if (data) post.share_count = data.share_count; })
                .catch(() => {});

            // The native sheet where it exists, which on a phone is everywhere
            // that matters. The clipboard is the fallback, not the default.
            if (navigator.share) {
                try {
                    await navigator.share({ title: post.name, url });
                } catch {
                    // Cancelled. Not an error worth showing.
                }

                return;
            }

            try {
                await navigator.clipboard.writeText(url);
                window.dispatchEvent(new CustomEvent('feed-toast', { detail: 'Link copied' }));
            } catch {
                window.open(url, '_blank');
            }
        },

        /**
         * Buy Now — a real form post, not a fetch.
         *
         * It ends on the checkout page, so the browser should follow the
         * redirect itself: the cart lives in the session, and letting fetch
         * chase a redirect it cannot navigate to would put the customer nowhere.
         *
         * No variant picker: this catalogue is flat SKUs, confirmed in recon.
         */
        buyNow(post) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = `/feed/${post.id}/buy`;

            const token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_token';
            token.value = this.token();
            form.appendChild(token);

            document.body.appendChild(form);
            form.submit();
        },
    });

    Alpine.data('feed', (firstPage, categories, categoryId, search) => ({
        posts: firstPage?.posts ?? [],
        cursor: firstPage?.next_cursor ?? null,
        hasMore: firstPage?.has_more ?? true,
        categories: categories ?? [],
        // Seeded from the page, so arriving on a ?search= URL scrolls into
        // more of the SAME search rather than silently widening to everything
        // on the second page.
        categoryId: categoryId ?? null,
        search: search ?? '',
        searchOpen: false,
        loading: false,

        // How far down the chip bar has to sit to clear the storefront
        // header. Measured rather than hard-coded: the header is sticky at
        // top-0 too, and it changes height when its own search row collapses
        // on scroll. A fixed number would be wrong half the time, and the
        // chip bar would hide underneath the header — taking its search
        // button and every chip with it.
        headerOffset: 0,

        init() {
            // The splash asks for this before it is dismissed, so the feed is
            // already warm when the reader taps through.
            this.$el.addEventListener('feed-prefetch', () => {
                if (this.posts.length === 0) this.loadMore();
            });

            this.watchSentinel();
            this.followHeader();
        },

        /** Keep the chip bar parked directly under the storefront header. */
        followHeader() {
            const header = document.querySelector('header');

            if (! header) return;

            const measure = () => { this.headerOffset = header.offsetHeight; };

            measure();

            if (window.ResizeObserver) {
                new ResizeObserver(measure).observe(header);
            } else {
                window.addEventListener('resize', measure);
            }
        },

        /**
         * Fetch the next page when the sentinel comes near.
         *
         * rootMargin pulls the trigger 800px before the sentinel is actually
         * visible, so the page is already arriving as the reader reaches the
         * bottom rather than after they have stopped and noticed.
         */
        watchSentinel() {
            if (! this.$refs.sentinel || ! window.IntersectionObserver) return;

            new IntersectionObserver(
                (entries) => { if (entries[0].isIntersecting) this.loadMore(); },
                { rootMargin: '800px 0px' },
            ).observe(this.$refs.sentinel);
        },

        async loadMore() {
            // Guarded on both: the observer can fire repeatedly while one
            // request is still out, and every extra call is a wasted page of
            // somebody's data.
            if (this.loading || ! this.hasMore) return;

            this.loading = true;

            try {
                const params = new URLSearchParams();
                if (this.cursor) params.set('cursor', this.cursor);
                if (this.categoryId) params.set('category', this.categoryId);
                if (this.search) params.set('search', this.search);

                const res = await fetch(`/feed/posts?${params}`, {
                    headers: { Accept: 'application/json' },
                });

                if (! res.ok) throw new Error(res.status);

                const page = await res.json();

                // Appended, never replaced: the reader's scroll position is in
                // the posts already on screen.
                this.posts = this.posts.concat(page.posts ?? []);
                this.cursor = page.next_cursor;
                this.hasMore = page.has_more;
            } catch {
                // Stop asking rather than hammering a failing endpoint. The
                // end-of-feed state is a truthful thing to show here: there is
                // no more feed available to this reader right now.
                this.hasMore = false;
            } finally {
                this.loading = false;
            }
        },

        /** A chip or a search resets the feed to a fresh cursor. */
        filterBy(categoryId) {
            this.categoryId = categoryId;
            this.posts = [];
            this.cursor = null;
            this.hasMore = true;

            window.scrollTo({ top: 0, behavior: 'instant' });

            this.loadMore();
            this.refreshCategories();
        },

        /**
         * Re-read the chips for the search now in force.
         *
         * The chips the page was rendered with describe the whole catalogue.
         * Once a search narrows the feed, some of them no longer hold anything
         * — tapping one opens an empty feed, which reads as the shop being
         * broken rather than the search being specific. So the chips are asked
         * for again whenever the filter changes, and only whenever it changes:
         * this must not ride along with every page of an infinite scroll.
         *
         * A failed request leaves the existing chips alone. Stale chips are a
         * far smaller problem than a feed that suddenly has no way to filter.
         */
        async refreshCategories() {
            try {
                const params = new URLSearchParams();
                if (this.search) params.set('search', this.search);

                const res = await fetch(`/feed/categories?${params}`, {
                    headers: { Accept: 'application/json' },
                });

                if (! res.ok) return;

                const { categories } = await res.json();

                this.categories = categories ?? [];

                // The chip the reader is standing on may have just been the one
                // that emptied out. Fall back to All rather than leaving them
                // filtered by something no longer offered.
                const stillThere = this.categories.some((c) => c.id === this.categoryId);

                if (this.categoryId !== null && ! stillThere) {
                    this.categoryId = null;
                    this.posts = [];
                    this.cursor = null;
                    this.hasMore = true;
                    this.loadMore();
                }
            } catch {
                // Keep the chips that are already on screen.
            }
        },
    }));
});
