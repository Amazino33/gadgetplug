/**
 * Holds the id that identifies one checkout to the server.
 *
 * The server recognises a repeat submission by the reference it derives from
 * this id, and returns the sale it already made instead of making another. That
 * only works if every attempt at the SAME checkout carries the SAME id — which
 * is exactly what went wrong: the till generated a fresh id inside the payload
 * on every call, so a cashier tapping the payment button four times on a slow
 * connection looked to the server like four different customers buying the same
 * thing, and it dutifully rang up four sales.
 *
 * Kept out of the component so this rule can be tested on its own. The
 * component owns the in-flight flag, which is React's business; this owns the
 * identity of the checkout, which is the part that has to be right.
 */
export function createCheckoutId(generate) {
    let current = null;

    return {
        /**
         * The id for an attempt at the current checkout. Generated on the first
         * attempt and returned unchanged for every attempt after it, so a
         * retry — whether the cashier's or the sync queue's — is recognisable
         * as the same sale.
         */
        forAttempt() {
            current ??= generate();

            return current;
        },

        /**
         * The sale landed. The next customer is a new checkout and needs a new
         * id.
         *
         * Only ever called on success. A sale the server refused, or one still
         * sitting in the offline queue, is the same attempt and must keep its
         * id — dropping it there is what would turn one queued sale into two.
         */
        settled() {
            current = null;
        },

        /** The id currently in play, or null before the first attempt. */
        peek() {
            return current;
        },
    };
}
