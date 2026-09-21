{{--
    What was actually bought, on the list itself.

    Expanding a delivery used to show four facts about the paperwork — item
    count, amount paid, who logged it, when — and not one word about what was
    in the boxes. So checking a delivery in meant opening it, reading it, going
    back, and opening the next one. The lines are the whole reason anyone
    expands the row, so they belong here.

    Approve is here for the same reason: a delivery you can read without
    leaving the list is one you should be able to receive without leaving it
    either. Correcting still lives on the record itself — that is a slower,
    more deliberate act than agreeing, and it deserves the full screen.
--}}
@php
    $record = $getRecord();
    $items  = $record->items;
    $isOpen = $record->isOpen();

    $canApprove = $isOpen
        && auth()->user()?->can('approve', $record)
        && \App\Filament\Vendor\Resources\Procurements\ProcurementResource::canApprove($record);

    $canCorrect = $isOpen
        && auth()->user()?->can('correct', $record)
        && \App\Filament\Vendor\Resources\Procurements\ProcurementResource::canApprove($record);
@endphp

<div class="mt-2 space-y-2">

    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
        Items purchased
    </p>

    <div class="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 dark:divide-white/5 dark:border-white/10">
        @forelse ($items as $item)
            @php
                $corrected = $item->isCorrected();
                $qty       = $item->verifiedQuantity();
                $cost      = $item->verifiedUnitCost();
            @endphp

            <div @class([
                'px-3 py-2',
                'bg-amber-50 dark:bg-amber-500/10' => $corrected,
            ])>
                <div class="flex items-start justify-between gap-3">
                    <span class="min-w-0 flex-1 text-sm font-medium text-gray-900 dark:text-white">
                        {{ $item->product->name ?? 'Unknown product' }}
                    </span>

                    <span class="shrink-0 text-sm font-semibold text-gray-900 dark:text-white">
                        &#8358;{{ number_format($item->verifiedLineTotal(), 2) }}
                    </span>
                </div>

                {{-- Quantity and price on their own line so a long product name
                     never pushes the figures off the side of a phone. --}}
                <div class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                    <span>{{ number_format($qty) }} &times; &#8358;{{ number_format($cost, 2) }}</span>

                    @if ($corrected)
                        <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                            was {{ number_format($item->quantity) }} &times; &#8358;{{ number_format((float) $item->unit_cost, 2) }}
                        </span>
                    @endif
                </div>
            </div>
        @empty
            <p class="px-3 py-2 text-sm text-gray-400">No items on this delivery.</p>
        @endforelse
    </div>

    @if ($canApprove || $canCorrect)
        <div class="flex flex-col gap-2 pt-1 sm:flex-row">
            @if ($canApprove)
                <button
                    type="button"
                    wire:click="mountAction('approveFromList', @js(['record' => $record->getKey()]))"
                    wire:loading.attr="disabled"
                    class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-green-700 disabled:opacity-50"
                >
                    <x-heroicon-m-check-circle class="h-4 w-4"/>
                    {{ $record->hasCorrections() ? 'Agree & Update Stock' : 'Approve & Update Stock' }}
                </button>
            @endif

            @if ($canCorrect)
                <a
                    href="{{ \App\Filament\Vendor\Resources\Procurements\Pages\ViewProcurement::getUrl(['record' => $record]) }}"
                    class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    <x-heroicon-m-pencil-square class="h-4 w-4"/>
                    Correct
                </a>
            @endif
        </div>
    @endif
</div>
