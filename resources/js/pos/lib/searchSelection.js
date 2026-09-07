/**
 * Decides what — if anything — pressing Enter in the product search adds to
 * the cart.
 *
 * This exists because the old rule was "if there's exactly one result, take
 * it", and on a phone the keyboard's Go/Search key sends Enter. A cashier
 * typing "sam", seeing one result, and tapping Go to put the keyboard away
 * had a product rung up they never chose — "it picks on its own". Nothing
 * about that was a decision.
 *
 * So Enter only ever resolves to a product when the cashier has actually said
 * which one:
 *
 *   - they arrowed onto a row, which is a deliberate highlight; or
 *   - what they typed IS a product's full barcode or SKU, which is what a
 *     handheld scanner sends (the whole code, then Enter). A human typing a
 *     name never trips this, because a name is not an identifier.
 *
 * Anything else — a partial name, an ambiguous match, a lone result nobody
 * pointed at — returns null, and the list just stays open waiting to be
 * tapped.
 */
export function selectionForEnter({ results = [], activeIndex = -1, query = '' } = {}) {
    if (activeIndex >= 0 && activeIndex < results.length) {
        return results[activeIndex];
    }

    const typed = query.trim().toLowerCase();

    if (typed === '') {
        return null;
    }

    return results.find((p) =>
        (p.barcode && String(p.barcode).toLowerCase() === typed) ||
        (p.sku && String(p.sku).toLowerCase() === typed)
    ) ?? null;
}
