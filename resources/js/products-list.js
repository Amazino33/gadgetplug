/**
 * The Products list, in the browser.
 *
 * The page opens with its first 50 rows (rendered with it). The whole
 * branch's catalogue then arrives in the background
 * (ProductCatalogueController) and is kept in IndexedDB, so the next visit
 * shows everything at once. From then on searching, the summary tiles,
 * filters, sorting and paging touch no server. Once a minute, and after any
 * change made on the page, it asks whether anything changed and quietly takes
 * the new list if so.
 *
 * Loaded from <head> on the Products list only (VendorPanelProvider), so the
 * alpine:init listener is in place before Livewire starts Alpine.
 */

const DB_NAME = 'GadgetPlugProducts';
const STORE = 'catalogue';
const REFRESH_MS = 60_000;
const DAY = 86_400;

/** A tiny IndexedDB key-value store; any failure just means no cache. */
const cache = {
    open() {
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = () => req.result.createObjectStore(STORE);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    },
    async get(key) {
        try {
            const db = await this.open();
            return await new Promise((resolve) => {
                const q = db.transaction(STORE).objectStore(STORE).get(key);
                q.onsuccess = () => resolve(q.result ?? null);
                q.onerror = () => resolve(null);
            });
        } catch {
            return null;
        }
    },
    async put(key, value) {
        try {
            const db = await this.open();
            db.transaction(STORE, 'readwrite').objectStore(STORE).put(value, key);
        } catch { /* private window, storage full: no cache this time */ }
    },
};

