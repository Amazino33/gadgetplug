/**
 * Decides what — if anything — pressing Enter in the product search adds to
 * the cart, and which row is shown highlighted while the cashier decides.
 *
 * Those are one question, not two. They were two once: the list highlighted
 * whatever the arrow keys had reached, and Enter re-derived its own answer
 * from the same state. Anything that made the two disagree — the server's
 * results landing and replacing the list a tick before Enter — meant the
 * cashier bought a product other than the one they could see highlighted.
 * So highlightIndexFor is the single authority, selectionForEnter reads it,
 * and the list renders it. What is highlighted is what Enter takes.
 *
 * ── Why Enter is fussy about this at all ──
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
 * ── autoHighlight, and why it is not the first rule coming back ──
 *
 * The counter's desktop till is a keyboard instrument with a real keyboard,
 * and needing an arrow key to reach the row already sitting at the top of
 * the list costs a keystroke on every single sale. So the first row can be
 * highlighted on its own — but only where the incident above cannot repeat.
 *
 * The incident was a touchscreen one. A phone's on-screen keyboard has no
 * Enter, it has Go, and Go is how you dismiss the keyboard; the cashier was
 * not committing anything, they were putting the keyboard away. A hardware
 * keyboard has no such gesture — Enter there is only ever Enter.
 *
 * So this is off by default and POS turns it on for the desktop search bar
 * only. The mobile bar keeps the deliberate-pick rule it has always had.
 * On top of that, a highlight needs MIN_AUTO_HIGHLIGHT_CHARS of text behind
 * it, so the single digit that matched a real SKU still resolves to nothing.
 */

// Three, because the SKU incident was one character and the shortest thing
// worth searching a product catalogue for is longer than two. A cashier who
// really wants a two-character match still gets there with one arrow press.
export const MIN_AUTO_HIGHLIGHT_CHARS = 3;

/**
 * The row that is highlighted: whatever the arrows reached, else the first
 * result when this bar is allowed to highlight one, else nothing.
 */
export function highlightIndexFor({
    results = [],
    activeIndex = -1,
    autoHighlight = false,
    query = '',
} = {}) {
    // An arrowed-to row wins, but only while it still points at something.
    // The list shrinks under the cashier whenever a newer search lands.
    if (activeIndex >= 0 && activeIndex < results.length) return activeIndex;

    if (! autoHighlight) return -1;
    if (results.length === 0) return -1;
    if (String(query).trim().length < MIN_AUTO_HIGHLIGHT_CHARS) return -1;

    return 0;
}

export function selectionForEnter(state = {}) {
    const index = highlightIndexFor(state);

    return index >= 0 ? (state.results ?? [])[index] ?? null : null;
}
