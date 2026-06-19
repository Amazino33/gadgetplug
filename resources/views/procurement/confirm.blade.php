<x-layouts.procurement title="New Procurement — Confirm">

    {{-- Stepper --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl p-6 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] border border-[#becab5]/30 dark:border-zinc-700 mb-6">
        <h1 class="text-2xl font-bold text-[#191c1d] dark:text-zinc-100 mb-1" style="font-family:'Montserrat',sans-serif;">Review & Confirm</h1>
        <p class="text-sm text-[#6f7b68] dark:text-zinc-400 mb-4">Please review your procurement before submitting for approval.</p>
        <div class="flex items-center justify-between relative">
            <div class="absolute left-4 right-4 top-4 h-0.5 bg-[#e1e3e4] dark:bg-zinc-700 -z-10"></div>
            <div class="absolute left-4 top-4 h-0.5 bg-[#016c00] -z-10" style="width:75%"></div>
            @foreach([['1','Supplier','completed'],['2','Items','completed'],['3','Financials','completed'],['4','Confirm','active']] as [$num,$label,$state])
            <div class="flex flex-col items-center gap-2 bg-white dark:bg-zinc-800 px-2">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold
                    {{ $state === 'completed' ? 'bg-[#016c00] text-white' : ($state === 'active' ? 'bg-[#016c00] text-white ring-4 ring-[#016c00]/20' : 'bg-[#e7e8e9] dark:bg-zinc-700 text-[#6f7b68] dark:text-zinc-400') }}"
                    style="font-family:'Montserrat',sans-serif;">
                    {{ $state === 'completed' ? '✓' : $num }}
                </div>
                <span class="text-xs font-semibold {{ $state === 'active' ? 'text-[#016c00] dark:text-green-400' : 'text-[#6f7b68] dark:text-zinc-400' }}">{{ $label }}</span>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Warning Notice --}}
    <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-700 rounded-xl p-4 flex gap-3 mb-6">
        <span class="material-symbols-outlined text-orange-500 dark:text-orange-400 shrink-0">info</span>
        <div>
            <p class="text-sm font-semibold text-orange-800 dark:text-orange-300">Pending Approval</p>
            <p class="text-xs text-orange-700 dark:text-orange-400 mt-0.5">This procurement will be submitted as <strong>pending</strong> and must be approved before stock is updated.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        {{-- Left: Items --}}
        <div class="lg:col-span-8 space-y-4">

            {{-- Supplier Info --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl p-5 border border-[#becab5]/30 dark:border-zinc-700 shadow-[0px_4px_20px_rgba(0,0,0,0.04)]">
                <h2 class="text-xs font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider mb-3">Supplier</h2>
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-lg bg-[#e7e8e9] dark:bg-zinc-700 border border-[#becab5] dark:border-zinc-600 flex items-center justify-center">
                        <span class="text-base font-bold text-[#6f7b68] dark:text-zinc-400" style="font-family:'Montserrat',sans-serif;">
                            {{ strtoupper(substr($supplier->name, 0, 2)) }}
                        </span>
                    </div>
                    <div>
                        <p class="font-semibold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">{{ $supplier->name }}</p>
                        <p class="text-xs text-[#6f7b68] dark:text-zinc-400">{{ $supplier->location ?? '' }} {{ $supplier->phone ? '· '.$supplier->phone : '' }}</p>
                    </div>
                </div>
            </div>

            {{-- Items Table --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-[#becab5]/30 dark:border-zinc-700 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] overflow-hidden">
                <div class="px-5 py-4 border-b border-[#e1e3e4] dark:border-zinc-700">
                    <h2 class="text-base font-semibold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">Items ({{ count($items) }})</h2>
                </div>
                <div class="divide-y divide-[#e1e3e4] dark:divide-zinc-700">
                    @foreach($items as $item)
                    @php $product = $products[$item['product_id']] ?? null; @endphp
                    @if($product)
                    <div class="px-5 py-4 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3 flex-1">
                            <div class="w-10 h-10 bg-[#e7e8e9] dark:bg-zinc-700 rounded-lg flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-[20px] text-[#6f7b68] dark:text-zinc-400">smartphone</span>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-[#191c1d] dark:text-zinc-100">{{ $product->name }}</p>
                                @if($item['barcode'])
                                <p class="text-xs text-[#6f7b68] dark:text-zinc-400">IMEI: {{ $item['barcode'] }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider">Qty</p>
                            <p class="text-sm font-bold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">{{ $item['quantity'] }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider">Unit Cost</p>
                            <p class="text-sm font-bold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($item['unit_cost'], 2) }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider">Selling Price</p>
                            <p class="text-sm font-bold text-[#016c00] dark:text-green-400" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($item['selling_price'], 2) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider">Line Total</p>
                            <p class="text-sm font-bold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($item['quantity'] * $item['unit_cost'], 2) }}</p>
                        </div>
                    </div>
                    @endif
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Right: Payment Summary --}}
        <div class="lg:col-span-4">
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-[#becab5]/30 dark:border-zinc-700 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] overflow-hidden mb-4">
                <div class="px-5 py-4 border-b border-[#e1e3e4] dark:border-zinc-700">
                    <h2 class="text-base font-semibold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">Payment Summary</h2>
                </div>
                <div class="p-5 space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-[#6f7b68] dark:text-zinc-400">Method</span>
                        <span class="font-semibold text-[#191c1d] dark:text-zinc-100 capitalize">{{ str_replace('_', ' ', $financials['payment_method']) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-[#6f7b68] dark:text-zinc-400">Total Cost</span>
                        <span class="font-bold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($subtotal, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-[#6f7b68] dark:text-zinc-400">Amount Paid</span>
                        <span class="font-bold text-[#016c00] dark:text-green-400" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($financials['amount_paid'], 2) }}</span>
                    </div>
                    @if($subtotal - $financials['amount_paid'] > 0)
                    <div class="flex justify-between text-sm border-t border-[#e1e3e4] dark:border-zinc-700 pt-3">
                        <span class="text-orange-600 dark:text-orange-400">Balance Due</span>
                        <span class="font-bold text-orange-600 dark:text-orange-400" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($subtotal - $financials['amount_paid'], 2) }}</span>
                    </div>
                    @endif
                    @if($financials['reference_number'])
                    <div class="flex justify-between text-sm">
                        <span class="text-[#6f7b68] dark:text-zinc-400">Reference</span>
                        <span class="font-semibold text-[#191c1d] dark:text-zinc-100">{{ $financials['reference_number'] }}</span>
                    </div>
                    @endif
                </div>
            </div>

        </div>
    </div>

    {{-- Bottom Bar --}}
    <div class="sticky bottom-0 bg-white dark:bg-zinc-800 border-t border-[#e1e3e4] dark:border-zinc-700 flex justify-between items-center px-6 py-4 -mx-6 shadow-[0px_-4px_20px_rgba(0,0,0,0.04)] mt-6">
        <a href="{{ route('procurement.financials') }}"
            class="flex items-center gap-2 px-6 py-2.5 border border-[#becab5] dark:border-zinc-600 rounded-lg text-[#6f7b68] dark:text-zinc-400 text-sm font-semibold hover:bg-[#f3f4f5] dark:hover:bg-zinc-700 transition-colors">
            <span class="material-symbols-outlined text-sm">arrow_back</span> Back
        </a>
        <form method="POST" action="{{ route('procurement.submit') }}">
            @csrf
            <button type="submit"
                class="flex items-center gap-2 px-6 py-2.5 bg-[#016c00] text-white text-sm font-bold rounded-lg hover:bg-green-800 transition-colors"
                style="font-family:'Montserrat',sans-serif;">
                <span class="material-symbols-outlined text-sm">check_circle</span>
                Submit Procurement
            </button>
        </form>
    </div>
</x-layouts.procurement>
