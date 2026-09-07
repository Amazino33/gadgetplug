/**
 * Marks each search attempt with a token and says whether it is still the
 * most recent one asked for.
 *
 * The IndexedDB lookup and the network fallback both take variable, unrelated
 * amounts of time, and a cashier keeps typing while they run. Without this, a
 * slow answer to an earlier, shorter query (e.g. "b") can land after a faster
 * answer to what's on screen now (e.g. "bat") and silently replace it — which
 * is what "the search bar loads different products" actually was: not wrong
 * results, stale ones arriving out of order.
 *
 * Kept out of the component so the ordering rule can be tested on its own,
 * the same reason createCheckoutId() is separate from the checkout button.
 */
export function createLatestSearch() {
    let current = 0;

    return {
        /** Call at the start of an attempt. Returns the token that attempt owns. */
        start() {
            return ++current;
        },

        /** Whether `token` still belongs to the most recent attempt started. */
        isCurrent(token) {
            return token === current;
        },
    };
}
