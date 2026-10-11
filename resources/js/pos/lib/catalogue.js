import api from './api';

/**
 * Products per request. Small enough that a shared host builds each page in
 * well under a second and a few MB; a shop of 8,000 products is 16 requests.
 */
export const CATALOGUE_PAGE = 500;

/**
 * The till's whole branch catalogue, fetched a page at a time.
 *
 * It used to be one request for everything. Past a few thousand products that
 * request ran out of memory on the server and failed, and the till carried on
 * with whatever it had cached — so a barcode only scanned once someone had
 * searched for that product by name. Paged, every request stays small.
 *
 * Resolves only once every page has arrived, so the caller can swap the new
 * catalogue in whole or keep the old one — never half of each. Throws if any
 * page fails.
 */
export async function fetchCatalogue(vendorId) {
    const all = [];
    let afterId = 0;

    for (;;) {
        const { data } = await api.get('/products', {
            params: { vendor_id: vendorId, after_id: afterId, limit: CATALOGUE_PAGE },
        });

        if (!Array.isArray(data)) throw new Error('The catalogue page was not a list.');

        // A server from before paging ignores the limit and sends everything
        // at once. That is the whole catalogue, so take it as it is.
        if (data.length > CATALOGUE_PAGE) return data;

        all.push(...data);

        if (data.length < CATALOGUE_PAGE) return all;

        const lastId = Number(data[data.length - 1]?.id);

        // No progress means the server is not paging after all; stop rather
        // than ask for the same page forever.
        if (!Number.isFinite(lastId) || lastId <= afterId) return all;

        afterId = lastId;
    }
}
