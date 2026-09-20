{{--
    The delivery, on one screen.

    This replaces a six-column table that only fitted on a desktop. Receiving
    stock happens on a phone, standing next to the cartons — a layout that has
    to be dragged sideways to read the quantity is the layout that gets
    approved without being read.

    Cards at every width, not a table that collapses below md. One set of
    markup means the figure somebody approves on a phone is rendered by the
    same code as the figure they approve at a desk, and the two cannot drift.
--}}
@php
    $isCorrected   = $record->hasCorrections();
    $verifiedTotal = $record->verifiedTotal();
    $recordedTotal = (float) $record->items->sum(fn ($item) => $item->lineTotal());
    $totalVariance = round($verifiedTotal - $recordedTotal, 2);
@endphp

<div class="space-y-4">

    {{-- Summary: supplier, units, money, state. Everything the approver needs
         to know before they look at a single line. --}}
    <div @class([
        'rounded-xl border p-4 sm:p-5',
        'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => ! $isCorrected,
        'border-blue-300 bg-blue-50 dark:border-blue-500/40 dark:bg-blue-500/10' => $isCorrected,
    ])>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Supplier</p>
                <p class="truncate text-base font-semibold text-gray-900 dark:text-white">
                    {{ $record->supplier->name ?? '—' }}
                </p>
                <p class="mt-0.5 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $record->reference }}</p>
            </div>

            <div class="shrink-0">
                {!! $statusBadge !!}
            </div>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3">
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Qty</p>
                <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">
                    {{ number_format($record->verifiedQuantity()) }}
                </p>
                @if ($record->verifiedQuantity() !== $record->recordedQuantity())
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        recorded <span class="line-through">{{ number_format($record->recordedQuantity()) }}</span>
                    </p>
                @endif
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Grand Total</p>
                <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">
                    &#8358;{{ number_format($verifiedTotal, 2) }}
                </p>
                @if (abs($totalVariance) >= 0.01)
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        recorded <span class="line-through">&#8358;{{ number_format($recordedTotal, 2) }}</span>
                    </p>
                @endif
            </div>
        </div>

        {{-- Whose move it is, said plainly. A status badge alone leaves both
             people assuming the other one is dealing with it. --}}
        @if ($record->isChangesRequested() && $record->awaitingUser)
            <p class="mt-3 flex items-start gap-1.5 text-sm text-blue-800 dark:text-blue-300">
                <x-heroicon-m-arrow-uturn-left class="mt-0.5 h-4 w-4 shrink-0"/>
                <span>
                    @if ($record->isAwaiting(auth()->id()))
                        Sent back to you. Check the corrected lines below, then agree or correct again.
                    @else
                        Waiting on <span class="font-semibold">{{ $record->awaitingUser->name }}</span> to re-check.
                    @endif
                </span>
            </p>
        @endif
    </div>

    {{-- One card per line. --}}
    @foreach ($items as $item)
        @php
            $correction = $item->latestCorrection();
            $corrected  = $correction !== null;
            $qtyVar     = $item->quantityVariance();
            $costVar    = $item->unitCostVariance();
        @endphp

        <div @class([
            'rounded-xl border p-4',
            'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => ! $corrected,
            'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' => $corrected,
        ])>
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900 dark:text-white">
                        {{ $item->product->name ?? 'Unknown product' }}
                    </p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ $record->supplier->name ?? '—' }}
                        @if ($item->barcode)
                            <span class="font-mono">&middot; {{ $item->barcode }}</span>
                        @endif
                    </p>
                </div>

                @if ($corrected)
                    <span class="shrink-0 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                        Corrected
                    </span>
                @endif
            </div>

            {{-- Three figures side by side. Never wider than the screen,
                 because they stack rather than scroll. --}}
            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-lg bg-gray-50 py-2 dark:bg-gray-800">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Qty</p>
                    <p class="mt-0.5 font-bold text-gray-900 dark:text-white">{{ number_format($item->verifiedQuantity()) }}</p>
                    @if ($qtyVar !== 0)
                        <p class="text-[11px] text-gray-500 line-through dark:text-gray-400">{{ number_format($item->quantity) }}</p>
                    @endif
                </div>

                <div class="rounded-lg bg-gray-50 py-2 dark:bg-gray-800">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Unit Cost</p>
                    <p class="mt-0.5 font-bold text-gray-900 dark:text-white">&#8358;{{ number_format($item->verifiedUnitCost(), 2) }}</p>
                    @if (abs($costVar) >= 0.01)
                        <p class="text-[11px] text-gray-500 line-through dark:text-gray-400">&#8358;{{ number_format((float) $item->unit_cost, 2) }}</p>
                    @endif
                </div>

                <div class="rounded-lg bg-gray-50 py-2 dark:bg-gray-800">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Line Total</p>
                    <p class="mt-0.5 font-bold text-gray-900 dark:text-white">&#8358;{{ number_format($item->verifiedLineTotal(), 2) }}</p>
                    @if (abs($item->lineTotalVariance()) >= 0.01)
                        <p class="text-[11px] text-gray-500 line-through dark:text-gray-400">&#8358;{{ number_format($item->lineTotal(), 2) }}</p>
                    @endif
                </div>
            </div>

            {{-- The disagreement in full: both figures, the variance, and who
                 said so. Struck-through numbers above give the gist; this is
                 the part somebody has to be able to point at later. --}}
            @if ($corrected)
                <div class="mt-3 rounded-lg border border-amber-200 bg-white/60 p-3 text-sm dark:border-amber-500/30 dark:bg-gray-900/40">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        @if ($qtyVar !== 0)
                            <span class="text-gray-700 dark:text-gray-300">
                                Qty <span class="font-medium">{{ number_format($item->quantity) }}</span>
                                &rarr; <span class="font-semibold">{{ number_format($item->verifiedQuantity()) }}</span>
                                <span @class([
                                    'ml-1 font-semibold',
                                    'text-red-600 dark:text-red-400' => $qtyVar < 0,
                                    'text-green-600 dark:text-green-400' => $qtyVar > 0,
                                ])>({{ $qtyVar > 0 ? '+' : '' }}{{ number_format($qtyVar) }})</span>
                            </span>
                        @endif

                        @if (abs($costVar) >= 0.01)
                            <span class="text-gray-700 dark:text-gray-300">
                                Cost <span class="font-medium">&#8358;{{ number_format((float) $item->unit_cost, 2) }}</span>
                                &rarr; <span class="font-semibold">&#8358;{{ number_format($item->verifiedUnitCost(), 2) }}</span>
                                <span @class([
                                    'ml-1 font-semibold',
                                    'text-red-600 dark:text-red-400' => $costVar > 0,
                                    'text-green-600 dark:text-green-400' => $costVar < 0,
                                ])>({{ $costVar > 0 ? '+' : '' }}&#8358;{{ number_format($costVar, 2) }})</span>
                            </span>
                        @endif
                    </div>

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ $correction->correctedBy->name ?? 'Someone' }}
                        &middot; {{ $correction->corrected_at?->diffForHumans() }}
                        @if ($correction->note)
                            &middot; <span class="italic">{{ $correction->note }}</span>
                        @endif
                    </p>
                </div>
            @endif

            {{-- The action sits on the line it changes. Correcting from a
                 single batch-wide form means holding twelve rows in your head
                 while looking at one carton. --}}
            @if ($canCorrect)
                <button
                    type="button"
                    wire:click="mountAction('correctLine', { item: {{ $item->id }} })"
                    wire:loading.attr="disabled"
                    class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 sm:w-auto"
                >
                    <x-heroicon-m-pencil-square class="h-4 w-4"/>
                    {{ $corrected ? 'Correct again' : 'Correct' }}
                </button>
            @endif
        </div>
    @endforeach

    {{-- Approve stays on the screen rather than at the end of it.
         A delivery of twenty lines put this button twenty cards down, so the
         person who had just finished checking the goods had to scroll back
         through everything they had already read to act on it. Sticky, it is
         reachable from wherever they happen to be in the list.

         The safe-area padding is for phones with a home indicator, where a
         button flush to bottom-0 sits under it and takes two taps. --}}
    @if ($canApprove)
        <div
            class="sticky bottom-0 z-10 -mx-4 mt-2 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur-sm dark:border-white/10 dark:bg-gray-900/95 sm:-mx-6 sm:px-6"
            style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom));"
        >
            <button
                type="button"
                wire:click="mountAction('approve')"
                wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-green-700 disabled:opacity-50"
            >
                <x-heroicon-m-check-circle class="h-5 w-5"/>
                {{ $isCorrected ? 'Agree & Update Stock' : 'Approve & Update Stock' }}
            </button>
        </div>
    @endif
</div>
