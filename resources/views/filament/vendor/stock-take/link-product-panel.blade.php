@php
    use App\Support\Quantity;
@endphp

{{-- "Link to existing" (LinkProductPanel). Opened from the Not in the system
     list with $dispatch('gp-link-product', { id }): it appears at once with a
     loading sign, then what the counter wrote sits above the likeliest
     products, so the two can be compared before linking. --}}
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
            $dispatch('open-modal', { id: 'gp-link-product' })
            req.finally(() => {
                if (this.wanted !== id) return
                this.loading = false
                $nextTick(() => $refs.search?.focus())
            })
        },
    }"
    x-on:gp-link-product.window="open($event.detail.id)"
    x-on:gp-link-product-hover.window="hover($event.detail.id)"
    x-on:stock-take-updated.window="wanted = null; ready = null; req = null"
>
    <x-filament::modal id="gp-link-product" width="3xl">
        <x-slot name="heading">Link to a product you have</x-slot>

        <div x-show="loading" class="space-y-4" role="status" aria-live="polite">
            <div class="flex items-center gap-3 text-sm font-medium text-gray-600 dark:text-gray-300">
                <x-filament::loading-indicator class="h-5 w-5 text-primary-600" />
                Loading…
            </div>
            <div class="gp-shimmer h-20 w-full rounded-xl"></div>
            <div class="gp-shimmer h-10 w-full rounded-lg"></div>
            @foreach (range(1, 4) as $i)
                <div class="gp-shimmer h-12 w-full rounded-lg"></div>
            @endforeach
        </div>

        <div x-show="! loading" x-cloak class="space-y-4">
            @if ($item)
                {{-- What the counter found, to compare against. --}}
                <div class="flex items-start gap-3 rounded-xl border border-warning-200 bg-warning-50 p-3 dark:border-warning-500/20 dark:bg-warning-500/10">
                    @if ($photo)
                        <a href="{{ $photo }}" target="_blank" rel="noopener" class="shrink-0">
                            <img src="{{ $photo }}" alt="" class="h-16 w-16 rounded-lg bg-white object-cover dark:bg-gray-800" />
                        </a>
                    @else
                        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-white text-gray-400 dark:bg-gray-800">
                            <x-filament::icon icon="heroicon-o-question-mark-circle" class="h-7 w-7" />
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-warning-700 dark:text-warning-400">The counter wrote</p>
                        <p class="text-lg font-semibold leading-snug text-gray-950 dark:text-white">{{ $item->name_entered }}</p>
                        @if ($item->barcode)
                            <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-200">Barcode <span class="font-mono font-semibold">{{ $item->barcode }}</span></p>
                        @endif
                        <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">
                            {{ collect([
                                $item->barcode ? null : 'No barcode',
                                $item->aisle?->name,
                                $item->creator?->name,
                                $item->created_at?->timezone('Africa/Lagos')->format('D j M, g:ia'),
                            ])->filter()->implode(' · ') }}
                        </p>
                    </div>
                </div>

                <div class="relative">
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                        <x-filament::input
                            type="search"
                            x-ref="search"
                            wire:model.live.debounce.250ms="search"
                            placeholder="Search by name, SKU or barcode"
                            autocomplete="off"
                        />
                    </x-filament::input.wrapper>
                    <span wire:loading.flex wire:target="search" class="absolute inset-y-0 end-3 items-center">
                        <x-filament::loading-indicator class="h-5 w-5 text-gray-400" />
                    </span>
                </div>

                <div>
                    <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ $typed ? 'Products matching “' . trim($search) . '”' : 'Most like “' . $item->name_entered . '”' }}
                    </p>

                    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 dark:divide-white/5 dark:border-white/10"
                        wire:loading.class="opacity-50" wire:target="search">
                        @forelse ($products as $p)
                            <li wire:key="lp-{{ $p['id'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5 sm:flex-nowrap">
                                @if ($p['image'])
                                    <img src="{{ $p['image'] }}" alt="" loading="lazy" class="h-11 w-11 shrink-0 rounded-lg bg-gray-100 object-cover dark:bg-gray-800" />
                                @else
                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-400 dark:bg-gray-800">
                                        <x-filament::icon icon="heroicon-o-photo" class="h-5 w-5" />
                                    </div>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold text-gray-950 dark:text-white" title="{{ $p['name'] }}">{{ $p['name'] }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ collect([
                                            $p['sku'],
                                            '₦' . number_format($p['price'], 0),
                                            $p['stock'] === null ? 'none here' : Quantity::format($p['stock']) . ' in stock here',
                                        ])->filter()->implode(' · ') }}
                                    </p>
                                    {{-- Its own line: the code to compare with the one the counter scanned. --}}
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        @if ($p['barcode'])
                                            Barcode <span class="font-mono text-gray-700 dark:text-gray-300">{{ $p['barcode'] }}</span>
                                        @else
                                            No barcode yet
                                        @endif
                                    </p>
                                </div>
                                {{-- With a barcode already: add this one as another (a second
                                     variant sold the same), or make it the main one. Without: give it. --}}
                                <div class="flex w-full shrink-0 flex-wrap items-center gap-2 sm:w-auto sm:flex-nowrap">
                                    @if ($item->barcode && $p['barcode'])
                                        <x-filament::button size="sm" wire:click="link({{ $p['id'] }})" wire:target="link({{ $p['id'] }})" icon="heroicon-o-plus"
                                            tooltip="Keeps its barcode(s) and adds {{ $item->barcode }}: all scan as this product">
                                            Add as another barcode
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="gray" wire:click="link({{ $p['id'] }}, true)" wire:target="link({{ $p['id'] }}, true)"
                                            tooltip="{{ $item->barcode }} becomes the main barcode; {{ $p['barcode'] }} still scans">
                                            Make main
                                        </x-filament::button>
                                    @else
                                        <x-filament::button size="sm" wire:click="link({{ $p['id'] }})" wire:target="link({{ $p['id'] }})" icon="heroicon-o-link">
                                            {{ $item->barcode ? 'Give barcode' : 'Link' }}
                                        </x-filament::button>
                                    @endif
                                </div>
                            </li>
                        @empty
                            <li class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                {{ $typed ? 'No product matches. Try fewer letters, a SKU or a barcode.' : 'Nothing like it yet. Type to search.' }}
                                <div class="mt-3">
                                    <x-filament::button color="gray" size="sm" icon="heroicon-o-plus"
                                        x-on:click="$dispatch('close-modal', { id: 'gp-link-product' }); $wire.dispatch('gp-create-unknown', { id: {{ $item->id }} })">
                                        Create it as a new product
                                    </x-filament::button>
                                </div>
                            </li>
                        @endforelse
                    </ul>

                    @if ($item->barcode)
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ $item->barcode }} scans as the product you pick from now on. A product that already has a barcode keeps it:
                            <strong>Add as another barcode</strong> for another variant sold the same (a product can have any number), or <strong>Make main</strong> to print and show the new one first.
                        </p>
                    @endif
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">This one has already been dealt with.</p>
            @endif
        </div>
    </x-filament::modal>
</div>
