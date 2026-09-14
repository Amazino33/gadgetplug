<div class="space-y-6" x-data="{ hideSales: localStorage.getItem('hideSales') === 'true' }" x-init="$watch('hideSales', val => localStorage.setItem('hideSales', val))">
    <!-- Greeting Header -->
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ $greeting }}, {{ $user->name }} 👋<br>
            <span class="text-base font-normal text-gray-500 dark:text-gray-400">What would you like to do today?</span>
        </h1>
    </div>

    <!-- Store Switcher -->
    @if(count($stores) > 1)
    <div class="w-full">
        <label for="store-switcher" class="sr-only">Active Store</label>
        <select id="store-switcher" wire:model.live="storeId" wire:change="switchStore($event.target.value)" class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white py-3 text-lg">
            @foreach($stores as $store)
                <option value="{{ $store->id }}">{{ $store->name }}</option>
            @endforeach
        </select>
    </div>
    @endif

    <!-- Live Stat Strip -->
    @if(isset($summary))
    <div class="grid grid-cols-3 gap-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 text-center border border-gray-200 dark:border-gray-700 relative">
            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider flex items-center justify-center gap-1">
                Sales
                <button @click="hideSales = !hideSales" class="text-gray-400 hover:text-gray-600 focus:outline-none flex items-center justify-center">
                    <span x-show="!hideSales">
                        @svg('heroicon-o-eye', 'w-4 h-4')
                    </span>
                    <span x-show="hideSales" style="display: none;">
                        @svg('heroicon-o-eye-slash', 'w-4 h-4')
                    </span>
                </button>
            </p>
            <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">
                <span x-show="!hideSales">₦{{ number_format($summary['revenue']) }}</span>
                <span x-show="hideSales" style="display: none;">****</span>
            </p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 text-center border border-gray-200 dark:border-gray-700">
            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider">Orders</p>
            <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">{{ number_format($summary['orders']) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 text-center border border-gray-200 dark:border-gray-700">
            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider">Low Stock</p>
            <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">
                @php
                    // Count was computed in component
                    $lowStock = 0;
                    foreach($alerts as $a) if($a['id'] == 'low_stock') $lowStock = (int) filter_var($a['label'], FILTER_SANITIZE_NUMBER_INT);
                @endphp
                {{ $lowStock }}
            </p>
        </div>
    </div>
    @endif

    <!-- Alerts Section -->
    @if(count($alerts) > 0)
    <div class="space-y-3">
        @foreach($alerts as $alert)
        <a href="{{ $alert['route'] }}" class="block">
            <div class="flex items-center p-4 rounded-xl {{ $alert['color'] }}">
                @svg($alert['icon'], 'w-6 h-6 mr-3')
                <span class="font-medium">{{ $alert['label'] }}</span>
            </div>
        </a>
        @endforeach
    </div>
    @endif

    <!-- Hero Actions -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        @foreach($tiles->where('is_hero', true) as $tile)
            @if($tile['route'])
                <a href="{{ $tile['route'] }}" class="block">
            @else
                <div class="block">
            @endif
            
            <div class="flex flex-col items-center justify-center p-6 rounded-2xl shadow-sm h-32 {{ $tile['color'] }} transition transform active:scale-95">
                @svg($tile['icon'], 'w-8 h-8 mb-2')
                <span class="text-lg font-bold">{{ $tile['label'] }}</span>
                @if(isset($tile['value']))
                    @if($tile['id'] === 'today_sales')
                        <span class="text-xl font-extrabold mt-1">
                            <span x-show="!hideSales">{{ $tile['value'] }}</span>
                            <span x-show="hideSales" style="display: none;">****</span>
                        </span>
                    @else
                        <span class="text-xl font-extrabold mt-1">{{ $tile['value'] }}</span>
                    @endif
                @endif
                @if(isset($tile['subtext']))
                    @if($tile['id'] === 'today_sales')
                        <span class="text-sm font-medium opacity-90 mt-1">
                            <span x-show="!hideSales">{{ $tile['subtext'] }}</span>
                            <span x-show="hideSales" style="display: none;">Profit: ****</span>
                        </span>
                    @else
                        <span class="text-sm font-medium opacity-90 mt-1">{{ $tile['subtext'] }}</span>
                    @endif
                @endif
            </div>

            @if($tile['route'])
                </a>
            @else
                </div>
            @endif
        @endforeach
    </div>

    <!-- Quick Actions -->
    <div class="grid grid-cols-2 gap-4">
        @foreach($tiles->where('is_hero', false) as $tile)
            @if($tile['route'])
                <a href="{{ $tile['route'] }}" class="block">
            @else
                <div class="block">
            @endif
                <div class="flex flex-col items-center justify-center p-5 rounded-2xl shadow-sm h-28 {{ $tile['color'] }} transition transform active:scale-95 dark:bg-gray-800 dark:border-gray-700">
                    @svg($tile['icon'], 'w-7 h-7 mb-2 text-gray-700 dark:text-gray-300')
                    <span class="text-base font-semibold text-gray-900 dark:text-white text-center">{{ $tile['label'] }}</span>
                    @if(isset($tile['value']))
                        @if($tile['id'] === 'today_sales')
                            <span class="text-lg font-extrabold mt-1 text-gray-900 dark:text-white">
                                <span x-show="!hideSales">{{ $tile['value'] }}</span>
                                <span x-show="hideSales" style="display: none;">****</span>
                            </span>
                        @else
                            <span class="text-lg font-extrabold mt-1 text-gray-900 dark:text-white">{{ $tile['value'] }}</span>
                        @endif
                    @endif
                </div>
            @if($tile['route'])
                </a>
            @else
                </div>
            @endif
        @endforeach
    </div>
</div>
