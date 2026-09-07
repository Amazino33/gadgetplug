{{--
    The cover the store opens behind.

    Shown once per session, and it is not decoration: while it is up, the first
    page of the feed is already being fetched and its first images preloaded, so
    "Enter Store" drops the visitor into a feed that is already warm rather than
    into a spinner. A splash that costs a wait would be worse than none.

    localStorage, not the session cookie, so it survives the page being
    refreshed but not the tab being closed and reopened days later.
--}}
<div
    x-data="{
        show: false,
        init() {
            try {
                this.show = ! sessionStorage.getItem('gp_feed_entered')
            } catch { this.show = true }

            // Warm the feed behind the splash, whether or not it is showing —
            // the shell asks for the same page and will take this one.
            this.$dispatch('feed-prefetch')
        },
        enter() {
            try { sessionStorage.setItem('gp_feed_entered', '1') } catch {}
            this.show = false
            this.$dispatch('feed-entered')
        },
    }"
    x-show="show"
    x-cloak
    x-transition.opacity.duration.300ms
    class="fixed inset-0 z-50 flex flex-col items-center justify-center bg-brand-dark px-8 text-center lg:hidden"
>
    <img src="{{ asset('images/logo.svg') }}" alt="GadgetPlug" class="mb-6 h-14 w-auto" />

    <h1 class="font-montserrat text-2xl font-black text-white">
        Nigeria's tech marketplace
    </h1>
    <p class="mt-2 max-w-xs text-sm text-white/70">
        Phones, laptops and gadgets from verified sellers. Test before you pay.
    </p>

    <button
        type="button"
        @click="enter()"
        class="mt-8 w-full max-w-xs rounded-full bg-brand-lime px-8 py-4 font-montserrat text-base font-black text-brand-dark active:scale-[0.98] transition-transform"
    >
        Enter Store
    </button>

    <p class="mt-4 text-[11px] text-white/40">Scroll. Tap what you like.</p>
</div>
