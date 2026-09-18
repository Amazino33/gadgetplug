<x-layouts.procurement title="New Procurement — Step 2">

    {{-- Stepper --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl p-6 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] border border-[#becab5]/30 dark:border-zinc-700 mb-6">
        <div class="flex justify-between items-start mb-4">
            <div>
                <h2 class="text-xl font-bold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">
                    Purchase Order
                </h2>
                <p class="text-sm text-[#6f7b68] dark:text-zinc-400 mt-0.5">
                    Supplier: {{ $supplier->name }}
                    <span class="mx-1">·</span>
                    Delivering to <span class="font-semibold text-[#016c00] dark:text-green-400">{{ $destination->name }}</span>
                </p>
            </div>
            <div class="text-right">
                <p class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider">Total Estimated Value</p>
                <p class="text-2xl font-bold text-[#016c00] dark:text-green-400" style="font-family:'Montserrat',sans-serif;" id="grandTotal">₦ 0.00</p>
            </div>
        </div>
        <div class="flex items-center justify-between relative">
            <div class="absolute left-4 right-4 top-4 h-0.5 bg-[#e1e3e4] dark:bg-zinc-700 -z-10"></div>
            <div class="absolute left-4 w-1/5 top-4 h-0.5 bg-[#016c00] -z-10"></div>
            @foreach([['1','Supplier','completed'],['2','Items','active'],['3','Logistics','pending'],['4','Financials','pending'],['5','Confirm','pending']] as [$num,$label,$state])
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

    <form method="POST" action="{{ route('procurement.storeItems') }}" id="itemsForm" onsubmit="return validateItemsForm()">
        @csrf

        @error('items')
            <p class="text-red-600 dark:text-red-400 text-sm mb-4">{{ $message }}</p>
        @enderror

        <p id="productFormError" class="hidden text-red-600 dark:text-red-400 text-sm mb-4">
            Pick a product from the search results for every row — start typing in the Product field to see matches.
        </p>

        {{-- Shown only when this page rebuilt itself from a draft, so the
             person knows why there is already work on screen. --}}
        <div id="draftRestored" class="hidden flex items-start gap-2 bg-brand-orange/10 border border-brand-orange/40 rounded-lg px-4 py-3 mb-4">
            <span class="material-symbols-outlined text-brand-orange text-[18px]">history</span>
            <p class="text-sm text-[#191c1d] dark:text-zinc-100">
                We brought back what you had typed. Nothing is submitted yet — it stays a draft until you continue.
            </p>
        </div>

        {{-- Items Header --}}
        <div class="flex justify-between items-center mb-4">
            <div class="flex items-center gap-2">
                <h3 class="text-base font-semibold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;">Items Added</h3>
                <span class="bg-[#e7e8e9] dark:bg-zinc-700 text-[#191c1d] dark:text-zinc-100 px-2 py-0.5 rounded-full text-xs font-bold" id="itemCount">0</span>
                <span id="draftStatus" class="text-xs text-[#6f7b68] dark:text-zinc-400 opacity-0 transition-opacity"></span>
            </div>
            <div class="flex gap-2">
                <button type="button" onclick="addItem()" data-tour="procurement-add-item"
                    class="flex items-center gap-1.5 px-4 py-2 border border-[#becab5] dark:border-zinc-600 rounded-lg text-[#016c00] dark:text-green-400 text-sm font-semibold hover:bg-[#f3f4f5] dark:hover:bg-zinc-700 transition-colors">
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

        {{-- Add row.
             In the action orange rather than the muted grey it used to wear:
             adding the next line is the thing a person does over and over on
             this step, and it was the quietest thing on the page — a dashed
             grey box that read as an empty placeholder rather than a control.

             type="button" is load-bearing. This sits inside #itemsForm, and a
             button with no type submits — so without it, "add a row" would post
             the whole procurement step instead. --}}
        <button type="button" onclick="addItem()"
            class="w-full bg-brand-orange/5 dark:bg-brand-orange/10 rounded-xl border-2 border-dashed border-brand-orange flex items-center justify-center h-20 hover:bg-brand-orange focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-orange focus-visible:ring-offset-2 transition-colors cursor-pointer group mb-6">
            <span class="flex items-center gap-2 text-brand-orange group-hover:text-white transition-colors">
                <span class="material-symbols-outlined">add_box</span>
                <span class="text-sm font-semibold">Add more items</span>
            </span>
        </button>

        {{-- Bottom Bar --}}
        <div class="sticky bottom-0 bg-white dark:bg-zinc-800 border-t border-[#e1e3e4] dark:border-zinc-700 flex justify-between items-center px-6 py-4 -mx-6 shadow-[0px_-4px_20px_rgba(0,0,0,0.04)]">
            <a href="{{ route('procurement.create') }}"
                class="flex items-center gap-2 px-6 py-2.5 border border-[#becab5] dark:border-zinc-600 rounded-lg text-[#6f7b68] dark:text-zinc-400 text-sm font-semibold hover:bg-[#f3f4f5] dark:hover:bg-zinc-700 transition-colors">
                <span class="material-symbols-outlined text-sm">arrow_back</span> Back
            </a>
            <div class="flex items-center gap-6">
                <div class="text-right hidden md:block">
                    <p class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider">Subtotal</p>
                    <p class="text-base font-bold text-[#191c1d] dark:text-zinc-100" style="font-family:'Montserrat',sans-serif;" id="subtotalDisplay">₦ 0.00</p>
                </div>
                <button type="submit" data-tour="procurement-items-continue"
                    class="flex items-center gap-2 px-6 py-2.5 bg-[#016c00] text-white text-sm font-bold rounded-lg hover:bg-green-800 transition-colors"
                    style="font-family:'Montserrat',sans-serif;">
                    Next: Transport Cost <span class="material-symbols-outlined text-sm">arrow_forward</span>
                </button>
            </div>
        </div>
    </form>

    {{-- Product data for JS --}}
    <script>
        const products = @json($productsJson);
        const savedItems = @json(session('procurement.items', []));
        const isDark = document.documentElement.classList.contains('dark');
        let itemIndex = 0;

        function addItem(prefill = null) {
            const list = document.getElementById('itemsList');
            const idx = itemIndex++;
            const prefillProduct = prefill ? products.find(p => p.id == prefill.product_id) : null;

            const html = `
            <div class="item-row bg-white dark:bg-zinc-800 rounded-xl p-4 border border-[#becab5]/50 dark:border-zinc-700 shadow-[0px_4px_20px_rgba(0,0,0,0.04)] flex flex-col lg:flex-row gap-4 lg:items-end" id="row_${idx}">

                <div class="flex-1 min-w-[180px] relative">
                    <label class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider block mb-1">Product</label>
                    <input type="text" id="productSearch_${idx}" placeholder="Type to search product…" autocomplete="off"
                        value="${escapeHtml(prefillProduct?.name ?? prefill?.product_query ?? '')}"
                        oninput="onProductSearchInput(${idx})"
                        onfocus="onProductSearchFocus(${idx})"
                        onblur="onProductSearchBlur(${idx})"
                        class="product-search w-full px-3 py-2 border border-[#becab5] dark:border-zinc-600 rounded-lg text-sm focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none bg-white dark:bg-zinc-900 dark:text-zinc-100">
                    <input type="hidden" name="items[${idx}][product_id]" id="productId_${idx}" value="${prefillProduct?.id ?? ''}">
                    <div id="productResults_${idx}" class="hidden absolute z-20 top-full left-0 right-0 mt-1 bg-white dark:bg-zinc-900 border border-[#becab5] dark:border-zinc-600 rounded-lg shadow-lg max-h-56 overflow-y-auto"></div>
                </div>

                {{-- Barcode and quantity are one line, and so are cost and
                     selling price below them. Stacked, a single item ran five
                     labels deep and you could not see the cost and the price
                     you were setting against it at the same time.

                     lg:contents dissolves these pairing wrappers at desktop
                     width, so their children become direct flex items of the
                     row again and the wide layout is exactly what it was. --}}
                <div class="flex gap-3 lg:contents">

                <div class="flex-1 min-w-0 lg:w-44 lg:flex-none">
                    <label class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider block mb-1">IMEI / Serial</label>
                    <div class="relative">
                        <input type="text" name="items[${idx}][barcode]" placeholder="Scan or type..."
                            value="${prefill?.barcode || ''}"
                            class="w-full px-3 py-2 pr-8 border border-[#becab5] dark:border-zinc-600 rounded-lg text-sm focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none bg-white dark:bg-zinc-900 dark:text-zinc-100">
                        <span class="material-symbols-outlined absolute right-2 top-2 text-[#6f7b68] dark:text-zinc-400 text-[16px] cursor-pointer hover:text-[#016c00]">qr_code_scanner</span>
                    </div>
                </div>

                <div class="w-[124px] shrink-0 lg:w-32">
                    <label class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider block mb-1">Qty</label>
                    <div class="flex items-center border border-[#becab5] dark:border-zinc-600 rounded-lg overflow-hidden h-9">
                        <button type="button" onclick="changeQty(${idx}, -1)" class="px-2 text-[#6f7b68] dark:text-zinc-400 hover:bg-[#e7e8e9] dark:hover:bg-zinc-700 h-full transition-colors">
                            <span class="material-symbols-outlined text-[16px]">remove</span>
                        </button>
                        <input type="number" name="items[${idx}][quantity]" value="${prefill?.quantity || 1}" min="1"
                            id="qty_${idx}" onchange="recalculate()"
                            class="w-12 text-center border-none focus:ring-0 text-sm font-bold bg-transparent dark:text-zinc-100 p-0 h-full">
                        <button type="button" onclick="changeQty(${idx}, 1)" class="px-2 text-[#6f7b68] dark:text-zinc-400 hover:bg-[#e7e8e9] dark:hover:bg-zinc-700 h-full transition-colors">
                            <span class="material-symbols-outlined text-[16px]">add</span>
                        </button>
                    </div>
                </div>

                </div>

                <div class="flex gap-3 lg:contents">

                <div class="flex-1 min-w-0 lg:w-36 lg:flex-none">
                    <label class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider block mb-1">Unit Cost</label>
                    <div class="relative">
                        <span class="absolute left-2 top-2 text-[#6f7b68] dark:text-zinc-400 text-sm font-bold">₦</span>
                        <input type="number" name="items[${idx}][unit_cost]" placeholder="0.00" min="0" step="0.01"
                            value="${prefill?.unit_cost || ''}"
                            id="cost_${idx}" onchange="recalculate()"
                            class="w-full pl-6 pr-3 py-2 border border-[#becab5] dark:border-zinc-600 rounded-lg text-sm font-bold focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none bg-white dark:bg-zinc-900 dark:text-zinc-100">
                    </div>
                </div>

                <div class="flex-1 min-w-0 lg:w-36 lg:flex-none">
                    <label class="text-[10px] font-bold text-[#6f7b68] dark:text-zinc-500 uppercase tracking-wider block mb-1">Selling Price</label>
                    <div class="relative">
                        <span class="absolute left-2 top-2 text-[#6f7b68] dark:text-zinc-400 text-sm font-bold">₦</span>
                        <input type="number" name="items[${idx}][selling_price]" placeholder="0.00" min="0" step="0.01"
                            value="${prefill?.selling_price || ''}"
                            id="price_${idx}"
                            class="w-full pl-6 pr-3 py-2 border border-[#becab5] dark:border-zinc-600 rounded-lg text-sm font-bold text-[#016c00] dark:text-green-400 focus:border-[#016c00] focus:ring-2 focus:ring-[#016c00]/20 outline-none bg-white dark:bg-zinc-900">
                    </div>
                </div>

                </div>

                <button type="button" onclick="removeItem(${idx})"
                    class="shrink-0 p-2 text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors mb-0.5">
                    <span class="material-symbols-outlined text-[18px]">delete</span>
                </button>
            </div>`;

            list.insertAdjacentHTML('beforeend', html);
            updateCount();
            saveDraft();
        }

        function removeItem(idx) {
            document.getElementById(`row_${idx}`)?.remove();
            updateCount();
            recalculate();
            saveDraft();
        }

        // ── Draft ───────────────────────────────────────────────────────────
        //
        // Everything on this step used to live only in the DOM until the whole
        // form was posted. A reload, a dropped connection, a phone killing the
        // tab to reclaim memory — any of those and a purchase order typed line
        // by line was simply gone, with nothing to show the person who typed
        // it. Now every keystroke lands in localStorage and the step rebuilds
        // itself from there.
        //
        // localStorage rather than a debounced POST to the session: this is
        // used on a phone in a shop, and a draft that needs the network to
        // survive is a draft that disappears exactly when the connection is
        // worst. It also costs the server nothing.
        //
        // Stamped with the supplier and branch it was typed against, so a
        // draft can never reappear inside a different procurement. Cleared for
        // good when the procurement is finally submitted (see confirm.blade).
        const DRAFT_KEY = 'gp.procurement.items-draft';
        const DRAFT_STAMP = { supplier: {{ (int) $supplier->id }}, store: {{ (int) $destination->id }} };
        const DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000;

        let draftTimer = null;

        function collectItems() {
            return [...document.querySelectorAll('.item-row')].map(row => ({
                product_id:    row.querySelector('input[name*="[product_id]"]')?.value || '',
                // What they typed but have not picked from the list yet. Kept
                // so a half-finished row survives too — losing it would mean
                // the draft only protects work that was already complete.
                product_query: row.querySelector('.product-search')?.value || '',
                barcode:       row.querySelector('[name*="[barcode]"]')?.value || '',
                quantity:      row.querySelector('[name*="[quantity]"]')?.value || 1,
                unit_cost:     row.querySelector('[name*="[unit_cost]"]')?.value || '',
                selling_price: row.querySelector('[name*="[selling_price]"]')?.value || '',
            }));
        }

        function hasContent(items) {
            return items.some(i => i.product_id || i.product_query || i.barcode || i.unit_cost || i.selling_price);
        }

        function writeDraft() {
            try {
                const items = collectItems();

                // An untouched empty row is not work worth restoring, and
                // storing it would resurrect a blank form over a real one.
                if (! hasContent(items)) {
                    localStorage.removeItem(DRAFT_KEY);
                    showDraftSaved(false);
                    return;
                }

                localStorage.setItem(DRAFT_KEY, JSON.stringify({
                    ...DRAFT_STAMP,
                    items,
                    savedAt: Date.now(),
                }));
                showDraftSaved(true);
            } catch (e) {
                // Private browsing, or storage full. The form still works —
                // it just stops being recoverable, which is where it started.
            }
        }

        // Debounced, for the letters arriving one after another while someone
        // is still typing a word.
        function saveDraft() {
            clearTimeout(draftTimer);
            draftTimer = setTimeout(writeDraft, 250);
        }

        // Not debounced. For every moment where the next thing that happens
        // might be the page going away — a field losing focus, a tab being
        // backgrounded, a navigation — the pending keystroke has to already be
        // written, because there is no later.
        function flushDraft() {
            clearTimeout(draftTimer);
            writeDraft();
        }

        function readDraft() {
            try {
                const raw = localStorage.getItem(DRAFT_KEY);
                if (! raw) return null;

                const draft = JSON.parse(raw);

                // Belongs to a different supplier or branch, or is old enough
                // that restoring it would be a surprise rather than a rescue.
                if (draft.supplier !== DRAFT_STAMP.supplier) return null;
                if (draft.store !== DRAFT_STAMP.store) return null;
                if (! draft.savedAt || Date.now() - draft.savedAt > DRAFT_MAX_AGE_MS) return null;

                return Array.isArray(draft.items) && draft.items.length ? draft.items : null;
            } catch (e) {
                return null;
            }
        }

        function clearDraft() {
            try { localStorage.removeItem(DRAFT_KEY); } catch (e) {}
        }

        function showDraftSaved(saved) {
            const el = document.getElementById('draftStatus');
            if (! el) return;

            el.textContent = saved ? 'Draft saved' : '';
            el.classList.toggle('opacity-0', ! saved);
        }

        function changeQty(idx, delta) {
            const input = document.getElementById(`qty_${idx}`);
            input.value = Math.max(1, parseInt(input.value || 1) + delta);
            recalculate();
            saveDraft();
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str ?? '';
            return div.innerHTML;
        }

        function renderProductResults(idx, query) {
            const box = document.getElementById(`productResults_${idx}`);
            const q = query.trim().toLowerCase();
            const matches = (q ? products.filter(p => p.name.toLowerCase().includes(q)) : products).slice(0, 20);

            box.innerHTML = matches.length
                ? matches.map(p => p.receivable
                    ? `
                    <button type="button" onmousedown="selectProduct(${idx}, ${p.id})"
                        class="w-full text-left px-3 py-2 text-sm hover:bg-[#f3f4f5] dark:hover:bg-zinc-700 text-[#191c1d] dark:text-zinc-100 border-b border-gray-50 dark:border-zinc-800 last:border-0">
                        ${escapeHtml(p.name)}
                    </button>`
                    : `
                    <div class="w-full text-left px-3 py-2 text-sm text-[#6f7b68] dark:text-zinc-500 border-b border-gray-50 dark:border-zinc-800 last:border-0 cursor-not-allowed">
                        <span class="line-through">${escapeHtml(p.name)}</span>
                        <span class="block text-xs mt-0.5">Stocked at ${escapeHtml(p.held_at || 'another branch')} — needs its own product here</span>
                    </div>`).join('')
                : `<div class="px-3 py-2 text-sm text-[#6f7b68] dark:text-zinc-400">No matching products</div>`;

            box.classList.remove('hidden');
        }

        function onProductSearchInput(idx) {
            document.getElementById(`productId_${idx}`).value = '';
            renderProductResults(idx, document.getElementById(`productSearch_${idx}`).value);
        }

        function onProductSearchFocus(idx) {
            renderProductResults(idx, document.getElementById(`productSearch_${idx}`).value);
        }

        function onProductSearchBlur(idx) {
            // Deferred so the result button's onmousedown still fires first.
            setTimeout(() => document.getElementById(`productResults_${idx}`)?.classList.add('hidden'), 150);
        }

        function selectProduct(idx, productId) {
            const product = products.find(p => p.id == productId);
            if (!product || !product.receivable) return;

            document.getElementById(`productId_${idx}`).value = product.id;
            document.getElementById(`productSearch_${idx}`).value = product.name;
            document.getElementById(`productSearch_${idx}`).classList.remove('border-red-400');
            document.getElementById(`productResults_${idx}`).classList.add('hidden');

            const priceInput = document.getElementById(`price_${idx}`);
            if (priceInput && !priceInput.value) priceInput.value = product.price;

            recalculate();
            saveDraft();
        }

        function validateItemsForm() {
            const rows = document.querySelectorAll('.item-row');
            for (const row of rows) {
                const hidden = row.querySelector('input[name*="[product_id]"]');
                if (!hidden || !hidden.value) {
                    const search = row.querySelector('.product-search');
                    search?.classList.add('border-red-400');
                    search?.focus();
                    document.getElementById('productFormError').classList.remove('hidden');
                    return false;
                }
            }
            document.getElementById('productFormError').classList.add('hidden');
            return true;
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

        // One listener for the whole list rather than handlers on every field:
        // rows are built as HTML strings and inserted, so anything bound per
        // field would have to be re-bound on each insert.
        const itemsList = document.getElementById('itemsList');

        itemsList.addEventListener('input', saveDraft);

        // change fires the moment a field loses focus — which is precisely
        // "clicking anywhere else". Flushed rather than debounced: a click that
        // leaves a field is very often a click that leaves the page, and a
        // quarter of a second is long enough to lose the number just typed.
        itemsList.addEventListener('change', flushDraft);

        // The page going away for any other reason: a link, the back button, a
        // phone backgrounding the tab and the browser reclaiming it. pagehide
        // and visibilitychange are the pair that actually fire on mobile, where
        // beforeunload is unreliable and often ignored outright.
        window.addEventListener('pagehide', flushDraft);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') flushDraft();
        });

        // Continuing to the next step: the session takes over from here, but
        // the draft has to match what was sent in case they come back.
        document.getElementById('itemsForm')?.addEventListener('submit', flushDraft);

        // A draft beats the session copy: the session holds what was last
        // submitted from this step, the draft holds what has been typed since.
        const draftItems = readDraft();
        const restore = draftItems ?? savedItems;

        if (restore.length > 0) {
            restore.forEach(item => addItem(item));
            recalculate();

            if (draftItems) {
                showDraftSaved(true);
                document.getElementById('draftRestored')?.classList.remove('hidden');
            }
        } else {
            addItem();
        }
    </script>
</x-layouts.procurement>
