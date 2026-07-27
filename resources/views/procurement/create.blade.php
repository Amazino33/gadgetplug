<x-layouts.procurement title="New Procurement — Step 1">

    @if(session('success'))
    <div class="mb-4 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 text-green-800 dark:text-green-300 rounded-lg text-sm">
        {{ session('success') }}
    </div>
    @endif

    {{-- Stepper --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl p-6 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] border border-[#becab5]/30 dark:border-zinc-700 mb-6">
        <div class="flex items-center justify-between relative">
            <div class="absolute left-10 right-10 top-5 h-0.5 bg-[#e1e3e4] dark:bg-zinc-700 -z-10"></div>
            @foreach([['1','Supplier',true],['2','Items',false],['3','Financials',false],['4','Confirm',false]] as [$num,$label,$active])
            <div class="flex flex-col items-center gap-2 bg-white dark:bg-zinc-800 px-2">
                <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold shadow-sm
                    {{ $active ? 'bg-[#016c00] text-white' : 'bg-[#e7e8e9] dark:bg-zinc-700 text-[#6f7b68] dark:text-zinc-400 border border-[#becab5] dark:border-zinc-600' }}"
                    style="font-family:'Montserrat',sans-serif;">
                    {{ $num }}
                </div>
                <span class="text-xs font-semibold {{ $active ? 'text-[#016c00] dark:text-green-400' : 'text-[#6f7b68] dark:text-zinc-400' }}"
                    style="font-family:'Inter',sans-serif;">{{ $label }}</span>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Header --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-lg font-semibold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">Supplier Selection</h2>
            <p class="text-sm text-[#6f7b68] dark:text-zinc-400 mt-0.5">Choose a verified supplier to begin the procurement process.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#6f7b68] dark:text-zinc-400 text-sm">search</span>
                <input type="text" id="supplierSearch" placeholder="Search suppliers..."
                    class="pl-9 pr-4 py-2.5 border border-[#becab5] dark:border-zinc-600 rounded-lg bg-white dark:bg-zinc-800 text-sm dark:text-zinc-100 focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none w-64"
                    oninput="filterSuppliers(this.value)">
            </div>
            <a href="/plug/{{ $vendor->slug }}/suppliers" class="flex items-center gap-2 px-4 py-2.5 border border-[#becab5] dark:border-zinc-600 rounded-lg text-[#016c00] dark:text-green-400 text-sm font-semibold hover:bg-[#f3f4f5] dark:hover:bg-zinc-700 transition-colors whitespace-nowrap">
                <span class="material-symbols-outlined text-sm">add</span> New Supplier
            </a>
        </div>
    </div>

    <form method="POST" action="{{ route('procurement.storeSupplier') }}" enctype="multipart/form-data">
        @csrf

        {{-- Receipt Upload --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl p-6 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] border border-[#becab5]/30 dark:border-zinc-700 mb-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-lg bg-[#016c00]/10 dark:bg-green-900/30 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#016c00] dark:text-green-400">receipt_long</span>
                </div>
                <div>
                    <h3 class="font-semibold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">Receipt / Waybill Photo</h3>
                    <p class="text-xs text-[#6f7b68] dark:text-zinc-400">Snap or upload the purchase receipt for reference.</p>
                </div>
            </div>

            <label id="receiptDropzone"
                class="relative flex flex-col items-center justify-center w-full h-44 border-2 border-dashed rounded-xl cursor-pointer hover:border-[#016c00] hover:bg-[#f3f4f5] dark:hover:bg-zinc-700 transition-all group
                    {{ ($receiptImage ?? false) ? 'border-[#016c00] bg-[#f3f4f5] dark:bg-zinc-700' : 'border-[#becab5] dark:border-zinc-600' }}">
                <div id="receiptPlaceholder" class="flex flex-col items-center gap-2 text-[#6f7b68] dark:text-zinc-400 group-hover:text-[#016c00] dark:group-hover:text-green-400 transition-colors"
                    @if($receiptImage ?? false) style="display:none" @endif>
                    <span class="material-symbols-outlined text-4xl">photo_camera</span>
                    <span class="text-sm font-semibold">Tap to snap or upload receipt</span>
                    <span class="text-xs">JPG, PNG — max 5 MB</span>
                </div>
                <img id="receiptPreview"
                    src="{{ ($receiptImage ?? false) ? asset('storage/' . $receiptImage) : '' }}"
                    alt="Receipt preview"
                    class="absolute inset-0 w-full h-full object-contain rounded-xl p-2"
                    style="{{ ($receiptImage ?? false) ? '' : 'display:none' }}" />
                <input type="file" name="receipt_image" id="receiptInput" accept="image/*" capture="environment"
                    class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                    onchange="previewReceipt(this)" />
            </label>
            @error('receipt_image')
                <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
            @enderror
            <button type="button" id="receiptClear" onclick="clearReceipt()"
                style="{{ ($receiptImage ?? false) ? 'display:flex' : 'display:none' }}"
                class="mt-3 flex items-center gap-1 text-red-500 dark:text-red-400 text-xs font-semibold hover:text-red-700 dark:hover:text-red-300 transition-colors">
                <span class="material-symbols-outlined text-sm">close</span> Remove photo
            </button>
        </div>

        {{-- Supplier Grid --}}
        @error('supplier_id')
            <p class="text-red-600 dark:text-red-400 text-sm mb-4">{{ $message }}</p>
        @enderror

        <div id="supplierGrid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            @forelse($suppliers as $supplier)
            <div class="supplier-card rounded-xl p-6 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] hover:shadow-[0px_10px_30px_rgba(0,0,0,0.08)] hover:-translate-y-0.5 transition-all duration-300 flex flex-col gap-4
                {{ ($selectedSupplier ?? null) == $supplier->id ? 'bg-green-50 dark:bg-green-900/20 border-2 border-[#016c00]' : 'bg-white dark:bg-zinc-800 border border-[#becab5]/50 dark:border-zinc-700' }}"
                data-name="{{ strtolower($supplier->name) }}">
                <div class="flex items-start justify-between">
                    <div class="flex gap-4">
                        <div class="w-12 h-12 rounded-lg bg-[#e7e8e9] dark:bg-zinc-700 border border-[#becab5] dark:border-zinc-600 flex items-center justify-center shrink-0">
                            <span class="text-base font-bold text-[#6f7b68] dark:text-zinc-400" style="font-family:'Montserrat',sans-serif;">
                                {{ strtoupper(substr($supplier->name, 0, 2)) }}
                            </span>
                        </div>
                        <div>
                            <h3 class="font-semibold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">{{ $supplier->name }}</h3>
                            <div class="flex items-center gap-1 text-[#6f7b68] dark:text-zinc-400 mt-1">
                                <span class="material-symbols-outlined text-[14px]">location_on</span>
                                <span class="text-xs">{{ $supplier->location ?? 'N/A' }}</span>
                            </div>
                        </div>
                    </div>
                    @if($supplier->rating > 0)
                    <div class="flex items-center gap-1 bg-orange-50 dark:bg-orange-900/20 text-[#9d4300] dark:text-orange-400 px-2 py-1 rounded text-xs font-semibold">
                        <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1;">star</span>
                        {{ number_format($supplier->rating, 1) }}
                    </div>
                    @endif
                </div>

                <div class="h-px bg-[#becab5]/30 dark:bg-zinc-700"></div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider">Avg. Delivery</p>
                        <p class="text-sm font-semibold text-[#191c1d] dark:text-zinc-100 mt-1" style="font-family:'Montserrat',sans-serif;">
                            {{ $supplier->avg_delivery_days ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider">Active Orders</p>
                        <p class="text-sm font-semibold text-[#191c1d] dark:text-zinc-100 mt-1" style="font-family:'Montserrat',sans-serif;">
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
            <div class="col-span-3 text-center py-16 text-[#6f7b68] dark:text-zinc-400">
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

        function previewReceipt(input) {
            const file = input.files[0];
            if (!file) return;

            if (file.size > 5 * 1024 * 1024) {
                alert('Image must be under 5 MB.');
                input.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (e) {
                document.getElementById('receiptPreview').src = e.target.result;
                document.getElementById('receiptPreview').style.display = '';
                document.getElementById('receiptPlaceholder').style.display = 'none';
                document.getElementById('receiptClear').style.display = 'flex';
                document.getElementById('receiptDropzone').classList.add('border-[#016c00]', 'bg-[#f3f4f5]');
            };
            reader.readAsDataURL(file);
        }

        function clearReceipt() {
            const input = document.getElementById('receiptInput');
            input.value = '';
            document.getElementById('receiptPreview').style.display = 'none';
            document.getElementById('receiptPreview').src = '';
            document.getElementById('receiptPlaceholder').style.display = '';
            document.getElementById('receiptClear').style.display = 'none';
            document.getElementById('receiptDropzone').classList.remove('border-[#016c00]', 'bg-[#f3f4f5]');
        }
    </script>
</x-layouts.procurement>
