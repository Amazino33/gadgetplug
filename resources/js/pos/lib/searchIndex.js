import { db } from './db';
import { createProductSearch } from '../../shared/productSearch';

/**
 * The till's product search index, built from the device's copy of the
 * catalogue (IndexedDB) and then kept in step with it.
 *
 * Searching used to read every product out of IndexedDB on each keystroke and
 * keep the first ten whose name contained the typed text. The index is built
 * once per catalogue, so a keystroke is a lookup in memory, ranked the way
 * shared/productSearch.js describes. Results are read back from IndexedDB by
 * id, so the stock and prices shown are always the stored ones.
 *
 * Building is done a few hundred products at a time so a big catalogue never
 * freezes the till; when the catalogue is reloaded, the old index keeps
 * answering until the new one is complete.
 *
 * Every write to db.products must tell this module (replaceSearchIndex /
 * upsertSearchIndex / removeFromSearchIndex / resetSearchIndex), or a
 * product could be found that is no longer on the device, or missed.
 */
const OPTIONS = { preferInStock: true };

let index = null;
let building = null;
// Bumped by every change, so a build that started before a change is not
// kept over what that change brought.
let generation = 0;

async function build(products) {
    const fresh = createProductSearch([], OPTIONS);
    await fresh.addManyInChunks(products);
    return fresh;
}

export function getSearchIndex() {
    if (index) return Promise.resolve(index);

    if (!building) {
        const started = generation;
        building = db.products.toArray()
            .then(build)
            .then((built) => {
                if (started === generation || !index) index = built;
                return index;
            })
            .finally(() => { building = null; });
    }

    return building;
}

/** The catalogue was replaced whole (a reload). The old index answers until this one is ready. */
export function replaceSearchIndex(products) {
    const started = ++generation;

    return build(products ?? []).then((built) => {
        if (started === generation) index = built;
    });
}

/** Products written to the device (a server answer, a scan). */
export function upsertSearchIndex(products) {
    index?.addMany(products ?? []);
}

export function removeFromSearchIndex(ids) {
    index?.remove(ids ?? []);
}

/** The device's catalogue was cleared (another shop or branch signed in). */
export function resetSearchIndex() {
    generation++;
    index = null;
}

/**
 * The best matches on this device, read fresh from IndexedDB, with the ids of
 * those that matched in a way the server's search cannot (a forgiven typo, a
 * word run together) — those are never dropped just because the server's
 * answer left them out.
 */
export async function searchDevice(query, limit = 10) {
    const hits = (await getSearchIndex()).search(query, { limit });
    const fresh = await db.products.bulkGet(hits.map((h) => h.product.id));

    const products = [];
    const unprunable = new Set();

    hits.forEach((hit, i) => {
        if (!fresh[i]) return;
        products.push(fresh[i]);
        if (!hit.prunable) unprunable.add(hit.product.id);
    });

    return { products, unprunable };
}
