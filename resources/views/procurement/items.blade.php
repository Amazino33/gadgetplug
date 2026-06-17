<x-layouts.procurement title="New Procurement — Step 2">

    {{-- Stepper --}}
    <div class="bg-white rounded-xl p-6 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] border border-[#becab5]/30 mb-6">
        <div class="flex justify-between items-start mb-4">
            <div>
                <h2 class="text-xl font-bold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">
                    Purchase Order
                </h2>
                <p class="text-sm text-[#6f7b68] mt-0.5">Supplier: {{ $supplier->name }}</p>
            </div>
            <div class="text-right">
                <p class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider">Total Estimated Value</p>
                <p class="text-2xl font-bold text-[#016c00]" style="font-family:'Montserrat',sans-serif;" id="grandTotal">₦ 0.00</p>
            </div>
        </div>
        <div class="flex items-center justify-between relative">
            <div class="absolute left-4 right-4 top-4 h-0.5 bg-[#e1e3e4] -z-10"></div>
            <div class="absolute left-4 w-1/4 top-4 h-0.5 bg-[#016c00] -z-10"></div>
            @foreach([['1','Supplier','completed'],['2','Items','active'],['3','Financials','pending'],['4','Confirm','pending']] as [$num,$label,$state])
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

    <form method="POST" action="{{ route('procurement.storeItems') }}" id="itemsForm">
        @csrf

        @error('items')
            <p class="text-red-600 text-sm mb-4">{{ $message }}</p>
        @enderror

        {{-- Items Header --}}
        <div class="flex justify-between items-center mb-4">
            <div class="flex items-center gap-2">
                <h3 class="text-base font-semibold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">Items Added</h3>
                <span class="bg-[#e7e8e9] text-[#191c1d] px-2 py-0.5 rounded-full text-xs font-bold" id="itemCount">0</span>
            </div>
            <div class="flex gap-2">
                <button type="button" onclick="addItem()"
                    class="flex items-center gap-1.5 px-4 py-2 border border-[#becab5] rounded-lg text-[#016c00] text-sm font-semibold hover:bg-[#f3f4f5] transition-colors">
                    <span class="material-symbols-outlined text-sm">add_circle</span> Add Item manually
                </button>
                <button type="button"
                    class="flex items-center gap-1.5 px-4 py-2 bg-[#B1FF00] text-[#121f00] text-sm font-bold rounded-lg hover:shadow-lg transition-all"
                    style="box-shadow: 0 0 10px rgba(177,255,0,0.3);">
                    <span class="material-symbols-outlined text-sm">barcode_scanner</span> Scan Next
                </button>
            </div>
        </div>

        {{-- Items List --}}
        <div id="itemsList" class="space-y-3 mb-4"></div>

        {{-- Add row placeholder --}}
        <div onclick="addItem()" class="bg-white rounded-xl border-2 border-dashed border-[#becab5] flex items-center justify-center h-20 hover:bg-[#f3f4f5] transition-colors cursor-pointer group mb-6">
            <div class="flex items-center gap-2 text-[#6f7b68] group-hover:text-[#016c00] transition-colors">
                <span class="material-symbols-outlined">add_box</span>
                <span class="text-sm font-semibold">Click to add new item row</span>
            </div>
        </div>

        {{-- Bottom Bar --}}
        <div class="sticky bottom-0 bg-white border-t border-[#e1e3e4] flex justify-between items-center px-6 py-4 -mx-6 shadow-[0px_-4px_20px_rgba(0,0,0,0.04)]">
            <a href="{{ route('procurement.create') }}"
                class="flex items-center gap-2 px-6 py-2.5 border border-[#becab5] rounded-lg text-[#6f7b68] text-sm font-semibold hover:bg-[#f3f4f5] transition-colors">
                <span class="material-symbols-outlined text-sm">arrow_back</span> Back
            </a>
            <div class="flex items-center gap-6">
                <div class="text-right hidden md:block">
                    <p class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider">Subtotal</p>
                    <p class="text-base font-bold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;" id="subtotalDisplay">₦ 0.00</p>
                </div>
                <button type="submit"
                    class="flex items-center gap-2 px-6 py-2.5 bg-[#016c00] text-white text-sm font-bold rounded-lg hover:bg-green-800 transition-colors"
                    style="font-family:'Montserrat',sans-serif;">
                    Next: Financials <span class="material-symbols-outlined text-sm">arrow_forward</span>
                </button>
            </div>
        </div>
    </form>

    {{-- Product data for JS --}}
    <script>
        const products = @json($products->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price ?? 0]));
        let itemIndex = 0;

        function addItem() {
            const list = document.getElementById('itemsList');
            const idx = itemIndex++;
            const options = products.map(p => `<option value="${p.id}">${p.name}</option>`).join('');

            const html = `
            <div class="item-row bg-white rounded-xl p-4 border border-[#becab5]/50 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] flex flex-col lg:flex-row gap-4 lg:items-end relative" id="row_${idx}">
                <button type="button" onclick="removeItem(${idx})"
                    class="absolute top-3 right-3 p-1.5 text-red-400 hover:bg-red-50 rounded-lg transition-colors">
                    <span class="material-symbols-outlined text-[18px]">delete</span>
                </button>

                <div class="flex-1 min-w-[180px]">
                    <label class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider block mb-1">Product</label>
                    <select name="items[${idx}][product_id]" required onchange="onProductChange(this, ${idx})"
                        class="w-full px-3 py-2 border border-[#becab5] rounded-lg text-sm focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none bg-white">
                        <option value="">Select product...</option>
                        ${options}
                    </select>
                </div>

                <div class="w-full lg:w-44">
                    <label class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider block mb-1">IMEI / Serial</label>
                    <div class="relative">
                        <input type="text" name="items[${idx}][barcode]" placeholder="Scan or type..."
                            class="w-full px-3 py-2 pr-8 border border-[#becab5] rounded-lg text-sm focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none">
                        <span class="material-symbols-outlined absolute right-2 top-2 text-[#6f7b68] text-[16px] cursor-pointer hover:text-[#016c00]">qr_code_scanner</span>
                    </div>
                </div>

                <div class="w-full lg:w-32">
                    <label class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider block mb-1">Qty</label>
                    <div class="flex items-center border border-[#becab5] rounded-lg overflow-hidden h-9">
                        <button type="button" onclick="changeQty(${idx}, -1)" class="px-2 text-[#6f7b68] hover:bg-[#e7e8e9] h-full transition-colors">
                            <span class="material-symbols-outlined text-[16px]">remove</span>
                        </button>
                        <input type="number" name="items[${idx}][quantity]" value="1" min="1"
                            id="qty_${idx}" onchange="recalculate()"
                            class="w-12 text-center border-none focus:ring-0 text-sm font-bold bg-transparent p-0 h-full">
                        <button type="button" onclick="changeQty(${idx}, 1)" class="px-2 text-[#6f7b68] hover:bg-[#e7e8e9] h-full transition-colors">
                            <span class="material-symbols-outlined text-[16px]">add</span>
                        </button>
                    </div>
                </div>

                <div class="w-full lg:w-36">
                    <label class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider block mb-1">Unit Cost</label>
                    <div class="relative">
                        <span class="absolute left-2 top-2 text-[#6f7b68] text-sm font-bold">₦</span>
                        <input type="number" name="items[${idx}][unit_cost]" placeholder="0.00" min="0" step="0.01"
                            id="cost_${idx}" onchange="recalculate()"
                            class="w-full pl-6 pr-3 py-2 border border-[#becab5] rounded-lg text-sm font-bold focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none">
                    </div>
                </div>

                <div class="w-full lg:w-36">
                    <label class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider block mb-1">Selling Price</label>
                    <div class="relative">
                        <span class="absolute left-2 top-2 text-[#6f7b68] text-sm font-bold">₦</span>
                        <input type="number" name="items[${idx}][selling_price]" placeholder="0.00" min="0" step="0.01"
                            id="price_${idx}"
                            class="w-full pl-6 pr-3 py-2 border border-[#becab5] rounded-lg text-sm font-bold text-[#016c00] focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none">
                    </div>
                </div>
            </div>`;

            list.insertAdjacentHTML('beforeend', html);
            updateCount();
        }

        function removeItem(idx) {
            document.getElementById(`row_${idx}`)?.remove();
            updateCount();
            recalculate();
        }

        function changeQty(idx, delta) {
            const input = document.getElementById(`qty_${idx}`);
            input.value = Math.max(1, parseInt(input.value || 1) + delta);
            recalculate();
        }

        function onProductChange(select, idx) {
            const product = products.find(p => p.id == select.value);
            if (product) {
                const priceInput = document.getElementById(`price_${idx}`);
                if (priceInput && !priceInput.value) priceInput.value = product.price;
            }
            recalculate();
        }

        function recalculate() {
            const rows = document.querySelectorAll('.item-row');
            let total = 0;
            rows.forEach((row, i) => {
                const qty = parseFloat(row.querySelector('[name*="[quantity]"]')?.value || 0);
                const cost = parseFloat(row.querySelector('[name*="[unit_cost]"]')?.value || 0);
                total += qty * cost;
            });
            const fmt = new Intl.NumberFormat('en-NG', {minimumFractionDigits: 2}).format(total);
            document.getElementById('subtotalDisplay').textContent = '₦ ' + fmt;
            document.getElementById('grandTotal').textContent = '₦ ' + fmt;
        }

        function updateCount() {
            document.getElementById('itemCount').textContent = document.querySelectorAll('.item-row').length;
        }

        // Add one row on load
        addItem();
    </script>
</x-layouts.procurement>
