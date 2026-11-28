{{-- The "what happened" panel (CountStoryPanel). Any element on the page can
     open it with $dispatch('gp-count-story', { id }): it slides in at once,
     shows a loading sign, and the story arrives in this component alone. --}}
<div
    x-data="{
        loading: false,
        wanted: null,
        ready: null,
        req: null,
        timer: null,
        // Asks the server for one; the same request is reused when it is
        // already on its way (a pointer resting on the button started it).
        fetch(id) {
            if (this.wanted === id && this.req) return this.req
            this.wanted = id
            this.ready = null
            this.req = $wire.show(id).then(() => { if (this.wanted === id) this.ready = id })
            return this.req
        },
        hover(id) {
            clearTimeout(this.timer)
            if (id !== null) this.timer = setTimeout(() => this.fetch(id), 120)
        },
        open(id) {
            clearTimeout(this.timer)
            const req = this.fetch(id)
            this.loading = this.ready !== id
            $dispatch('open-modal', { id: 'gp-count-story' })
            req.finally(() => {
                if (this.wanted !== id) return
                this.loading = false
            })
        },
    }"
    x-on:gp-count-story.window="open($event.detail.id)"
    x-on:gp-count-story-hover.window="hover($event.detail.id)"
    x-on:stock-take-updated.window="wanted = null; ready = null; req = null"
>
    <x-filament::modal id="gp-count-story" slide-over width="2xl">
        <x-slot name="heading">
            <span x-show="loading">What happened</span>
            <span x-show="! loading" x-cloak>{{ $story['product'] ?? 'What happened' }}</span>
        </x-slot>

        <x-slot name="description">
            What the books said when it was counted, and how they got there.
        </x-slot>

        <div x-show="loading" class="space-y-4" role="status" aria-live="polite">
            <div class="flex items-center gap-3 text-sm font-medium text-gray-600 dark:text-gray-300">
                <x-filament::loading-indicator class="h-5 w-5 text-primary-600" />
                Loading what happened…
            </div>
            <div class="gp-shimmer h-20 w-full rounded-xl"></div>
            <div class="gp-shimmer h-4 w-2/3"></div>
            <div class="gp-shimmer h-4 w-1/2"></div>
            <div class="gp-shimmer h-4 w-3/5"></div>
            <div class="gp-shimmer h-4 w-2/5"></div>
        </div>

        <div x-show="! loading" x-cloak>
            @if ($story)
                @include('filament.vendor.stock-take.count-story', ['s' => $story])
            @endif
        </div>

        <x-slot name="footerActions">
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'gp-count-story' })">
                Close
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
