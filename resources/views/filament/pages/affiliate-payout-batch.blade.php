<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Only affiliates whose available balance meets the configured minimum payout amount are listed below.
            Select the affiliates you have paid manually (bank transfer) and mark them as paid — this writes a
            wallet debit and closes out their balance for this batch.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
