/**
 * The product pop-up on the Products list.
 *
 * The Filament product form asks the server something on nearly every field:
 * the margin preview, the SKU preview, each "Add barcode" row. On a shop's
 * mobile data each of those is a visible wait. Here the whole form, Advanced
 * settings included, lives in the browser: it opens with no request, every
 * row is added on the spot, and the server is asked once, on Save
 * (ListProducts::saveQuickProduct, which checks it the way ProductForm does).
 *
 * Two lookups still go to the server, in the background and never in the way:
 * the SKU preview and a check that a barcode is not already taken.
 *
 * Loaded from <head> on the Products list only, so the alpine:init listener is
 * registered before Livewire starts Alpine.
 */

const TABS = {
    units: ['base_unit', 'wholesale_price', 'half_price', 'default_unit_choice', 'extraUnits'],
    barcodes: ['barcode', 'extraBaseBarcodes'],
    photos: ['photos'],
    details: ['brand', 'search_keywords', 'supplier_id', 'new_supplier', 'description', 'tags', 'new_tags', 'specifications'],
    stock: ['low_stock_threshold', 'reorder_point', 'preferred_quantity', 'negative_stock_override', 'is_service', 'track_serial'],
    pricing: ['has_custom_price', 'allow_pos_price_override'],
    channels: ['show_in_pos', 'show_online', 'published_at', 'unpublish_at'],
};

const tabFor = (key) => Object.keys(TABS).find((tab) => TABS[tab].includes(key.split('.')[0])) ?? null;

const money = (n) => '₦' + Number(n).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const num = (v) => (v === '' || v === null || v === undefined || Number.isNaN(Number(v)) ? null : Number(v));

let seq = 0;
const key = () => `k${++seq}`;

// Phone photos are 4–8 MB; a product photo needs nowhere near that. Shrunk in
// the browser before upload, which is most of the wait on mobile data.
const MAX_EDGE = 1800;

