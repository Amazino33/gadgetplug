/**
 * Finding products from the task pack, in memory, with no network.
 *
 * The pack carries products as compact rows:
 *   [id, name, sku, picture, base unit, [[unitId, name, factor], …], [[code, unitId|null], …]]
 * This turns them into objects once, and builds one Map from every barcode
 * (a crate's own code included) to the product and the unit it names, so a
 * scan is one Map lookup.
 */
export function buildIndex(pack) {
    const products = new Map();
    const codes = new Map();
    const skus = new Map();
    const searchable = [];

    for (const [id, name, sku, image, baseUnit, units, barcodes, ceiling] of pack.products ?? []) {
        const product = {
            id,
            name,
            sku,
            image,
            base_unit: baseUnit,
            units: (units ?? []).map(([unitId, unitName, factor]) => ({
                id: unitId, name: unitName, factor, is_base: factor === 1,
            })),
            barcodes: (barcodes ?? []).map(([code]) => code),
            // Above this, "Save 4,800 pieces?" first (never shown; not the stock).
            ceiling: ceiling ?? null,
        };

        products.set(id, product);
        for (const [code, unitId] of barcodes ?? []) codes.set(String(code), { product, unitId: unitId ?? null });
        if (sku) skus.set(String(sku).toLowerCase(), product);
        searchable.push([`${name} ${sku ?? ''}`.toLowerCase(), product]);
    }

    return { products, codes, skus, searchable };
}

/** A scanned or typed code: a barcode (with the unit it names), else a SKU. */
export function lookupCode(index, raw) {
    const code = String(raw ?? '').trim();
    if (!code) return null;

    const hit = index.codes.get(code);
    if (hit) return { product: hit.product, unitId: hit.unitId };

    const bySku = index.skus.get(code.toLowerCase());
    return bySku ? { product: bySku, unitId: null } : null;
}

/** Name or SKU search: names that start with the term first, then any that contain it. */
export function search(index, term, limit = 15) {
    const q = String(term ?? '').trim().toLowerCase();
    if (q.length < 2) return [];

    const starts = [];
    const contains = [];

    for (const [text, product] of index.searchable) {
        if (text.startsWith(q)) starts.push(product);
        else if (text.includes(q)) contains.push(product);
        if (starts.length >= limit) break;
    }

    return [...starts, ...contains].slice(0, limit);
}
