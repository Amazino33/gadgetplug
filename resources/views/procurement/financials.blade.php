<x-layouts.procurement title="New Procurement — Step 3">

    {{-- Stepper --}}
    <div class="bg-white rounded-xl p-6 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] border border-[#becab5]/30 mb-6">
        <h1 class="text-2xl font-bold text-[#191c1d] mb-4" style="font-family:'Montserrat',sans-serif;">Financial Details</h1>
        <div class="flex items-center justify-between relative">
            <div class="absolute left-4 right-4 top-4 h-0.5 bg-[#e1e3e4] -z-10"></div>
            <div class="absolute left-4 top-4 h-0.5 bg-[#016c00] -z-10" style="width:50%"></div>
            @foreach([['1','Supplier','completed'],['2','Items','completed'],['3','Financials','active'],['4','Confirm','pending']] as [$num,$label,$state])
            <div class="flex flex-col items-center gap-2 bg-white px-2">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold
                    {{ $state === 'completed' ? 'bg-[#016c00] text-white' : ($state === 'active' ? 'bg-[#016c00] text-white ring-4 ring-[#016c00]/20' : 'bg-[#e7e8e9] text-[#6f7b68]') }}"
                    style="font-family:'Montserrat',sans-serif;">
                    {{ $state === 'completed' ? '✓' : $num }}
                </div>
                <span class="text-xs font-semibold {{ $state === 'active' ? 'text-[#016c00]' : 'text-[#6f7b68]' }}">{{ $label }}</span>
            </div>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        {{-- Left Column --}}
        <div class="lg:col-span-8 space-y-6">
            <form method="POST" action="{{ route('procurement.storeFinancials') }}" id="financialsForm">
                @csrf

                {{-- Payment Method --}}
                <div class="bg-white rounded-xl p-6 border border-[#becab5]/30 shadow-[0px_4px_20px_rgba(0,0,0,0.04)]">
                    <h2 class="text-base font-semibold text-[#191c1d] border-b border-[#e1e3e4] pb-3 mb-5"
                        style="font-family:'Montserrat',sans-serif;">Payment Information</h2>

                    <div class="mb-5">
                        <label class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider block mb-3">Payment Method</label>
                        <div class="grid grid-cols-3 gap-4">
                            @foreach([['bank_transfer','account_balance','Bank Transfer'],['cash','payments','Cash'],['credit','credit_card','Credit']] as [$val,$icon,$lbl])
                            <label class="flex flex-col items-center justify-center p-4 border border-[#becab5] rounded-lg cursor-pointer hover:bg-[#f3f4f5] transition-colors
                                has-[:checked]:border-[#016c00] has-[:checked]:bg-green-50 has-[:checked]:text-[#016c00] text-[#6f7b68]">
                                <input type="radio" name="payment_method" value="{{ $val }}"
                                    {{ old('payment_method', $financials['payment_method'] ?? 'bank_transfer') === $val ? 'checked' : '' }}
                                    class="sr-only" required>
                                <span class="material-symbols-outlined mb-1">{{ $icon }}</span>
                                <span class="text-xs font-bold">{{ $lbl }}</span>
                            </label>
                            @endforeach
                        </div>
                        @error('payment_method')
                            <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider block mb-2">Amount Paid (₦)</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[#6f7b68] font-bold">₦</span>
                                <input type="number" name="amount_paid" id="amountPaid"
                                    value="{{ old('amount_paid', $financials['amount_paid'] ?? 0) }}" min="0" step="0.01"
                                    oninput="updateBalance()"
                                    class="w-full pl-8 pr-4 py-2.5 border border-[#becab5] rounded-lg text-sm font-bold focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none"
                                    required>
                            </div>
                            @error('amount_paid')
                                <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider block mb-2">Reference Number / Note</label>
                            <input type="text" name="reference_number"
                                value="{{ old('reference_number', $financials['reference_number'] ?? '') }}"
                                placeholder="e.g. TRF-2024-09-01"
                                class="w-full px-4 py-2.5 border border-[#becab5] rounded-lg text-sm focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none">
                        </div>
                    </div>
                </div>

            </form>
        </div>

        {{-- Right Column: Order Summary --}}
        <div class="lg:col-span-4 sticky top-24">
            <div class="bg-white rounded-xl border border-[#becab5]/30 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] overflow-hidden">
                <div class="px-5 py-4 border-b border-[#e1e3e4]">
                    <h2 class="text-base font-semibold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">Order Summary</h2>
                </div>
                <div class="p-5 space-y-3">
                    @foreach($items as $item)
                    @php $product = $products[$item['product_id']] ?? null; @endphp
                    @if($product)
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-semibold text-[#191c1d]">{{ $product->name }}</p>
                            <p class="text-xs text-[#6f7b68]">Qty: {{ $item['quantity'] }}</p>
                        </div>
                        <span class="text-sm font-bold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">
                            ₦{{ number_format($item['quantity'] * $item['unit_cost'], 2) }}
                        </span>
                    </div>
                    @endif
                    @endforeach

                    <div class="border-t border-[#e1e3e4] pt-3 space-y-2">
                        <div class="flex justify-between text-sm text-[#6f7b68]">
                            <span>Subtotal</span>
                            <span class="font-bold" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($subtotal, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-sm text-[#6f7b68]">
                            <span>Tax (0%)</span>
                            <span class="font-bold">₦0.00</span>
                        </div>
                        <div id="balanceRow" class="flex justify-between text-sm text-orange-600 hidden">
                            <span>Balance Due</span>
                            <span class="font-bold" id="balanceAmount">₦0.00</span>
                        </div>
                    </div>
                </div>
                <div class="px-5 py-4 flex justify-between items-center border-t border-[#e1e3e4]">
                    <span class="text-base font-semibold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">Total Order Value</span>
                    <span class="text-xl font-bold text-[#016c00]" style="font-family:'Montserrat',sans-serif;">₦{{ number_format($subtotal, 2) }}</span>
                </div>
            </div>

        </div>
    </div>

    {{-- Bottom Bar --}}
    <div class="sticky bottom-0 bg-white border-t border-[#e1e3e4] flex justify-between items-center px-6 py-4 -mx-6 shadow-[0px_-4px_20px_rgba(0,0,0,0.04)] mt-6">
        <a href="{{ route('procurement.items') }}"
            class="flex items-center gap-2 px-6 py-2.5 border border-[#becab5] rounded-lg text-[#6f7b68] text-sm font-semibold hover:bg-[#f3f4f5] transition-colors">
            <span class="material-symbols-outlined text-sm">arrow_back</span> Back
        </a>
        <button type="submit" form="financialsForm"
            class="flex items-center gap-2 px-6 py-2.5 bg-[#016c00] text-white text-sm font-bold rounded-lg hover:bg-green-800 transition-colors"
            style="font-family:'Montserrat',sans-serif;">
            Next: Review & Confirm <span class="material-symbols-outlined text-sm">arrow_forward</span>
        </button>
    </div>

    <script>
        const subtotal = {{ $subtotal }};
        function updateBalance() {
            const paid = parseFloat(document.getElementById('amountPaid').value || 0);
            const balance = subtotal - paid;
            const row = document.getElementById('balanceRow');
            const amt = document.getElementById('balanceAmount');
            if (balance > 0) {
                row.classList.remove('hidden');
                amt.textContent = '₦' + new Intl.NumberFormat('en-NG', {minimumFractionDigits: 2}).format(balance);
            } else {
                row.classList.add('hidden');
            }
        }
    </script>
</x-layouts.procurement>
