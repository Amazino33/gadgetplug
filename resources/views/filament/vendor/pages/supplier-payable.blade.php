<x-filament-panels::page>

    @php $rows = $this->balances(); @endphp

    @if ($rows->isEmpty())
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">No suppliers linked to you</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                When you sell from a supplier's catalogue, what you owe them for delivered orders appears here.
            </p>
        </div>
    @else
        {{-- What is owed, per supplier. Summed from the ledger on every read,
             so this cannot disagree with the statement below it. --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($rows as $row)
                <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-gray-950 dark:text-white">{{ $row['supplier'] }}</p>
                            @unless ($row['active'])
                                <p class="mt-0.5 text-xs text-amber-600 dark:text-amber-400">Link switched off</p>
                            @endunless
                        </div>
                        <span @class([
                            'shrink-0 text-lg font-bold',
                            'text-amber-600 dark:text-amber-400' => $row['balance'] > 0,
                            'text-gray-400 dark:text-gray-500' => $row['balance'] <= 0,
                        ])>₦{{ number_format($row['balance'], 2) }}</span>
                    </div>

                    <dl class="mt-3 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                        <div class="flex justify-between">
                            <dt>Owed in all</dt>
                            <dd>₦{{ number_format($row['charged'], 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Paid so far</dt>
                            <dd>₦{{ number_format($row['paid'], 2) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4">
                        {{ ($this->payAction)(['link' => $row['link']->id]) }}
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3 text-sm dark:bg-white/5">
            <span class="text-gray-500 dark:text-gray-400">Owed across every supplier</span>
            <span class="font-bold text-gray-950 dark:text-white">₦{{ number_format($this->totalOwed(), 2) }}</span>
        </div>

        {{-- The statement. Every charge and payment, never edited — a correction
             is another row, so the history always explains the balance. --}}
        <div class="fi-section mt-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-end gap-3 p-6 pb-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Statement</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Owed and paid over a period</p>
                </div>
                <div class="ml-auto flex gap-2">
                    <input type="date" wire:model.live="from"
                        class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white" />
                    <input type="date" wire:model.live="to"
                        class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white" />
                </div>
            </div>

            {{ $this->table }}
        </div>
    @endif

</x-filament-panels::page>
