<x-filament-panels::page>

    @php $links = $this->availableLinks(); @endphp

    @if ($links->isEmpty())
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">No supplier linked to you yet</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                A supplier link lets you sell from another vendor's catalogue. Only an administrator can create one,
                because it opens their whole catalogue and their prices to you.
            </p>
        </div>
    @else
        {{-- Only shown when there is a choice to make. --}}
        @if ($links->count() > 1)
            <div class="fi-section mb-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Supplier</label>
                <select wire:model.live="supplierLinkId"
                    class="w-full max-w-sm rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                    @foreach ($links as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @php $link = $this->currentLink(); @endphp
        @if ($link)
            <div class="mb-4 flex items-center gap-2 rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 ring-1 ring-primary-600/10 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-400/20">
                <x-filament::icon icon="heroicon-m-information-circle" class="h-5 w-5 shrink-0" />
                <span>
                    Selling from <span class="font-semibold">{{ $link->supplier?->name }}</span> at
                    <span class="font-semibold">{{ rtrim(rtrim(number_format((float) $link->markup_percent, 2), '0'), '.') }}%</span> markup.
                    Their pictures and words become yours on publish; their price and stock stay theirs.
                </span>
            </div>
        @endif

        {{ $this->table }}
    @endif

</x-filament-panels::page>
