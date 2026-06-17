<x-layouts.procurement title="New Procurement — Step 1">

    @if(session('success'))
    <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg text-sm">
        {{ session('success') }}
    </div>
    @endif

    {{-- Stepper --}}
    <div class="bg-white rounded-xl p-6 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] border border-[#becab5]/30 mb-6">
        <div class="flex items-center justify-between relative">
            <div class="absolute left-10 right-10 top-5 h-0.5 bg-[#e1e3e4] -z-10"></div>
            @foreach([['1','Supplier',true],['2','Items',false],['3','Financials',false],['4','Confirm',false]] as [$num,$label,$active])
            <div class="flex flex-col items-center gap-2 bg-white px-2">
                <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold shadow-sm
                    {{ $active ? 'bg-[#016c00] text-white' : 'bg-[#e7e8e9] text-[#6f7b68] border border-[#becab5]' }}"
                    style="font-family:'Montserrat',sans-serif;">
                    {{ $num }}
                </div>
                <span class="text-xs font-semibold {{ $active ? 'text-[#016c00]' : 'text-[#6f7b68]' }}"
                    style="font-family:'Inter',sans-serif;">{{ $label }}</span>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Header --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-lg font-semibold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">Supplier Selection</h2>
            <p class="text-sm text-[#6f7b68] mt-0.5">Choose a verified supplier to begin the procurement process.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#6f7b68] text-sm">search</span>
                <input type="text" id="supplierSearch" placeholder="Search suppliers..."
                    class="pl-9 pr-4 py-2.5 border border-[#becab5] rounded-lg bg-white text-sm focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none w-64"
                    oninput="filterSuppliers(this.value)">
            </div>
            <a href="#" class="flex items-center gap-2 px-4 py-2.5 border border-[#becab5] rounded-lg text-[#016c00] text-sm font-semibold hover:bg-[#f3f4f5] transition-colors whitespace-nowrap">
                <span class="material-symbols-outlined text-sm">add</span> New Supplier
            </a>
        </div>
    </div>

    {{-- Supplier Grid --}}
    <form method="POST" action="{{ route('procurement.storeSupplier') }}">
        @csrf
        @error('supplier_id')
            <p class="text-red-600 text-sm mb-4">{{ $message }}</p>
        @enderror

        <div id="supplierGrid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            @forelse($suppliers as $supplier)
            <div class="supplier-card bg-white rounded-xl p-6 border border-[#becab5]/50 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] hover:shadow-[0px_10px_30px_rgba(0,0,0,0.08)] hover:-translate-y-0.5 transition-all duration-300 flex flex-col gap-4"
                data-name="{{ strtolower($supplier->name) }}">
                <div class="flex items-start justify-between">
                    <div class="flex gap-4">
                        <div class="w-12 h-12 rounded-lg bg-[#e7e8e9] border border-[#becab5] flex items-center justify-center shrink-0">
                            <span class="text-base font-bold text-[#6f7b68]" style="font-family:'Montserrat',sans-serif;">
                                {{ strtoupper(substr($supplier->name, 0, 2)) }}
                            </span>
                        </div>
                        <div>
                            <h3 class="font-semibold text-[#191c1d]" style="font-family:'Montserrat',sans-serif;">{{ $supplier->name }}</h3>
                            <div class="flex items-center gap-1 text-[#6f7b68] mt-1">
                                <span class="material-symbols-outlined text-[14px]">location_on</span>
                                <span class="text-xs">{{ $supplier->location ?? 'N/A' }}</span>
                            </div>
                        </div>
                    </div>
                    @if($supplier->rating > 0)
                    <div class="flex items-center gap-1 bg-orange-50 text-[#9d4300] px-2 py-1 rounded text-xs font-semibold">
                        <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1;">star</span>
                        {{ number_format($supplier->rating, 1) }}
                    </div>
                    @endif
                </div>

                <div class="h-px bg-[#becab5]/30"></div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider">Avg. Delivery</p>
                        <p class="text-sm font-semibold text-[#191c1d] mt-1" style="font-family:'Montserrat',sans-serif;">
                            {{ $supplier->avg_delivery_days ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-[#6f7b68] uppercase tracking-wider">Active Orders</p>
                        <p class="text-sm font-semibold text-[#191c1d] mt-1" style="font-family:'Montserrat',sans-serif;">
                            {{ $supplier->procurements()->whereIn('status', ['pending','approved'])->count() }}
                        </p>
                    </div>
                </div>

                <button type="submit" name="supplier_id" value="{{ $supplier->id }}"
                    class="w-full mt-auto bg-[#F97316] text-white text-sm font-bold py-2.5 rounded-lg hover:bg-orange-600 transition-colors"
                    style="font-family:'Montserrat',sans-serif;">
                    Select Supplier
                </button>
            </div>
            @empty
            <div class="col-span-3 text-center py-16 text-[#6f7b68]">
                <span class="material-symbols-outlined text-5xl mb-3 block">local_shipping</span>
                <p class="text-sm">No suppliers yet. Add your first supplier to get started.</p>
            </div>
            @endforelse
        </div>
    </form>

    <script>
        function filterSuppliers(query) {
            const cards = document.querySelectorAll('.supplier-card');
            cards.forEach(card => {
                card.style.display = card.dataset.name.includes(query.toLowerCase()) ? '' : 'none';
            });
        }
    </script>
</x-layouts.procurement>
