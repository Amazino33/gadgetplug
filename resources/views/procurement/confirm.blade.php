<x-layouts.procurement title="New Procurement — Confirm">

    {{-- Stepper --}}
    <div class="bg-white rounded-xl p-6 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] border border-[#becab5]/30 mb-6">
        <div class="flex items-center gap-2 text-xs font-bold text-[#6f7b68] uppercase tracking-wider mb-5">
            <span>Supplier</span>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            <span>Items</span>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            <span>Financials</span>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            <span class="text-[#016c00]">Confirm</span>
        </div>
        <h1 class="text-2xl font-bold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">Review & Confirm</h1>
        <p class="text-sm text-[#6f7b68] mt-1">Please review your procurement before submitting for approval.</p>
    </div>

    {{-- Warning Notice --}}
    <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 flex gap-3 mb-6">
        <span class="material-symbols-outlined text-orange-500 shrink-0">info</span>
        <div>
            <p class="text-sm font-semibold text-orange-800">Pending Approval</p>
            <p class="text-xs text-orange-700 mt-0.5">This procurement will be submitted as <strong>pending</strong> and must be approved by an administrator before stock is updated.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        {{-- Left: Items --}}
        <div class="lg:col-span-8 space-y-4">

            {{-- Supplier Info --}}
            <div class="bg-white rounded-xl p-5 border border-[#becab5]/30 shadow-[0px_4px_20px_rgba(0,0,0,0.04)]">
                <h2 class="text-xs font-bold text-[#6f7b68] uppercase tracking-wider mb-3">Supplier</h2>
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-lg bg-[#e7e8e9] border border-[#becab5] flex items-center justify-center">
                        <span class="text-base font-bold text-[#6f7b68]" style="font-family:'Montserrat',sans-serif;">
                            {{ strtoupper(substr($supplier->name, 0, 2)) }}
                        </span>
                    </div>
                    <div>
                        <p class="font-semibold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">{{ $supplier->name }}</p>
                        <p class="text-xs text-[#6f7b68]">{{ $supplier->location ?? '' }} {{ $supplier->phone ? '· '.$supplier->phone : '' }}</p>
                    </div>
                </div>
            </div>

            {{-- Items Table --}}
            <div class="bg-white rounded-xl border border-[#becab5]/30 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] overflow-hidden">
                <div class="px-5 py-4 border-b border-[#e1e3e4] bg-[#f3f4f5]">
                    <h2 class="text-xs font-bold text-[#6f7b68] uppercase tracking-wider">Items ({{ count($items) }})</h2>
                </div>
                <div class="divide-y divide-[#e1e3e4]">
                    @foreach($items as $item)
                    @php $product = $products[$item['product_id']] ?? null; @endphp
                    @if($product)
                    <div class="px-5 py-4 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3 flex-1">
                            <div class="w-10 h-10 bg-[#e7e8e9] rounded-lg flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-[20px] text-[#6f7b68]">smartphone</span>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-[#191c1d]">{{ $product->name }}</p>
                                @if($item['barcode'])
                                <p class="text-xs text-[#6f7b68]">IMEI: {{ $item['barcode'] }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] text-[#6f7b68] uppercase tracking-wider">Qty</p>
                            <p class="text-sm font-bold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">{{ $item['quantity'] }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] text-[#6f7b68] uppercase tracking-wider">Unit Cost</p>
                            <p class="text-sm font-bold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($item['unit_cost'], 2) }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] text-[#6f7b68] uppercase tracking-wider">Selling Price</p>
                            <p class="text-sm font-bold text-[#016c00]" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($item['selling_price'], 2) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] text-[#6f7b68] uppercase tracking-wider">Line Total</p>
                            <p class="text-sm font-bold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($item['quantity'] * $item['unit_cost'], 2) }}</p>
                        </div>
                    </div>
                    @endif
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Right: Payment Summary --}}
        <div class="lg:col-span-4">
            <div class="bg-white rounded-xl border border-[#becab5]/30 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] overflow-hidden mb-4">
                <div class="bg-[#f3f4f5] px-5 py-4 border-b border-[#e1e3e4]">
                    <h2 class="text-sm font-semibold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">Payment Summary</h2>
                </div>
                <div class="p-5 space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-[#6f7b68]">Method</span>
                        <span class="font-semibold text-[#191c1d] capitalize">{{ str_replace('_', ' ', $financials['payment_method']) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-[#6f7b68]">Total Cost</span>
                        <span class="font-bold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($subtotal, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-[#6f7b68]">Amount Paid</span>
                        <span class="font-bold text-[#016c00]" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($financials['amount_paid'], 2) }}</span>
                    </div>
                    @if($subtotal - $financials['amount_paid'] > 0)
                    <div class="flex justify-between text-sm border-t border-[#e1e3e4] pt-3">
                        <span class="text-orange-600">Balance Due</span>
                        <span class="font-bold text-orange-600" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($subtotal - $financials['amount_paid'], 2) }}</span>
                    </div>
                    @endif
                    @if($financials['reference_number'])
                    <div class="flex justify-between text-sm">
                        <span class="text-[#6f7b68]">Reference</span>
                        <span class="font-semibold text-[#191c1d]">{{ $financials['reference_number'] }}</span>
                    </div>
                    @endif
                </div>
            </div>

            <div class="flex gap-3">
                <a href="{{ route('procurement.financials') }}"
                    class="flex-1 text-center px-4 py-2.5 border border-[#becab5] rounded-lg text-[#016c00] text-sm font-semibold hover:bg-[#f3f4f5] transition-colors">
                    Back
                </a>
                <form method="POST" action="{{ route('procurement.submit') }}" class="flex-[2]">
                    @csrf
                    <button type="submit"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-[#016c00] text-white text-sm font-bold rounded-lg hover:bg-green-800 transition-colors"
                        style="font-family:'Montserrat',sans-serif;">
                        <span class="material-symbols-outlined text-sm">check_circle</span>
                        Submit Procurement
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.procurement>