// Made once: a new formatter for every number made drawing a page of rows slow.
const MONEY = new Intl.NumberFormat('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const COUNT = new Intl.NumberFormat('en-NG');
const money = (n) => '₦' + MONEY.format(Number(n ?? 0));
const moneyShort = (n) => {
    const v = Number(n ?? 0);
    if (Math.abs(v) >= 1e9) return '₦' + (v / 1e9).toFixed(1) + 'bn';
    if (Math.abs(v) >= 1e6) return '₦' + (v / 1e6).toFixed(1) + 'm';
    if (Math.abs(v) >= 1e3) return '₦' + (v / 1e3).toFixed(0) + 'k';
    return money(v);
};
const count = (n) => COUNT.format(Number(n ?? 0));

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/** Heroicons (outline), inline so a row costs no extra request. */
const ICON = {
    photo: 'm2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z',
    pencil: 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10',
    trash: 'm14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0',
};
const icon = (name, cls) => `<svg class="${cls}" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${ICON[name]}"/></svg>`;
const SPIN = '<svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/></svg>';
const CHECK = 'rounded border-gray-300 text-primary-600 focus:ring-primary-500';
const TH = 'px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500';

/** The optional columns, and what each one is called. */
export const OPTIONAL_COLUMNS = {
    barcode: 'Barcode',
    category: 'Category',
    low_at: 'Low at',
    last_sold: 'Last sold',
    sold_7d: 'Sold this week',
    margin: 'Margin',
};

export const SORTS = {
    newest: 'Newest first',
    oldest: 'Oldest first',
    name: 'Name A–Z',
    name_desc: 'Name Z–A',
    price_desc: 'Price: high to low',
    price: 'Price: low to high',
    stock_desc: 'Stock: most first',
    stock: 'Stock: least first',
    last_sold: 'Last sold (recent first)',
    sold_7d: 'Selling fastest this week',
    margin_desc: 'Margin: highest first',
};

export const PAGE_SIZES = [25, 50, 100, 200];

/**
 * The summary tiles: what each counts, and the filter it applies when tapped.
 * `cost` tiles are only for people who may see cost prices.
 */
export function tileTests(nowSec) {
    const days = (n) => nowSec - n * DAY;
    return {
        total:       { group: 'stock', label: 'Total products', test: () => true },
        in_stock:    { group: 'stock', label: 'In stock', tone: 'green', test: (r) => r.available > 0 },
        low:         { group: 'stock', label: 'Low stock', tone: 'amber', test: (r) => r.available > 0 && r.available < r.low_at },
        out:         { group: 'stock', label: 'Out of stock', tone: 'red', test: (r) => r.available <= 0 && r.quantity >= 0 },
        negative:    { group: 'stock', label: 'Negative stock', tone: 'red', test: (r) => r.quantity < 0 },
        no_photo:    { group: 'gaps', label: 'No photo', test: (r) => !r.photo },
        no_barcode:  { group: 'gaps', label: 'No barcode', test: (r) => !r.has_barcode },
        no_cost:     { group: 'gaps', label: 'No cost price', cost: true, test: (r) => r.cost === null },
        no_category: { group: 'gaps', label: 'No category', test: (r) => !r.category },
        draft:       { group: 'gaps', label: 'Draft', test: (r) => r.status === 'draft' },
        selling:     { group: 'movement', label: 'Sold this week', tone: 'green', test: (r) => r.sold_7d > 0 },
        // Dead stock: on the shelf, older than the window, and not sold in it.
        idle_30:     { group: 'movement', label: 'Not sold in 30 days', tone: 'amber', test: (r) => r.available > 0 && (r.created ?? 0) < days(30) && (r.last_sold ?? 0) < days(30) },
        idle_90:     { group: 'movement', label: 'Not sold in 90 days', tone: 'red', test: (r) => r.available > 0 && (r.created ?? 0) < days(90) && (r.last_sold ?? 0) < days(90) },
    };
}

/** Turn the compact rows into objects once, with what search and sort need. */
export function hydrate(columns, rows) {
    const at = Object.fromEntries(columns.map((c, i) => [c, i]));
    return rows.map((r) => {
        const o = {};
        for (const c of columns) o[c] = r[at[c]];
        o.margin = o.cost !== null && o.price > 0 ? ((o.price - o.cost) / o.price) * 100 : null;
        // Frozen: Alpine leaves a frozen object as it is, rather than wrapping
        // it to watch for changes, which made drawing a page of rows slow.
        return Object.freeze(o);
    });
}

/** Every word typed must be in the product's searchable text, in any order ("peak 400"). */
export function matchesSearch(row, words) {
    for (const w of words) if (!row.search.includes(w)) return false;
    return true;
}

export function sortRows(rows, sort) {
    const by = {
        newest:      (a, b) => b.id - a.id,
        oldest:      (a, b) => a.id - b.id,
        name:        (a, b) => a.name.localeCompare(b.name),
        name_desc:   (a, b) => b.name.localeCompare(a.name),
        price:       (a, b) => a.price - b.price,
        price_desc:  (a, b) => b.price - a.price,
        stock:       (a, b) => a.available - b.available,
        stock_desc:  (a, b) => b.available - a.available,
        last_sold:   (a, b) => (b.last_sold ?? 0) - (a.last_sold ?? 0),
        sold_7d:     (a, b) => b.sold_7d - a.sold_7d,
        margin_desc: (a, b) => (b.margin ?? -Infinity) - (a.margin ?? -Infinity),
    }[sort] ?? ((a, b) => b.id - a.id);
    return rows.slice().sort(by);
}

/** The rows a view shows: tile, status, category, then search. */
export function filterRows(rows, { tile, status, category, search }, tests) {
    const words = String(search ?? '').toLowerCase().split(/\s+/).filter(Boolean);
    const t = tile ? tests[tile]?.test : null;
    return rows.filter((r) => {
        if (t && !t(r)) return false;
        // "Published" includes ones whose publish window has ended, as the server does.
        if (status && !(r.status === status || (status === 'published' && r.status === 'expired'))) return false;
        if (category && String(r.category) !== String(category)) return false;
        return words.length === 0 || matchesSearch(r, words);
    });
}

function registerProductsList() {
    window.Alpine.data('productsList', (cfg) => {
    // Kept outside Alpine's reactivity: thousands of rows made reactive would
    // slow every filter. Only the page on screen and the counts are reactive.
    let all = [];        // the whole branch (or the first page until it arrives)
    let matching = [];   // what the current view matches, sorted

    return {
        cfg,
        allCount: 0,
        matchCount: 0,
        loaded: false,        // the whole branch is here
        loading: false,       // a background fetch is running
        failed: false,
        version: null,
        categories: cfg.initial.categories ?? {},
        builtAt: null,

        // The view: remembered on this device, overridden by the address.
        display: 'table',
        search: '',
        status: '',
        category: '',
        tile: null,
        sort: 'newest',
        perPage: 50,
        page: 1,
        columns: [],
        columnsOpen: false,
        narrow: window.matchMedia?.('(max-width: 767px)').matches ?? false,

        // What the view shows, worked out on change (not on every render).
        pageRows: [],
        tiles: {},
        values: { cost: 0, retail: 0, profit: 0, viewRetail: 0, viewCost: 0 },

        selected: new Set(),
        selectAll: false,     // "all N matching", across pages
        busy: null,           // which bulk action is running
        bulkCategory: '',
        bulkLowAt: '',

        sorts: SORTS,
        pageSizes: PAGE_SIZES,
        optionalColumns: OPTIONAL_COLUMNS,

        init() {
            // The first page the server drew has done its job: the list takes over.
            this.$root.querySelector('[data-first-page]')?.remove();
            this.restoreView();
            all = hydrate(cfg.columns, cfg.initial.rows ?? []);
            this.recompute();

            this.loadCached().then(() => this.refresh());
            this.timer = setInterval(() => this.refresh(), REFRESH_MS);
            document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') this.refresh(); });

            for (const key of ['search', 'status', 'category', 'tile', 'sort', 'perPage']) {
                this.$watch(key, () => { this.page = 1; this.recompute(); this.saveView(); });
            }
            this.$watch('page', () => this.slicePage());
            // Phones get cards, wider screens the table: only one is drawn.
            window.matchMedia?.('(max-width: 767px)').addEventListener?.('change', (e) => { this.narrow = e.matches; });
            this.$watch('display', () => { this.saveView(); this.drawRows(); });
            this.$watch('columns', () => { this.saveView(); this.drawRows(); });
            this.$watch('narrow', () => this.drawRows());
            this.$watch('busy', () => this.drawRows());
            this.$watch('selectAll', () => this.drawRows());
            this.$nextTick(() => this.drawRows());
        },

        destroy() {
            clearInterval(this.timer);
        },

        // ── Loading ──────────────────────────────────────────────────────

        async loadCached() {
            const hit = await cache.get(cfg.storageKey);
            if (hit?.rows && hit.columns) this.take(hit);
        },

        /** Ask for the list; with what we have, ask only whether it changed. */
        async refresh() {
            if (this.loading) return;
            this.loading = true;
            try {
                const url = new URL(cfg.catalogueUrl, window.location.origin);
                if (this.loaded && this.version) url.searchParams.set('v', this.version);
                const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!res.ok) throw new Error(String(res.status));
                const data = await res.json();
                if (!data.same) {
                    this.take(data);
                    cache.put(cfg.storageKey, data);
                }
                this.failed = false;
            } catch {
                this.failed = !this.loaded;
            } finally {
                this.loading = false;
            }
        },

        take(data) {
            all = hydrate(data.columns, data.rows);
            this.categories = data.categories ?? this.categories;
            this.version = data.v;
            this.builtAt = data.built_at;
            this.loaded = true;
            // Drop selections of products that are gone.
            const ids = new Set(all.map((r) => r.id));
            for (const id of [...this.selected]) if (!ids.has(id)) this.selected.delete(id);
            this.recompute();
        },

        // ── The view ─────────────────────────────────────────────────────

        recompute() {
            const now = Math.floor(Date.now() / 1000);
            this.tests = tileTests(now);

            const tiles = {};
            for (const [key, t] of Object.entries(this.tests)) {
                if (t.cost && !cfg.can.cost) continue;
                tiles[key] = { ...t, count: 0 };
            }
            let cost = 0;
            let retail = 0;
            let profit = 0;
            for (const r of all) {
                for (const key in tiles) if (tiles[key].test(r)) tiles[key].count++;
                const units = Math.max(0, r.available);
                retail += units * r.price;
                if (r.cost !== null) { cost += units * r.cost; profit += units * (r.price - r.cost); }
            }
            this.tiles = tiles;

            matching = sortRows(filterRows(all, this, this.tests), this.sort);
            let viewRetail = 0;
            let viewCost = 0;
            for (const r of matching) {
                const units = Math.max(0, r.available);
                viewRetail += units * r.price;
                if (r.cost !== null) viewCost += units * r.cost;
            }
            this.values = { cost, retail, profit, viewRetail, viewCost };
            this.allCount = all.length;
            this.matchCount = matching.length;
            this.slicePage();
            this.syncUrl();
        },

        slicePage() {
            const pages = this.pages;
            if (this.page > pages) this.page = pages;
            const start = (this.page - 1) * this.perPage;
            this.pageRows = matching.slice(start, start + this.perPage);
            this.drawRows();
        },

        get pages() {
            return Math.max(1, Math.ceil(this.matchCount / this.perPage));
        },

        get total() {
            return this.loaded ? this.allCount : (cfg.initial.total ?? this.allCount);
        },

        /** Filters set before the whole list is here would search only the first page: wait. */
        get waitingForAll() {
            return !this.loaded && (this.search || this.status || this.category || this.tile || this.sort !== 'newest' || this.page > 1);
        },

        get filtered() {
            return Boolean(this.search || this.status || this.category || this.tile);
        },

        pickTile(key) {
            this.tile = this.tile === key ? null : key;
        },

        clearFilters() {
            this.search = '';
            this.status = '';
            this.category = '';
            this.tile = null;
        },

        toggleColumn(key) {
            this.columns = this.columns.includes(key) ? this.columns.filter((c) => c !== key) : [...this.columns, key];
        },

        has(column) {
            return this.columns.includes(column);
        },

        restoreView() {
            let saved = {};
            try { saved = JSON.parse(localStorage.getItem(cfg.storageKey + '.view') || '{}'); } catch { /* none */ }
            const url = cfg.url ?? {};
            const fromUrl = new URLSearchParams(window.location.search);

            this.display = fromUrl.has('display') ? (url.display || 'table') : (saved.display || 'table');
            this.search = fromUrl.has('search') ? (url.search || '') : (saved.search || '');
            this.status = fromUrl.has('status') ? (url.status || '') : (saved.status || '');
            this.category = fromUrl.has('category') ? (url.category || '') : (saved.category || '');
            this.tile = saved.tile || null;
            this.sort = SORTS[saved.sort] ? saved.sort : 'newest';
            this.perPage = PAGE_SIZES.includes(saved.perPage) ? saved.perPage : 50;
            this.columns = Array.isArray(saved.columns) ? saved.columns.filter((c) => OPTIONAL_COLUMNS[c]) : [];
        },

        saveView() {
            try {
                localStorage.setItem(cfg.storageKey + '.view', JSON.stringify({
                    display: this.display, search: this.search, status: this.status, category: this.category,
                    tile: this.tile, sort: this.sort, perPage: this.perPage, columns: this.columns,
                }));
            } catch { /* private window */ }
        },

        /** Keep the address shareable: the same view opens from a copied link. */
        syncUrl() {
            const url = new URL(window.location.href);
            const set = (k, v) => (v ? url.searchParams.set(k, v) : url.searchParams.delete(k));
            set('display', this.display === 'table' ? '' : this.display);
            set('search', this.search);
            set('status', this.status);
            set('category', this.category);
            window.history.replaceState(window.history.state, '', url);
        },

        // ── Selection and bulk actions ───────────────────────────────────

        isSelected(id) {
            return this.selectAll || this.selected.has(id);
        },

        toggle(id) {
            if (this.selectAll) {
                this.selectAll = false;
                this.selected = new Set(matching.map((r) => r.id));
            }
            const next = new Set(this.selected);
            next.has(id) ? next.delete(id) : next.add(id);
            this.selected = next;
            this.drawRows();
        },

        get pageSelected() {
            return this.pageRows.length > 0 && this.pageRows.every((r) => this.isSelected(r.id));
        },

        togglePage() {
            const next = new Set(this.selectAll ? matching.map((r) => r.id) : this.selected);
            this.selectAll = false;
            if (this.pageSelected) this.pageRows.forEach((r) => next.delete(r.id));
            else this.pageRows.forEach((r) => next.add(r.id));
            this.selected = next;
            this.drawRows();
        },

        get selectedIds() {
            return this.selectAll ? matching.map((r) => r.id) : [...this.selected];
        },

        get selectedCount() {
            return this.selectAll ? this.matchCount : this.selected.size;
        },

        clearSelection() {
            this.selectAll = false;
            this.selected = new Set();
            this.drawRows();
        },

        async bulk(field, value, label) {
            if (this.busy || this.selectedCount === 0) return;
            this.busy = label;
            try {
                const result = await this.$wire.bulkUpdate(this.selectedIds, field, value);
                if (result?.ok) { this.clearSelection(); await this.refreshNow(); }
            } finally {
                this.busy = null;
            }
        },

        async bulkDelete() {
            if (this.busy || this.selectedCount === 0) return;
            if (!window.confirm(`Delete ${count(this.selectedCount)} product(s)? This cannot be undone.`)) return;
            this.busy = 'delete';
            try {
                await this.$wire.bulkDelete(this.selectedIds);
                this.clearSelection();
                await this.refreshNow();
            } finally {
                this.busy = null;
            }
        },

        async bulkExport() {
            if (this.busy || this.selectedCount === 0) return;
            this.busy = 'export';
            try {
                await this.$wire.exportSelected(this.selectedIds);
            } finally {
                this.busy = null;
            }
        },

        async deleteOne(row) {
            if (!window.confirm(`Delete ${row.name}? This cannot be undone.`)) return;
            this.busy = 'row-' + row.id;
            try {
                await this.$wire.deleteProduct(row.id);
                await this.refreshNow();
            } finally {
                this.busy = null;
            }
        },

        /** After a change made here: fetch now, not at the next minute. */
        async refreshNow() {
            this.loading = false;
            await this.refresh();
        },

        // ── Drawing the rows ─────────────────────────────────────────────

        /** The page of rows as HTML, in one go (table, grid or phone cards). */
        drawRows() {
            const el = this.$refs.rows;
            if (!el) return;
            const rows = this.pageRows;
            if (rows.length === 0) { el.innerHTML = ''; return; }
            if (this.display === 'grid') el.innerHTML = this.gridHtml(rows);
            else if (this.narrow) el.innerHTML = this.cardsHtml(rows);
            else el.innerHTML = this.tableHtml(rows);
        },

        cellsFor() {
            const c = (k) => this.has(k) && (k !== 'margin' || cfg.can.cost);
            return { barcode: c('barcode'), category: c('category'), margin: c('margin'), low_at: c('low_at'), sold_7d: c('sold_7d'), last_sold: c('last_sold') };
        },

        thumb(r, size) {
            return r.photo
                ? `<img src="${esc(r.photo)}" alt="" loading="lazy" class="h-full w-full object-cover">`
                : icon('photo', `${size} text-gray-300 dark:text-gray-600`);
        },

        stockHtml(r) {
            const s = this.stock(r);
            return `<span class="inline-flex items-center gap-1.5 text-xs font-medium ${s.text}"><span class="h-2 w-2 rounded-full ${s.dot}"></span>${esc(s.label)}</span>`;
        },

        statusHtml(r) {
            return `<span class="inline-flex rounded px-2 py-0.5 text-[11px] font-semibold ${this.statusClass(r.status)}">${esc(this.label(r.status))}</span>`;
        },

        priceHtml(r) {
            const cost = cfg.can.cost && r.cost !== null ? `<p class="text-xs text-gray-400 dark:text-gray-500">Cost ${esc(money(r.cost))}</p>` : '';
            return `${cost}<p class="font-montserrat text-sm font-bold text-gray-900 dark:text-white">${esc(money(r.price))}</p>`;
        },

        editHtml(r, cls) {
            return `<a href="${esc(this.editUrl(r.id))}" title="Edit" aria-label="Edit ${esc(r.name)}" data-quick-edit="${r.id}" data-quick-name="${esc(r.name)}" class="${cls}">${icon('pencil', 'h-4 w-4')}</a>`;
        },

        deleteHtml(r) {
            if (!cfg.can.delete) return '';
            const busy = this.busy === 'row-' + r.id;
            return `<button type="button" data-delete="${r.id}" ${this.busy ? 'disabled' : ''} title="Delete" aria-label="Delete ${esc(r.name)}" class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20">${busy ? SPIN : icon('trash', 'h-4 w-4')}</button>`;
        },

        checkHtml(r, extra = '') {
            return `<input type="checkbox" data-select="${r.id}" ${this.isSelected(r.id) ? 'checked' : ''} aria-label="Select ${esc(r.name)}" class="${CHECK} ${extra}">`;
        },

        tableHtml(rows) {
            const c = this.cellsFor();
            const head = [
                `<th class="w-10 px-4 py-3"><input type="checkbox" data-select-page ${this.pageSelected ? 'checked' : ''} aria-label="Select all products on this page" class="${CHECK}"></th>`,
                `<th class="${TH}">Product</th>`,
                c.barcode ? `<th class="${TH}">Barcode</th>` : '',
                c.category ? `<th class="${TH}">Category</th>` : '',
                `<th class="${TH} text-right">Pricing</th>`,
                c.margin ? `<th class="${TH} text-right">Margin</th>` : '',
                `<th class="${TH} text-center">Stock</th>`,
                c.low_at ? `<th class="${TH} text-center">Low below</th>` : '',
                c.sold_7d ? `<th class="${TH} text-center">Sold this week</th>` : '',
                c.last_sold ? `<th class="${TH} text-center">Last sold</th>` : '',
                `<th class="${TH} text-center">Status</th>`,
                '<th class="w-24 px-4 py-3"></th>',
            ].join('');

            const cell = 'whitespace-nowrap px-4 py-3 text-xs text-gray-600 dark:text-gray-300';
            const body = rows.map((r) => {
                const sel = this.isSelected(r.id);
                const margin = r.margin === null ? '<span class="text-gray-400">—</span>' : `<span class="${r.margin < 10 ? 'text-red-600' : 'text-emerald-600'}">${r.margin.toFixed(1)}%</span>`;
                return `<tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5 ${sel ? 'bg-primary-50/60 dark:bg-primary-500/5' : ''}">`
                    + `<td class="px-4 py-3">${this.checkHtml(r)}</td>`
                    + `<td class="px-4 py-3"><a href="${esc(this.viewUrl(r.id))}" class="group flex items-center gap-3">`
                    + `<div class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-100 dark:border-white/10 dark:bg-gray-800">${this.thumb(r, 'h-5 w-5')}</div>`
                    + `<div class="min-w-0"><p title="${esc(r.name)}" class="truncate font-montserrat text-sm font-semibold text-gray-900 group-hover:underline dark:text-white">${esc(r.name)}</p>`
                    + `<p class="truncate text-xs text-gray-500 dark:text-gray-400">${esc(this.eyebrow(r))}</p></div></a></td>`
                    + (c.barcode ? `<td class="${cell} font-mono">${esc(r.barcode || '—')}</td>` : '')
                    + (c.category ? `<td class="${cell}">${esc(this.categories[r.category] || '—')}</td>` : '')
                    + `<td class="whitespace-nowrap px-4 py-3 text-right">${this.priceHtml(r)}</td>`
                    + (c.margin ? `<td class="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold">${margin}</td>` : '')
                    + `<td class="whitespace-nowrap px-4 py-3 text-center">${this.stockHtml(r)}</td>`
                    + (c.low_at ? `<td class="${cell} text-center">${esc(r.low_at)}</td>` : '')
                    + (c.sold_7d ? `<td class="${cell} text-center">${esc(this.qty(r.sold_7d))}</td>` : '')
                    + (c.last_sold ? `<td class="${cell} text-center">${esc(this.when(r.last_sold))}</td>` : '')
                    + `<td class="px-4 py-3 text-center">${this.statusHtml(r)}</td>`
                    + `<td class="px-4 py-3"><div class="flex items-center justify-end gap-1">${this.editHtml(r, 'flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-white')}${this.deleteHtml(r)}</div></td>`
                    + '</tr>';
            }).join('');

            // Fixed widths: the browser lays the table out in one pass instead of
            // measuring every cell first (that was most of the time on a 200-row page).
            const widths = [44, null, c.barcode && 128, c.category && 128, 120, c.margin && 80, 136,
                c.low_at && 88, c.sold_7d && 104, c.last_sold && 104, 96, 80].filter((w) => w !== false && w !== 0 && w !== undefined);
            const cols = widths.map((w) => `<col style="width:${w === null ? 'auto' : w + 'px'}">`).join('');
            // The product name gets what is left, at least 240 px; below that the table scrolls sideways.
            const min = widths.reduce((sum, w) => sum + (w ?? 240), 0);
            return '<div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">'
                + `<table class="w-full text-left" style="table-layout:fixed;min-width:${min}px"><colgroup>${cols}</colgroup>`
                + `<thead><tr class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-800">${head}</tr></thead>`
                + `<tbody data-list-body class="divide-y divide-gray-100 dark:divide-white/5">${body}</tbody></table></div>`;
        },

        gridHtml(rows) {
            const cards = rows.map((r) => '<div class="relative flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">'
                + this.checkHtml(r, 'absolute left-3 top-3 z-10')
                + `<a href="${esc(this.viewUrl(r.id))}" class="flex aspect-square items-center justify-center bg-gray-100 dark:bg-gray-800">${this.thumb(r, 'h-10 w-10')}</a>`
                + '<div class="flex flex-1 flex-col gap-1 p-3">'
                + `<a href="${esc(this.viewUrl(r.id))}" class="line-clamp-2 font-montserrat text-sm font-semibold text-gray-900 hover:underline dark:text-white">${esc(r.name)}</a>`
                + `<p class="truncate text-xs text-gray-500">${esc(this.eyebrow(r))}</p>`
                + `<div class="mt-auto flex items-end justify-between pt-2"><div>${this.priceHtml(r)}</div>${this.stockHtml(r)}</div>`
                + `<div class="flex items-center justify-between pt-1">${this.statusHtml(r)}<a href="${esc(this.editUrl(r.id))}" data-quick-edit="${r.id}" data-quick-name="${esc(r.name)}" class="text-xs font-semibold text-primary-600 hover:underline">Edit</a></div>`
                + '</div></div>').join('');
            return `<div data-list-body class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">${cards}</div>`;
        },

        cardsHtml(rows) {
            const cards = rows.map((r) => '<div class="flex gap-3 rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">'
                + this.checkHtml(r, 'mt-1')
                + `<a href="${esc(this.viewUrl(r.id))}" class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">${this.thumb(r, 'h-6 w-6')}</a>`
                + `<div class="min-w-0 flex-1"><a href="${esc(this.viewUrl(r.id))}" class="line-clamp-2 font-montserrat text-sm font-semibold text-gray-900 dark:text-white">${esc(r.name)}</a>`
                + `<p class="truncate text-xs text-gray-500">${esc(this.eyebrow(r))}</p>`
                + `<div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1"><span class="font-montserrat text-sm font-bold text-gray-900 dark:text-white">${esc(money(r.price))}</span>${this.stockHtml(r)}${this.statusHtml(r)}</div></div>`
                + this.editHtml(r, 'flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100')
                + '</div>').join('');
            return `<div data-list-body class="space-y-3">${cards}</div>`;
        },

        onRowsClick(e) {
            const del = e.target.closest('[data-delete]');
            if (del) {
                const row = this.pageRows.find((r) => r.id === Number(del.dataset.delete));
                if (row) this.deleteOne(row);
            }
        },

        onRowsChange(e) {
            if (e.target.matches('[data-select-page]')) { this.togglePage(); return; }
            const id = e.target.dataset?.select;
            if (id) this.toggle(Number(id));
        },

        // ── Formatting for the rows ──────────────────────────────────────

        money,
        moneyShort,
        count,

        qty(n) {
            const v = Number(n ?? 0);
            if (Number.isInteger(v)) return count(v);
            const whole = Math.trunc(v);
            if (cfg.halves && Math.abs(v - whole) === 0.5) return `${v < 0 ? '-' : ''}${Math.abs(whole) || ''}½`;
            return String(+v.toFixed(3));
        },

        stock(r) {
            if (r.quantity < 0) return { label: `Negative (${this.qty(r.quantity)})`, dot: 'bg-red-500', text: 'text-red-600 dark:text-red-400' };
            if (r.available <= 0) return { label: 'Out of stock', dot: 'bg-red-500', text: 'text-red-600 dark:text-red-400' };
            if (r.available < r.low_at) return { label: `Low stock (${this.qty(r.available)})`, dot: 'bg-amber-500', text: 'text-amber-600 dark:text-amber-400' };
            return { label: `${this.qty(r.available)} available`, dot: 'bg-emerald-500', text: 'text-gray-700 dark:text-gray-300' };
        },

        statusClass(status) {
            return {
                published: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
                draft: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
                archived: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
                expired: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            }[status] ?? 'bg-gray-100 text-gray-600';
        },

        label(status) {
            return status ? status.charAt(0).toUpperCase() + status.slice(1) : '';
        },

        eyebrow(r) {
            return [this.categories[r.category], r.brand, r.sku ? `SKU: ${r.sku}` : null].filter(Boolean).join(' · ') || '—';
        },

        when(ts) {
            if (!ts) return 'Never';
            const d = new Date(ts * 1000);
            const days = Math.floor((Date.now() - d.getTime()) / 86_400_000);
            if (days <= 0) return 'Today';
            if (days === 1) return 'Yesterday';
            if (days < 30) return `${days} days ago`;
            return d.toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' });
        },

        viewUrl(id) { return cfg.viewUrl.replace('__ID__', id); },
        editUrl(id) { return cfg.editUrl.replace('__ID__', id); },

        get rangeText() {
            if (this.matchCount === 0) return 'No products';
            const start = (this.page - 1) * this.perPage + 1;
            const end = Math.min(this.matchCount, this.page * this.perPage);
            return `${count(start)}–${count(end)} of ${count(this.matchCount)}`;
        },

        /** Page numbers to show: first, last, and a few around this one. */
        get pageList() {
            const last = this.pages;
            const set = new Set([1, last, this.page - 2, this.page - 1, this.page, this.page + 1, this.page + 2].filter((p) => p >= 1 && p <= last));
            const list = [...set].sort((a, b) => a - b);
            const out = [];
            list.forEach((p, i) => { if (i && p - list[i - 1] > 1) out.push('…' + p); out.push(p); });
            return out;
        },
    };
    });
}

if (window.Alpine) registerProductsList();
else document.addEventListener('alpine:init', registerProductsList);
