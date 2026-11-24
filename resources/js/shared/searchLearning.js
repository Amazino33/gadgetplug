import { normalize } from './productSearch';

/**
 * Notices when staff search with a word the product list does not know, so
 * the server can learn it (App\Services\Products\SearchShortcuts).
 *
 * Two moments teach something:
 *
 *   - A search finds nothing — "titus" — and within half a minute the same
 *     person finds and picks a product some other way — "mackerel". The
 *     word that failed is reported for the product picked.
 *   - A product is picked that was not at the top of the list. The words
 *     typed are reported for it, so next time it is nearer the top.
 *
 * The server counts a pair only once it has been seen twice, so one stray
 * pick teaches nothing. Codes (all digits) and one-letter searches are never
 * reported, and nothing here ever waits on the network.
 */
export function createSearchLearner(send, { now = () => Date.now(), windowMs = 30_000 } = {}) {
    let missed = null; // { term, at }

    // Learning must never get in the way of a sale: whatever sending does,
    // the pick it rides on carries on.
    const report = (productId, term) => {
        try {
            send(productId, term);
        } catch {
            /* teaches nothing this time */
        }
    };

    const worthLearning = (term) => term.length >= 3 && /[a-z]/.test(term);

    return {
        /** A search finished, with this many results. */
        searched(query, count) {
            const term = normalize(query);
            if (count > 0 || !worthLearning(term)) return;

            // Backspacing over a failed word ("titus" → "titu") keeps the whole word.
            if (missed && missed.term.startsWith(term) && now() - missed.at <= windowMs) {
                missed.at = now();
                return;
            }

            missed = { term, at: now() };
        },

        /** A product was picked from the results of query, at this position (0 = top). */
        picked(product, query, position) {
            if (!product?.id) return;
            const term = normalize(query);

            if (missed && now() - missed.at <= windowMs && term !== missed.term && !term.startsWith(missed.term)) {
                report(product.id, missed.term);
            }
            missed = null;

            if (position > 0 && worthLearning(term)) report(product.id, term);
        },

        /** A barcode was scanned: whatever was being looked for has been found. */
        reset() {
            missed = null;
        },
    };
}
