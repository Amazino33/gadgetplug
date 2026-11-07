<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Today's Total Cost</span>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">
                {{ $this->getTodayTotalCostUsd() }}
            </div>
            <p class="text-xs text-gray-500 mt-1">All active vendors today</p>
        </div>

        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Today's Messages</span>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1">
                {{ number_format($this->getTodayTotalMessages()) }}
            </div>
            <p class="text-xs text-gray-500 mt-1">Across all assistant threads</p>
        </div>

        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Default Model</span>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1">
                GPT-4o mini
            </div>
            <p class="text-xs text-gray-500 mt-1">$0.15 / 1M in &bull; $0.60 / 1M out</p>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>

