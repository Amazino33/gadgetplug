<x-layouts.procurement title="New Procurement — Step 3">

    {{-- Stepper --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl p-6 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] border border-[#becab5]/30 dark:border-zinc-700 mb-6">
        <div class="flex justify-between items-start mb-4">
            <div>
                <h2 class="text-xl font-bold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">
                    Transport Cost
                </h2>
                <p class="text-sm text-[#6f7b68] dark:text-zinc-400 mt-0.5">Supplier: {{ $supplier->name }}</p>
            </div>
        </div>
        <div class="flex items-center justify-between relative">
            <div class="absolute left-4 right-4 top-4 h-0.5 bg-[#e1e3e4] dark:bg-zinc-700 -z-10"></div>
            <div class="absolute left-4 top-4 h-0.5 bg-[#016c00] -z-10" style="width:40%"></div>
            @foreach([['1','Supplier','completed'],['2','Items','completed'],['3','Logistics','active'],['4','Financials','pending'],['5','Confirm','pending']] as [$num,$label,$state])
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

    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-xl p-4 mb-6">
        <p class="text-sm text-blue-800 dark:text-blue-300">
            What did it cost to move this stock to your store? If it took more than one trip or hand-off (e.g. park to park, then park to store), add each stage below with what it cost. This is separate from what you paid the supplier for the goods, and separate from what you charge customers for delivery.
        </p>
    </div>

    <form method="POST" action="{{ route('procurement.storeLogistics') }}" id="logisticsForm">
        @csrf

        {{-- Stages Header --}}
        <div class="flex justify-between items-center mb-4">
            <div class="flex items-center gap-2">
                <h3 class="text-base font-semibold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">Transport Stages</h3>
                <span class="bg-[#e7e8e9] dark:bg-zinc-700 text-[#191c1d] dark:text-zinc-100 px-2 py-0.5 rounded-full text-xs font-bold" id="legCount">0</span>
            </div>
            <button type="button" onclick="addLeg()"
                class="flex items-center gap-1.5 px-4 py-2 border border-[#becab5] dark:border-zinc-600 rounded-lg text-[#016c00] dark:text-green-400 text-sm font-semibold hover:bg-[#f3f4f5] dark:hover:bg-zinc-700 transition-colors">
                <span class="material-symbols-outlined text-sm">add_circle</span> Add Another Stage
            </button>
        </div>

        {{-- Stages List --}}
        <div id="legsList" class="space-y-3 mb-4"></div>

        {{-- Add row placeholder --}}
        <div onclick="addLeg()" class="bg-white dark:bg-zinc-800 rounded-xl border-2 border-dashed border-[#becab5] dark:border-zinc-600 flex items-center justify-center h-20 hover:bg-[#f3f4f5] dark:hover:bg-zinc-700 transition-colors cursor-pointer group mb-6">
            <div class="flex items-center gap-2 text-[#6f7b68] dark:text-zinc-400 group-hover:text-[#016c00] dark:group-hover:text-green-400 transition-colors">
                <span class="material-symbols-outlined">add_box</span>
                <span class="text-sm font-semibold" id="addRowLabel">Click to add a transport stage</span>
            </div>
        </div>

        <p class="text-xs text-[#6f7b68] dark:text-zinc-400 mb-6">
            Optional — leave this empty and click "Next: Financials" if the supplier delivered straight to your store with no separate transport cost.
        </p>

        {{-- Bottom Bar --}}
        <div class="sticky bottom-0 bg-white dark:bg-zinc-800 border-t border-[#e1e3e4] dark:border-zinc-700 flex justify-between items-center px-6 py-4 -mx-6 shadow-[0px_-4px_20px_rgba(0,0,0,0.04)]">
            <a href="{{ route('procurement.items') }}"
                class="flex items-center gap-2 px-6 py-2.5 border border-[#becab5] dark:border-zinc-600 rounded-lg text-[#6f7b68] dark:text-zinc-400 text-sm font-semibold hover:bg-[#f3f4f5] dark:hover:bg-zinc-700 transition-colors">
                <span class="material-symbols-outlined text-sm">arrow_back</span> Back
            </a>
            <div class="flex items-center gap-6">
                <div class="text-right hidden md:block">
                    <p class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider">Total Transport Cost</p>
                    <p class="text-base font-bold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;" id="legsTotalDisplay">₦ 0.00</p>
                </div>
                <button type="submit"
                    class="flex items-center gap-2 px-6 py-2.5 bg-[#016c00] text-white text-sm font-bold rounded-lg hover:bg-green-800 transition-colors"
                    style="font-family:'Montserrat',sans-serif;">
                    Next: Financials <span class="material-symbols-outlined text-sm">arrow_forward</span>
                </button>
            </div>
        </div>
    </form>

    <script>
        const savedLegs = @json($legs);
        let legIndex = 0;

        function addLeg(prefill = null) {
            const list = document.getElementById('legsList');
            const idx = legIndex++;

            const html = `
            <div class="leg-row bg-white dark:bg-zinc-800 rounded-xl p-4 border border-[#becab5]/50 dark:border-zinc-700 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] flex flex-col lg:flex-row gap-4 lg:items-end" id="leg_${idx}">

                <div class="flex-1 min-w-[220px]">
                    <label class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider block mb-1">Stage (from → to)</label>
                    <input type="text" name="legs[${idx}][route_label]" placeholder="e.g. Supplier → Eket park"
                        value="${prefill?.route_label ?? ''}" required
                        class="w-full px-3 py-2 border border-[#becab5] dark:border-zinc-600 rounded-lg text-sm focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none bg-white dark:bg-zinc-900 dark:text-zinc-100">
                </div>

                <div class="w-full lg:w-44">
                    <label class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider block mb-1">Cost (₦)</label>
                    <div class="relative">
                        <span class="absolute left-2 top-2 text-[#6f7b68] dark:text-zinc-400 text-sm font-bold">₦</span>
                        <input type="number" name="legs[${idx}][amount]" placeholder="0.00" min="0" step="0.01"
                            value="${prefill?.amount ?? ''}" required
                            id="legAmount_${idx}" onchange="recalculateLegs()"
                            class="w-full pl-6 pr-3 py-2 border border-[#becab5] dark:border-zinc-600 rounded-lg text-sm font-bold focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none bg-white dark:bg-zinc-900 dark:text-zinc-100">
                    </div>
                </div>

                <button type="button" onclick="removeLeg(${idx})"
                    class="shrink-0 p-2 text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors mb-0.5">
                    <span class="material-symbols-outlined text-[18px]">delete</span>
                </button>
            </div>`;

            list.insertAdjacentHTML('beforeend', html);
            updateLegCount();
        }

        function removeLeg(idx) {
            document.getElementById(`leg_${idx}`)?.remove();
            updateLegCount();
            recalculateLegs();
        }

        function recalculateLegs() {
            const rows = document.querySelectorAll('.leg-row');
            let total = 0;
            rows.forEach((row) => {
                total += parseFloat(row.querySelector('[name*="[amount]"]')?.value || 0);
            });
            const fmt = new Intl.NumberFormat('en-NG', {minimumFractionDigits: 2}).format(total);
            document.getElementById('legsTotalDisplay').textContent = '₦ ' + fmt;
        }

        function updateLegCount() {
            const count = document.querySelectorAll('.leg-row').length;
            document.getElementById('legCount').textContent = count;
            document.getElementById('addRowLabel').textContent = count === 0
                ? 'Click to add a transport stage'
                : 'Click to add another stage';
        }

        // Restore saved stages (leave the list empty if none — this step is optional)
        if (savedLegs.length > 0) {
            savedLegs.forEach(leg => addLeg(leg));
            recalculateLegs();
        }
        updateLegCount();
    </script>
</x-layouts.procurement>