async function shrink(file) {
    if (!file.type.startsWith('image/') || file.type === 'image/gif' || file.size < 600 * 1024) return file;

    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
        if (scale === 1 && file.size < 2 * 1024 * 1024) return file;

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, 0.85));
        if (!blob || blob.size >= file.size) return file;

        const name = file.name.replace(/\.[^.]+$/, '') + (type === 'image/png' ? '.png' : '.jpg');
        return new File([blob], name, { type });
    } catch {
        return file;
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('productQuickForm', (opts) => {
    // Picked photos, kept out of Alpine's reactive state: a proxied File
    // cannot be put in a FormData, so it would never upload.
    const files = [];

    return {
        opts,
        open: false,
        mode: 'create', // or 'edit'
        id: null,
        loading: false,
        saving: false,
        savingLabel: '',
        advanced: false,
        tab: 'units',
        f: {},
        snapshot: '',
        errors: {},
        warnings: {}, // barcode field key -> "Already used by …", from the background check
        newCount: 0, // photos picked since opening (the Files are in `files`)
        uploadedSig: '',
        editUrl: '',
        linked: false,
        currentStatus: null,
        loadError: '',
        tagInput: '',
        scanTarget: null,
        cache: new Map(), // id -> { at, product }, from hovering a pencil
        inflight: new Map(),
        skuTimer: null,

        init() {
            this.f = this.blank();
            this.snapshot = JSON.stringify(this.f);

            try { this.advanced = localStorage.getItem('gp-product-advanced') === '1'; } catch { /* private window */ }
            this.$watch('advanced', (v) => { try { localStorage.setItem('gp-product-advanced', v ? '1' : '0'); } catch { /* ignore */ } });

            window.addEventListener('product-form-open', () => this.openCreate());

            // The list re-renders after every save, so the pencils are found by
            // delegation rather than wired one by one.
            document.addEventListener('click', (e) => {
                const link = e.target.closest('[data-quick-edit]');
                if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
                e.preventDefault();
                this.openEdit(Number(link.dataset.quickEdit), link.dataset.quickName ?? '');
            });

            // Hovering a pencil fetches that product, so the click opens it filled.
            let hoverTimer = null;
            document.addEventListener('pointerover', (e) => {
                const link = e.target.closest('[data-quick-edit]');
                clearTimeout(hoverTimer);
                if (link) hoverTimer = setTimeout(() => this.prefetch(Number(link.dataset.quickEdit)), 120);
            });

            window.addEventListener('barcode-scanned', (e) => {
                if (!this.open || !this.scanTarget) return;
                this.putScanned(this.scanTarget, String(e.detail?.barcode ?? '').trim());
                this.scanTarget = null;
            });

            const params = new URLSearchParams(window.location.search);
            if (params.get('new') === '1') this.$nextTick(() => this.openCreate());
        },

        // ── Opening ────────────────────────────────────────────────────
        blank() {
            const o = this.opts;
            return {
                name: '', sku: '', sku_manual: false, category_id: '',
                store_id: o.defaultStoreId ? String(o.defaultStoreId) : '',
                cost_price: '', price: '', status: o.defaultStatus, shop_sold_out: false,
                base_unit: 'Piece', wholesale_price: '', half_price: '', default_unit_choice: 'base',
                extraUnits: [], barcode: '', extraBaseBarcodes: [], photos: [],
                brand: '', search_keywords: '', learned_words: [], forget_words: [],
                supplier_id: '', new_supplier: null, description: '', tags: [], new_tags: [], specifications: [],
                low_stock_threshold: '5', reorder_point: '', preferred_quantity: '', negative_stock_override: '',
                is_service: false, track_serial: false, has_custom_price: false, allow_pos_price_override: true,
                show_in_pos: true, show_online: true, published_at: '', unpublish_at: '',
            };
        },

        withKeys(product) {
            const f = { ...this.blank(), ...product };
            f.extraUnits = (f.extraUnits ?? []).map((u) => ({ ...u, _key: key(), barcodes: [...(u.barcodes ?? [])] }));
            f.extraBaseBarcodes = [...(f.extraBaseBarcodes ?? [])];
            f.photos = (f.photos ?? []).map((p) => ({ ...p, _key: key() }));
            f.specifications = (f.specifications ?? []).map((s) => ({ ...s, _key: key() }));
            return f;
        },

        reset(f) {
            this.f = f;
            this.errors = {};
            this.warnings = {};
            files.forEach((file) => URL.revokeObjectURL(file.previewUrl));
            files.length = 0;
            this.newCount = 0;
            this.uploadedSig = '';
            this.tagInput = '';
            this.loadError = '';
            this.snapshot = JSON.stringify(this.f);
        },

        openCreate(keep = null) {
            if (this.opts.limitReached) {
                this.mode = 'limit';
                this.open = true;
                return;
            }
            this.mode = 'create';
            this.id = null;
            this.linked = false;
            this.currentStatus = null;
            this.editUrl = '';
            this.loading = false;
            this.reset({ ...this.blank(), ...(keep ?? {}) });
            this.open = true;
            this.focusName();

            // The "Add a product" tour's next chapter belongs to the create
            // form, which now opens here rather than on its own page.
            setTimeout(() => window.gpTours?.resumeAt?.(window.location.pathname.replace(/\/$/, '') + '/create'), 250);
        },

        async openEdit(id, name) {
            this.mode = 'edit';
            this.id = id;
            this.open = true;

            const cached = this.cache.get(id);
            if (cached && Date.now() - cached.at < 60_000) {
                this.fill(cached.product);
                return;
            }

            this.reset({ ...this.blank(), name });
            this.loading = true;
            const result = await this.fetchProduct(id);
            if (this.id !== id || !this.open) return;
            this.loading = false;

            if (result?.ok) this.fill(result.product);
            else this.loadError = result?.message ?? 'This product could not be opened.';
        },

        fill(product) {
            this.linked = !!product.linked;
            this.currentStatus = product.current_status;
            this.editUrl = product.edit_url;
            this.loading = false;
            this.reset(this.withKeys(product));
            this.focusName();
        },

        fetchProduct(id) {
            if (this.inflight.has(id)) return this.inflight.get(id);
            const p = this.$wire.loadQuickProduct(id)
                .then((result) => {
                    if (result?.ok) this.cache.set(id, { at: Date.now(), product: result.product });
                    return result;
                })
                .catch(() => ({ ok: false, message: 'Could not reach the server. Check the connection and try again.' }))
                .finally(() => this.inflight.delete(id));
            this.inflight.set(id, p);
            return p;
        },

        prefetch(id) {
            const cached = this.cache.get(id);
            if (!this.open && !(cached && Date.now() - cached.at < 60_000)) this.fetchProduct(id);
        },

        focusName() {
            this.$nextTick(() => setTimeout(() => this.$refs.name?.focus(), 50));
        },

        get dirty() {
            return this.mode !== 'limit' && (JSON.stringify(this.f) !== this.snapshot || this.newCount > 0);
        },

        close(force = false) {
            if (this.saving) return;
            if (!force && this.dirty && !window.confirm('Close without saving? What you typed will be lost.')) return;
            this.open = false;
            this.scanTarget = null;
        },

        // ── Figures worked out as you type ─────────────────────────────
        get unitWord() {
            return (this.f.base_unit || 'piece').trim().toLowerCase();
        },

        get chips() {
            const price = num(this.f.price);
            const cost = num(this.f.cost_price);
            if (!price || price <= 0) return { note: 'Enter a selling price to see profit.' };
            if (cost === null) return { note: 'Add a cost price to see profit and margin.' };

            const units = [{ name: this.f.base_unit || 'Piece', price, factor: 1 }];
            for (const u of this.f.extraUnits) {
                if (u.name && num(u.price) !== null) units.push({ name: u.name, price: num(u.price), factor: Math.max(1, parseInt(u.conversion_factor, 10) || 1) });
            }

            return {
                list: units.map((u) => {
                    const profit = u.price - cost * u.factor;
                    const margin = u.price > 0 ? (profit / u.price) * 100 : 0;
                    return { name: u.name, loss: profit < 0, profit: (profit < 0 ? '−' : '') + money(Math.abs(profit)), margin: margin.toFixed(1) + '%' };
                }),
            };
        },

        perSingle(u) {
            const single = num(this.f.price);
            const price = num(u.price);
            const factor = parseInt(u.conversion_factor, 10);
            if (!single || !price || !factor || factor < 1) return { text: '—', tone: 'muted' };

            const each = price / factor;
            const diff = Math.round(((single - each) / single) * 1000) / 10;
            if (diff < 0) return { text: `${money(each)} · ${Math.abs(diff)}% dearer than single!`, tone: 'bad' };
            return { text: money(each) + (diff > 0 ? ` · saves ${diff}%` : ''), tone: diff > 0 ? 'good' : 'muted' };
        },

        halfPlaceholder(price) {
            const p = num(price);
            return p && p > 0 ? 'Auto: ' + money(Math.round((p / 2) * 100) / 100) : 'Auto: half';
        },

        get unitChoices() {
            return [
                { value: 'base', label: `${this.f.base_unit || 'Piece'} (single)` },
                ...this.f.extraUnits.filter((u) => u.name).map((u) => ({ value: 'unit:' + u.name, label: u.name })),
            ];
        },

        get summary() {
            const units = 1 + this.f.extraUnits.filter((u) => u.name).length;
            const choice = this.f.default_unit_choice;
            const sells = choice?.startsWith('unit:') ? choice.slice(5) : (this.f.base_unit || 'Piece');
            const codes = (this.f.barcode ? 1 : 0)
                + this.f.extraBaseBarcodes.filter(Boolean).length
                + this.f.extraUnits.reduce((n, u) => n + u.barcodes.filter(Boolean).length, 0);
            return `${units} unit${units === 1 ? '' : 's'} · POS sells as ${sells} · ${codes} barcode${codes === 1 ? '' : 's'} · ${this.f.photos.length} photo${this.f.photos.length === 1 ? '' : 's'}`;
        },

        get priceLocked() {
            return this.linked && !this.f.has_custom_price;
        },

        // ── SKU preview (background) ───────────────────────────────────
        get skuLocked() {
            return !this.opts.skuManual && !this.f.sku_manual;
        },

        refreshSku(force = false) {
            if (this.opts.skuManual || this.f.sku_manual) return;
            if (this.mode === 'edit' && !force) return;
            clearTimeout(this.skuTimer);
            this.skuTimer = setTimeout(async () => {
                if (!this.f.name) { this.f.sku = ''; return; }
                const wanted = `${this.f.name}|${this.f.category_id}`;
                try {
                    const sku = await this.$wire.previewQuickSku(this.f.name, this.f.category_id || null, this.mode === 'edit' ? this.id : null);
                    if (`${this.f.name}|${this.f.category_id}` === wanted && this.skuLocked) {
                        this.f.sku = sku ?? '';
                        // A preview is not an edit: it must not make Close ask.
                        if (this.mode === 'create') {
                            const snap = JSON.parse(this.snapshot);
                            snap.sku = this.f.sku;
                            this.snapshot = JSON.stringify(snap);
                        }
                    }
                } catch { /* the real one is made on save anyway */ }
            }, force ? 0 : 150);
        },

        // ── Units and barcodes, all in the browser ─────────────────────
        addUnit(preset = false) {
            const price = num(this.f.price);
            this.f.extraUnits.push({
                _key: key(), id: null, sku: '',
                name: preset ? 'Crate' : '',
                conversion_factor: preset ? '12' : '',
                price: preset && price ? String(Math.round((price * 12 * 0.92) / 50) * 50) : '',
                wholesale_price: '', half_price: '', is_active: true, barcodes: [],
            });
            this.advanced = true;
            this.tab = 'units';
            const i = this.f.extraUnits.length - 1;
            this.$nextTick(() => document.getElementById(`pq-unit-${i}-name`)?.focus());
        },

        removeUnit(i) {
            const removed = this.f.extraUnits.splice(i, 1)[0];
            if (removed && this.f.default_unit_choice === 'unit:' + removed.name) this.f.default_unit_choice = 'base';
        },

        addBarcode(list, focusId) {
            list.push('');
            this.$nextTick(() => document.getElementById(focusId(list.length - 1))?.focus());
        },

        // A scanner types the code and presses Enter: Enter opens the next row,
        // so a box of codes can be scanned in without touching the mouse.
        barcodeEnter(list, i, focusId) {
            if (!list[i]) return;
            if (i === list.length - 1) this.addBarcode(list, focusId);
            else document.getElementById(focusId(i + 1))?.focus();
        },

        scan(target) {
            this.scanTarget = target;
            window.dispatchEvent(new CustomEvent('open-barcode-scanner'));
        },

        putScanned(target, code) {
            if (!code) return;
            if (target === 'barcode') this.f.barcode = code;
            else if (target === 'alt') this.f.extraBaseBarcodes.push(code);
            else if (target.startsWith('unit:')) this.f.extraUnits[Number(target.slice(5))]?.barcodes.push(code);
        },

        async checkBarcode(fieldKey, code) {
            code = String(code ?? '').trim();
            delete this.warnings[fieldKey];
            if (!code) return;

            const all = [this.f.barcode, ...this.f.extraBaseBarcodes, ...this.f.extraUnits.flatMap((u) => u.barcodes)].map((c) => String(c ?? '').trim());
            if (all.filter((c) => c === code).length > 1) {
                this.warnings[fieldKey] = 'Entered twice on this product.';
                return;
            }

            try {
                const problem = await this.$wire.checkQuickBarcode(code, this.mode === 'edit' ? this.id : null);
                if (problem) this.warnings[fieldKey] = problem;
            } catch { /* checked again on save */ }
        },

        // ── Details ────────────────────────────────────────────────────
        tagName(id) {
            return this.opts.tags.find((t) => String(t.id) === String(id))?.name ?? '';
        },

        addTag() {
            const name = this.tagInput.trim();
            this.tagInput = '';
            if (!name) return;
            const existing = this.opts.tags.find((t) => t.name.toLowerCase() === name.toLowerCase());
            if (existing) {
                if (!this.f.tags.includes(String(existing.id))) this.f.tags.push(String(existing.id));
            } else if (!this.f.new_tags.some((t) => t.toLowerCase() === name.toLowerCase())) {
                this.f.new_tags.push(name);
            }
        },

        forgetWord(i) {
            const [word] = this.f.learned_words.splice(i, 1);
            if (word) this.f.forget_words.push(word.term);
        },

        // ── Photos: picked here, uploaded on Save ──────────────────────
        async pickPhotos(event) {
            const chosen = [...(event.target.files ?? [])];
            event.target.value = '';
            const room = this.opts.maxPhotos - this.f.photos.length;
            if (chosen.length > room) {
                this.errors = { ...this.errors, photos: [`A product can have at most ${this.opts.maxPhotos} photos.`] };
            }
            for (const file of chosen.slice(0, Math.max(0, room))) {
                const small = await shrink(file);
                small.previewUrl = URL.createObjectURL(small);
                files.push(small);
                this.newCount = files.length;
                this.f.photos.push({ _key: key(), tmp: files.length - 1, url: small.previewUrl });
            }
        },

        removePhoto(i) {
            this.f.photos.splice(i, 1);
        },

        makeMain(i) {
            const [photo] = this.f.photos.splice(i, 1);
            this.f.photos.unshift(photo);
        },

        uploadPhotos(list) {
            return new Promise((resolve, reject) => {
                this.$wire.uploadMultiple('quickPhotos', list, resolve, () => reject(new Error('upload')), (e) => {
                    this.savingLabel = `Uploading photos… ${e.detail?.progress ?? 0}%`;
                });
            });
        },

        // ── Saving ─────────────────────────────────────────────────────
        err(fieldKey) {
            return this.errors[fieldKey]?.[0] ?? null;
        },

        tabHasError(tab) {
            return Object.keys(this.errors).some((k) => tabFor(k) === tab);
        },

        get errorCount() {
            return Object.keys(this.errors).length;
        },

        payload() {
            const f = JSON.parse(JSON.stringify(this.f));
            f.extraUnits = f.extraUnits.map(({ _key, sku, ...u }) => u);
            f.specifications = f.specifications.map(({ _key, ...s }) => s);
            delete f.learned_words;
            return f;
        },

        async save(another = false) {
            if (this.saving || this.loading || this.mode === 'limit') return;
            this.saving = true;
            this.errors = {};

            try {
                // Only photos still in the form go up, in the form's order.
                const kept = this.f.photos.filter((p) => p.tmp !== undefined);
                const picked = kept.map((p) => files[p.tmp]);
                const sig = kept.map((p) => p._key).join(',');

                if (picked.length && sig !== this.uploadedSig) {
                    this.savingLabel = 'Uploading photos…';
                    await this.uploadPhotos(picked);
                    this.uploadedSig = sig;
                }

                const data = this.payload();
                let upload = 0;
                data.photos = this.f.photos.map((p) => (p.uuid ? { uuid: p.uuid } : { upload: upload++ }));

                this.savingLabel = 'Saving…';
                const result = await this.$wire.saveQuickProduct(data, this.mode === 'edit' ? this.id : null);

                if (!result?.ok) {
                    this.errors = result?.errors ?? { form: ['Could not save. Try again.'] };
                    this.showFirstError();
                    return;
                }

                this.cache.clear();

                if (another) {
                    // The next product is usually the same kind: keep where it
                    // goes and how it is sold, clear what it is.
                    const keep = {
                        category_id: this.f.category_id, store_id: this.f.store_id, base_unit: this.f.base_unit,
                        supplier_id: this.f.supplier_id, status: this.f.status, low_stock_threshold: this.f.low_stock_threshold,
                        show_in_pos: this.f.show_in_pos, show_online: this.f.show_online,
                    };
                    this.openCreate(keep);
                } else {
                    this.open = false;
                }
            } catch (e) {
                this.errors = { form: [e?.message === 'upload' ? 'A photo could not be uploaded. Check the connection, then press Save again.' : 'Could not reach the server. Check the connection, then press Save again — nothing was lost.'] };
            } finally {
                this.saving = false;
                this.savingLabel = '';
            }
        },

        showFirstError() {
            const first = Object.keys(this.errors)[0];
            if (!first) return;
            const tab = tabFor(first);
            if (tab) {
                this.advanced = true;
                this.tab = tab;
            }
            this.$nextTick(() => {
                const el = this.$root.querySelector('[data-error]') ?? this.$refs.errorSummary;
                el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        },
    };
    });
});
