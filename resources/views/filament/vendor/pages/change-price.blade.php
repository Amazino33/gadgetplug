<x-filament-panels::page>
    <div class="max-w-3xl mx-auto space-y-6">
        
        @if($isSuccess)
            <div class="bg-green-50 border border-green-200 rounded-2xl p-8 text-center">
                @svg('heroicon-o-check-circle', 'w-16 h-16 text-green-500 mx-auto mb-4')
                <h2 class="text-2xl font-bold text-green-800">Price Updated!</h2>
                <p class="text-green-700 mt-2">The new price has been saved globally and logged.</p>
                
                <button wire:click="cancelEdit" class="mt-6 px-6 py-3 bg-white border border-gray-300 rounded-xl font-bold text-gray-700 shadow-sm active:bg-gray-50">
                    Change Another Price
                </button>
            </div>
        @elseif(!$selectedProductId)
            <!-- Search State -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Find a Product</h2>
                
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        @svg('heroicon-o-magnifying-glass', 'h-6 w-6 text-gray-400')
                    </div>
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search by name, SKU, or barcode..." class="block w-full pl-12 pr-4 py-4 text-lg border-gray-300 dark:border-gray-600 rounded-xl focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white shadow-sm" autofocus>
                </div>
                
                @if(strlen($search) > 0)
                    <div class="mt-4 space-y-2">
                        @forelse($this->products as $product)
                            <button wire:click="selectProduct({{ $product->id }})" class="w-full flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl text-left transition">
                                <div>
                                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $product->name }}</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">SKU: {{ $product->sku ?? 'N/A' }} &middot; Current Price: ₦{{ number_format((float) $product->price) }}</p>
                                </div>
                                @svg('heroicon-m-chevron-right', 'w-6 h-6 text-gray-400')
                            </button>
                        @empty
                            <div class="p-6 text-center text-gray-500 dark:text-gray-400">
                                No products found matching "{{ $search }}" in this branch.
                            </div>
                        @endforelse
                    </div>
                @endif
            </div>
        @else
            <!-- Edit State -->
            @php 
                $product = $this->selectedProduct; 
                $cost = (float) $product->cost_price;
                $currentMargin = $cost > 0 && $newPrice > 0 ? (($newPrice - $cost) / $newPrice) * 100 : 0;
                $isLoss = $cost > 0 && $newPrice < $cost;
            @endphp
            
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $product->name }}</h2>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">SKU: {{ $product->sku ?? 'N/A' }}</p>
                    </div>
                    <button wire:click="cancelEdit" class="p-2 text-gray-400 hover:text-gray-600 bg-gray-100 rounded-full">
                        @svg('heroicon-m-x-mark', 'w-6 h-6')
                    </button>
                </div>
                
                <div class="p-6 bg-gray-50 dark:bg-gray-800/50 grid grid-cols-3 gap-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="text-center">
                        <span class="block text-xs font-semibold text-gray-500 uppercase tracking-wider">Current Cost</span>
                        <span class="block mt-1 text-lg font-bold text-gray-900 dark:text-white">{{ $cost > 0 ? '₦'.number_format($cost) : 'Not Set' }}</span>
                    </div>
                    <div class="text-center border-l border-gray-200 dark:border-gray-700">
                        <span class="block text-xs font-semibold text-gray-500 uppercase tracking-wider">Current Price</span>
                        <span class="block mt-1 text-lg font-bold text-gray-900 dark:text-white">₦{{ number_format((float) $product->price) }}</span>
                    </div>
                    <div class="text-center border-l border-gray-200 dark:border-gray-700">
                        <span class="block text-xs font-semibold text-gray-500 uppercase tracking-wider">New Margin</span>
                        <span class="block mt-1 text-lg font-bold {{ $isLoss ? 'text-red-600' : 'text-green-600' }}">{{ $cost > 0 ? number_format($currentMargin, 1).'%' : 'N/A' }}</span>
                    </div>
                </div>

                <div class="p-6 space-y-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">New Selling Price (₦)</label>
                        <input type="number" wire:model.live="newPrice" class="block w-full text-center text-4xl font-black py-4 border-gray-300 dark:border-gray-600 rounded-2xl focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white shadow-sm">
                    </div>
                    
                    <div class="grid grid-cols-4 gap-3">
                        <button wire:click="adjustPrice(-1000)" class="py-3 bg-red-50 text-red-700 font-bold rounded-xl border border-red-200 active:bg-red-100">-1k</button>
                        <button wire:click="adjustPrice(-500)" class="py-3 bg-red-50 text-red-700 font-bold rounded-xl border border-red-200 active:bg-red-100">-500</button>
                        <button wire:click="adjustPrice(500)" class="py-3 bg-green-50 text-green-700 font-bold rounded-xl border border-green-200 active:bg-green-100">+500</button>
                        <button wire:click="adjustPrice(1000)" class="py-3 bg-green-50 text-green-700 font-bold rounded-xl border border-green-200 active:bg-green-100">+1k</button>
                    </div>

                    <div class="bg-blue-50 text-blue-800 p-4 rounded-xl flex items-start text-sm">
                        @svg('heroicon-m-information-circle', 'w-5 h-5 mr-2 flex-shrink-0 mt-0.5')
                        <p><strong>Note:</strong> Pricing is set per-vendor. This new price will apply immediately <strong>across all your branches</strong>.</p>
                    </div>

                    @if($requiresConfirmation)
                        <div class="bg-red-50 border border-red-200 rounded-xl p-5">
                            <div class="flex items-start">
                                @svg('heroicon-s-exclamation-triangle', 'w-6 h-6 text-red-600 mr-3 flex-shrink-0')
                                <div>
                                    <h3 class="text-lg font-bold text-red-800">Selling Below Cost!</h3>
                                    <p class="text-red-700 mt-1">The new price (₦{{ number_format($newPrice) }}) is lower than your cost price (₦{{ number_format($cost) }}). You will lose money on each sale.</p>
                                </div>
                            </div>
                            <button wire:click="savePrice" class="mt-4 w-full py-4 bg-red-600 text-white font-bold text-lg rounded-xl shadow active:bg-red-700">
                                Yes, I want to clear stock at a loss
                            </button>
                        </div>
                    @else
                        <button wire:click="savePrice" class="w-full py-4 bg-primary-600 text-white font-bold text-xl rounded-xl shadow-lg active:bg-primary-700" @disabled($newPrice <= 0)>
                            Save New Price
                        </button>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
