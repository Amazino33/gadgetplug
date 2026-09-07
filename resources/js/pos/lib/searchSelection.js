/**
 * Decides what — if anything — pressing Enter in the product search adds to
 * the cart.
 *
 * The answer is: only the row the cashier arrowed onto. Nothing else.
 *
 * Two softer rules were tried here and both rang up goods nobody chose. The
 * first took the result outright whenever exactly one was showing, and on a
 * phone Enter is the keyboard's Go key — typing "sam" and tapping Go to put
 * the keyboard away bought a charger. The second took a result whose full
 * barcode or SKU had been typed, on the reasoning that a human types names
 * and a handheld scanner types identifiers. That is not true of this
 * catalogue: imports have left real products sitting on SKUs like "1" and
 * "5", so a cashier pressing a single digit was handed a product and a
 * quantity prompt.
 *
 * There is no clever version of this. A keystroke does not tell you which
 * product someone wants; pointing at one does. So Enter resolves only after
 * a deliberate highlight, and everything else — a lone result, an exact
 * identifier, a fully typed name — returns null and leaves the list open to
 * be clicked or arrowed onto.
 */
export function selectionForEnter({ results = [], activeIndex = -1 } = {}) {
    if (activeIndex >= 0 && activeIndex < results.length) {
        return results[activeIndex];
    }

    return null;
}
