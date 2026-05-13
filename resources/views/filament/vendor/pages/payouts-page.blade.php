<x-filament-panels::page>

    {{-- Balance Summary --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-5 flex flex-col gap-1 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Earned</span>
            <span class="text-2xl font-bold text-gray-900 dark:text-white">₦{{ number_format($totalEarned, 2) }}</span>
            <span class="text-xs text-gray-400 dark:text-gray-500">From paid & delivered orders</span>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-5 flex flex-col gap-1 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Paid Out + Pending</span>
            <span class="text-2xl font-bold text-gray-900 dark:text-white">₦{{ number_format($totalPaid + $totalPending, 2) }}</span>
            <span class="text-xs text-gray-400 dark:text-gray-500">₦{{ number_format($totalPaid, 2) }} paid · ₦{{ number_format($totalPending, 2) }} pending</span>
        </div>

        <div class="rounded-xl border border-amber-300 dark:border-amber-500/40 bg-amber-50 dark:bg-amber-500/10 p-5 flex flex-col gap-1 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-amber-600 dark:text-amber-400">Available Balance</span>
            <span class="text-2xl font-bold text-amber-700 dark:text-amber-300">₦{{ number_format($availableBalance, 2) }}</span>
            <span class="text-xs text-amber-500 dark:text-amber-400">Ready to withdraw</span>
        </div>
    </div>

    {{-- Request Form --}}
    @if ($availableBalance >= 500)
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-6 mb-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Request a Payout</h2>
            <form wire:submit="requestPayout">
                {{ $this->form }}
                <div class="mt-4">
                    <x-filament::button type="submit" color="primary" icon="heroicon-m-banknotes">
                        Request Payout
                    </x-filament::button>
                </div>
            </form>
        </div>
        <x-filament-actions::modals />
    @else
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-8 text-center mb-6 shadow-sm">
            <x-filament::icon icon="heroicon-o-banknotes" class="mx-auto h-10 w-10 mb-3 text-gray-300 dark:text-gray-600" />
            <p class="font-medium text-gray-700 dark:text-gray-300">Minimum withdrawal is ₦500</p>
            <p class="text-sm mt-1 text-gray-500 dark:text-gray-400">Keep selling to unlock your first payout.</p>
        </div>
    @endif

    {{-- History Table --}}
    <div class="mt-2">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Payout History</h2>
        {{ $this->table }}
    </div>

</x-filament-panels::page>
