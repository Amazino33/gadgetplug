<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

/**
 * Search, as a panel that opens over whatever the shopper was reading.
 *
 * The Search button in the mobile nav used to be a link to #site-search-mobile
 * — the field in the header. That field lives in a row which collapses on
 * scroll, so tapping Search jumped to something the shopper could not see and
 * did not focus it. It read as a dead button because, in every way that
 * mattered to them, it was one.
 *
 * Results come as they type rather than on submit. On a phone, a round trip to
 * a results page for every guess is how someone gives up and leaves.
 */
new class extends Component
{
    /** Enough to be useful on a phone screen without becoming a page of its own. */
    private const RESULT_LIMIT = 8;

    /**
     * Below this, almost everything in the catalogue matches and the list is
     * noise — "a" would return whatever happened to be indexed first.
     */
    private const MIN_TERM_LENGTH = 2;

    public string $q = '';

    #[Computed]
    public function results(): Collection
    {
        $term = trim($this->q);

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return collect();
        }

        // % and _ are wildcards to LIKE, so a shopper typing either would
        // otherwise be running a query they did not write.
        $escaped = addcslashes($term, '%_\\');

        return Product::query()
            ->visibleOnline()
            ->inStockForSale()
            ->with(['vendor', 'category', 'media'])
            ->where(function ($q) use ($escaped) {
                $q->where('name', 'like', "%{$escaped}%")
                  ->orWhere('brand', 'like', "%{$escaped}%");
            })
            // Something that starts with what they typed is much more likely to
            // be what they meant than something that merely contains it
            // somewhere — "charger" should not open on "Car charger adapter
            // for wireless charger stand".
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', ["{$escaped}%"])
            ->orderBy('name')
            ->limit(self::RESULT_LIMIT)
            ->get();
    }

    /**
     * What a shopper actually types, in the order we would like to suggest it.
     *
     * Product words, not category names. Categories here are broad shelves —
     * "Accessories & Cables", "Smartwatches & Wearables" — and "Search
     * Accessories & Cables…" reads like a filing system, not like something a
     * person came here to buy. Nobody searches for a shelf; they search for a
     * charger.
     *
     * Only a candidate list, though. Which of these a shopper is actually
     * offered is decided by the catalogue, below.
     */
    private const CANDIDATE_TERMS = [
        'charger',
        'power bank',
        'earbuds',
        'smartwatch',
        'laptop',
        'iPhone',
        'headphones',
        'cable',
        'speaker',
        'phone case',
    ];

    /**
     * The words that rotate through the empty field, and the chips under it.
     *
     * Every one is checked against what is actually on the shelves first, so
     * the field can never invite a search that lands on "Nothing for charger".
     * Falls back to category names if none of the candidates match — a real
     * shelf beats an empty field.
     *
     * Cached: this renders on every storefront page and the answer changes
     * about as often as the catalogue does.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function suggestions(): array
    {
        return Cache::remember(
            'storefront.search-suggestions',
            now()->addMinutes(10),
            function (): array {
                // Category names count as well as product names. Stock is
                // rarely named the way it is shopped for — nothing is called a
                // "smartwatch", it is called an "Apple Watch Series 9" — but it
                // sits on a shelf that is. Without the shelves, a catalogue
                // full of watches would offer no way to search for one.
                $haystack = Product::query()
                    ->visibleOnline()
                    ->inStockForSale()
                    ->limit(2000)
                    ->get(['name', 'brand'])
                    ->map(fn ($p) => $p->name . ' ' . $p->brand)
                    ->concat(
                        Category::query()
                            ->whereHas('products', fn ($q) => $q->visibleOnline()->inStockForSale())
                            ->pluck('name'),
                    )
                    ->implode(' ');

                // Spaces and plurals are not the shopper's problem: "power
                // bank" should find "Power Banks", and "smartwatch" should find
                // "Smartwatches & Wearables". Folding both sides down to bare
                // letters makes all of those the same string.
                $fold = fn (string $v): string => preg_replace('/[^a-z0-9]/', '', mb_strtolower($v));

                $folded = $fold($haystack);

                $matched = array_values(array_filter(
                    self::CANDIDATE_TERMS,
                    fn (string $term) => str_contains($folded, $fold($term)),
                ));

                if ($matched !== []) {
                    return array_slice($matched, 0, 8);
                }

                // Nothing in the candidate list is stocked — offer the shelves
                // rather than an empty field. Categories holding nothing
                // buyable are left out for the same reason.
                return Category::query()
                    ->whereHas('products', fn ($q) => $q->visibleOnline()->inStockForSale())
                    ->orderBy('name')
                    ->limit(8)
                    ->pluck('name')
                    ->all();
            },
        );
    }

    public function clear(): void
    {
        $this->q = '';
    }
}; ?>

<div
    x-data="{
        open: false,
        // The rotating hint. Words the catalogue was checked against on the
        // server below, so this never advertises something nobody sells.
        terms: @js($this->suggestions ?: ['phones', 'chargers', 'power banks']),
        index: 0,
        timer: null,

        get hint() {
            return this.terms.length ? `Search ${this.terms[this.index]}…` : 'Search products…'
        },

        show() {
            this.open = true
            document.body.style.overflow = 'hidden'
            // Next tick: the field is only focusable once x-show has revealed it.
            this.$nextTick(() => this.$refs.field?.focus())
            this.spin()
        },

        hide() {
            this.open = false
            document.body.style.overflow = ''
            this.stop()
        },

        spin() {
            this.stop()
            // Slow enough to finish reading, quick enough to see it change
            // while deciding what to type.
            this.timer = setInterval(() => {
                this.index = (this.index + 1) % this.terms.length
            }, 2200)
        },

        stop() {
            if (this.timer) { clearInterval(this.timer); this.timer = null }
        },
    }"
    x-on:open-search.window="show()"
    x-on:keydown.escape.window="open && hide()"
>
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-[300] bg-black/40 backdrop-blur-sm"
        x-on:click.self="hide()"
        role="dialog"
        aria-modal="true"
        aria-label="Search products"
    >
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-3"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="mx-auto flex h-full w-full max-w-[640px] flex-col bg-[#f8fcf8] dark:bg-[#0d1a0d] md:mt-16 md:h-auto md:max-h-[80vh] md:rounded-2xl md:shadow-2xl md:overflow-hidden"
        >
            {{-- ─── FIELD ──────────────────────────────────────────────────── --}}
            <div class="flex items-center gap-2 border-b border-brand-border bg-white px-3 py-3 dark:border-[#2a3a2a] dark:bg-[#162016]"
                 style="padding-top: calc(0.75rem + env(safe-area-inset-top, 0px));">
                <button type="button" @click="hide()" aria-label="Close search"
                    class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full text-brand-muted transition-colors hover:bg-brand-bg dark:hover:bg-[#1a2a1a]">
                    <svg class="h-5 w-5 fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                </button>

                <div class="flex h-11 flex-1 items-center gap-2 rounded-xl border-[1.5px] border-[#d0d9d2] bg-brand-bg px-3 transition-colors focus-within:border-brand dark:border-[#2a3a2a] dark:bg-[#0d1a0d]">
                    <x-gp-icon name="search" class="w-4 h-4 flex-shrink-0 text-[#8a9e8c]" />
                    <label for="overlay-search" class="sr-only">Search products</label>
                    <input
                        id="overlay-search"
                        x-ref="field"
                        type="search"
                        autocomplete="off"
                        enterkeyhint="search"
                        wire:model.live.debounce.250ms="q"
                        :placeholder="hint"
                        {{-- The hint stops rotating the moment they engage with
                             the field — a placeholder changing under a cursor
                             is a distraction, not a suggestion. --}}
                        x-on:focus="stop()"
                        class="min-w-0 flex-1 border-none bg-transparent text-[15px] text-[#111] outline-none placeholder-[#8a9e8c] dark:text-[#e8f5e9]"
                    >
                    @if ($q !== '')
                    <button type="button" wire:click="clear" x-on:click="$refs.field?.focus()"
                        aria-label="Clear search"
                        class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-[#d0d9d2] text-white dark:bg-[#2a3a2a]">
                        <svg class="h-3.5 w-3.5 fill-none" style="stroke:currentColor;stroke-width:2.5" viewBox="0 0 24 24">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                    @endif
                </div>
            </div>

            {{-- ─── RESULTS ────────────────────────────────────────────────── --}}
            <div class="flex-1 overflow-y-auto overscroll-contain px-3 py-3">

                {{-- Skeletons rather than a spinner: the list is about to be
                     this shape, so the page does not jump when it arrives. --}}
                <div wire:loading.delay wire:target="q" class="space-y-2">
                    @for ($i = 0; $i < 4; $i++)
                    <div class="flex items-center gap-3 rounded-xl bg-white p-2.5 dark:bg-[#162016]">
                        <div class="gp-skeleton h-12 w-12 flex-shrink-0 rounded-lg"></div>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="gp-skeleton h-3 w-3/4 rounded"></div>
                            <div class="gp-skeleton h-3 w-1/3 rounded"></div>
                        </div>
                    </div>
                    @endfor
                </div>

                <div wire:loading.remove wire:target="q">
                    @if ($q === '')
                        {{-- Idle: the same words the field is offering, as
                             something they can tap instead of type. --}}
                        @if ($this->suggestions)
                        <p class="mb-2 px-1 text-[11px] font-semibold uppercase tracking-wide text-brand-muted">
                            Popular right now
                        </p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->suggestions as $term)
                            <button type="button"
                                wire:click="$set('q', @js($term))"
                                class="rounded-full border border-brand-border bg-white px-3.5 py-2 text-[12px] font-medium text-brand-dark transition-colors hover:border-brand hover:text-brand dark:border-[#2a3a2a] dark:bg-[#162016] dark:text-[#e8f5e9]">
                                {{ $term }}
                            </button>
                            @endforeach
                        </div>
                        @endif

                    @elseif (mb_strlen(trim($q)) < 2)
                        <p class="px-1 py-6 text-center text-[13px] text-brand-muted">
                            Keep typing — one more letter.
                        </p>

                    @elseif ($this->results->isEmpty())
                        <div class="px-4 py-10 text-center">
                            <x-gp-icon name="search" class="mx-auto mb-3 h-10 w-10 text-[#8a9e8c]" />
                            <p class="font-montserrat text-[14px] font-bold text-brand-dark dark:text-[#e8f5e9]">
                                Nothing for "{{ trim($q) }}"
                            </p>
                            <p class="mt-1 text-[12px] text-brand-muted">
                                Try a shorter word, or browse a category instead.
                            </p>
                            <a href="{{ route('home') }}"
                               class="mt-4 inline-block rounded-full bg-brand px-5 py-2.5 font-montserrat text-[12px] font-black text-white">
                                Browse everything
                            </a>
                        </div>

                    @else
                        <p class="mb-2 px-1 text-[11px] font-semibold uppercase tracking-wide text-brand-muted">
                            {{ $this->results->count() }} {{ Str::plural('result', $this->results->count()) }}
                        </p>

                        <div class="space-y-2">
                            @foreach ($this->results as $result)
                            @php $thumb = $result->getFirstMediaUrl('product-images', 'thumb'); @endphp
                            <a href="{{ route('product.show', $result) }}"
                               wire:key="search-{{ $result->id }}"
                               class="flex items-center gap-3 rounded-xl bg-white p-2.5 transition-colors hover:bg-[#f0f8f0] dark:bg-[#162016] dark:hover:bg-[#1f2f1f]">
                                <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg border border-brand-border bg-brand-bg dark:border-[#2a3a2a] dark:bg-[#0d1a0d]">
                                    @if ($thumb)
                                        <img src="{{ $thumb }}" alt="{{ $result->name }}" loading="lazy" decoding="async" class="h-full w-full object-cover">
                                    @else
                                        <x-gp-icon :name="\App\Support\CategoryIcon::for($result->category?->name)" class="h-6 w-6 text-brand opacity-40" />
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="line-clamp-1 text-[13px] font-semibold text-[#111] dark:text-[#e8f5e9]">{{ $result->name }}</p>
                                    <p class="line-clamp-1 text-[11px] text-brand-muted">{{ $result->vendor->name ?? '' }}</p>
                                </div>
                                <span class="flex-shrink-0 font-montserrat text-[14px] font-black text-brand">
                                    ₦{{ number_format($result->price) }}
                                </span>
                            </a>
                            @endforeach
                        </div>

                        {{-- The panel caps at eight; this is the way to the rest. --}}
                        <a href="{{ route('home', ['search' => trim($q)]) }}"
                           class="mt-3 block rounded-xl border border-brand-border py-3 text-center font-montserrat text-[12px] font-bold text-brand transition-colors hover:bg-white dark:border-[#2a3a2a] dark:hover:bg-[#162016]">
                            See all results for "{{ trim($q) }}"
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
