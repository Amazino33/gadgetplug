import api from './api';
import { createProductSearch, rankProducts } from '../../shared/productSearch';

/**
 * The terminal's whole catalogue, held in memory so a scan is answered on the
 * spot — the till's approach (pos/lib/catalogue.js): fetched a page at a time
 * in id order, so no single request grows with the shop.
 */
export const CATALOGUE_PAGE = 500;

export async function fetchCatalogue() {
    const all = [];
    let afterId = 0;

    for (;;) {
        const { data } = await api.get('/catalogue', { params: { after_id: afterId, limit: CATALOGUE_PAGE } });

        if (!Array.isArray(data)) throw new Error('The catalogue page was not a list.');

        all.push(...data);

        if (data.length < CATALOGUE_PAGE) return all;

        const lastId = Number(data[data.length - 1]?.id);

        if (!Number.isFinite(lastId) || lastId <= afterId) return all;

        afterId = lastId;
    }
}

const norm = (code) => String(code ?? '').trim().toLowerCase();

/**
 * Every code that names a product, to what it names: its barcode and SKU, and
 * each extra or unit barcode (a carton's own code means the carton).
 */
export function buildIndex(products) {
    const index = new Map();

    for (const p of products) addToIndex(index, p);

    return index;
}

export function addToIndex(index, product) {
    const put = (code, unitId) => {
        const k = norm(code);
        if (k && !index.has(k)) index.set(k, { product, unitId });
    };

    put(product.barcode, null);
    for (const b of product.barcodes ?? []) put(b.barcode, b.unit_id ?? null);
    for (const u of product.units ?? []) for (const code of u.barcodes ?? []) put(code, u.is_base ? null : u.id);
    put(product.sku, null);

    return index;
}

/** The product a scanned code names, tagged with the unit it means — or null. */
export function resolveCode(index, code) {
    const hit = index.get(norm(code));

    if (!hit) return null;

    return { ...hit.product, scanned_unit_id: hit.unitId };
}

/*
 * Name search over the in-memory catalogue: the shared product search
 * (shared/productSearch.js) — any word order, word starts, a slip forgiven,
 * the branch's best sellers first. The index is built once per catalogue
 * array and reused for every keystroke; the terminal swaps in a new array
 * whenever the catalogue changes, which is what retires the old index.
 */
const indexes = new WeakMap();

/** The matches with how they matched: { product, fuzzy, exact, score }. */
export function searchHits(products, term, limit = 15) {
    if (norm(term).length < 2) return [];

    let index = indexes.get(products);
    if (!index) {
        index = createProductSearch(products);
        indexes.set(products, index);
    }

    return index.search(term, { limit });
}

export const searchLocal = (products, term, limit = 15) => searchHits(products, term, limit).map((r) => r.product);

/**
 * Builds a catalogue's index in the background, a few hundred products at a
 * time, so the first letter typed does not wait for it.
 */
export async function warmSearch(products) {
    if (indexes.has(products)) return;
    const index = createProductSearch([]);
    await index.addManyInChunks(products);
    if (!indexes.has(products)) indexes.set(products, index);
}

/** A server's answer, put in the order the local search would show it. */
export const rankResults = (products, term) => rankProducts(products ?? [], term);
