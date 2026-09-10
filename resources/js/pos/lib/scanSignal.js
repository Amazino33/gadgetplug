/**
 * Tells a barcode scan apart from a cashier typing.
 *
 * This is deliberately narrow, because the permissive version of it was
 * already shipped and reverted — see searchSelection.js. That rule took any
 * result whose full barcode *or SKU* had been typed, and imports have left
 * real products sitting on SKUs like "1" and "5", so a single keypress rang
 * up goods. Nothing here ever looks at a SKU.
 *
 * What is left is safe for a different reason than "it is probably a scan":
 * a barcode is unique per vendor at the database level (see the
 * add_unique_product_identifiers migration), so text that exactly equals one
 * identifies exactly one product and cannot mean anything else. A cashier who
 * types thirteen digits that happen to be a barcode wants that product just as
 * much as a scanner does.
 *
 * Two independent signals, because each covers the other's blind spot:
 *
 *   - the shape of the text (digits, long enough to be a real symbology).
 *     Catches the scanners that deliver their payload in one paste-like input
 *     event, where there are no keystroke gaps to measure.
 *   - the speed it arrived at. Catches the alphanumeric Code 39 labels that
 *     the shape test refuses, because no human types eight characters at
 *     15ms intervals.
 *
 * Either one, plus an exact barcode match, is a scan. Neither one on its own
 * adds anything to a sale.
 */

// EAN-8 is the shortest symbology anything on this counter is printed with,
// so eight is the floor. It exists to keep short numeric SKUs — the "5" that
// caused the original incident — from ever reaching this path, not because
// seven-digit barcodes would otherwise be mishandled.
export const MIN_BARCODE_LENGTH = 8;

/**
 * Whether the search text is shaped like a barcode at all.
 *
 * Exported separately because the search box needs the distinction between
 * "this was not a barcode" (fall through to the ordinary pick rules, say
 * nothing) and "this was a barcode and no product carries it" (say so, and
 * do not open the quantity box).
 */
export function looksLikeBarcode(query = '') {
    const trimmed = String(query).trim();

    return trimmed.length >= MIN_BARCODE_LENGTH && /^[0-9]+$/.test(trimmed);
}

/**
 * The product a scan resolves to, or null if this was not a scan.
 *
 * `results` is whatever the search box is currently showing for this exact
 * text — the caller is responsible for making sure it is not still the
 * answer to a half-typed query, because a scanner beats the search debounce
 * to the Enter key every time.
 */
export function scanFor({ query = '', results = [], burst = false } = {}) {
    const trimmed = String(query).trim();

    if (! trimmed) return null;
    if (! looksLikeBarcode(trimmed) && ! burst) return null;

    const carrying = results.filter(
        (p) => p?.barcode != null && String(p.barcode) === trimmed
    );

    // Exactly one, never "the first one". Two products claiming one barcode
    // is something the unique index forbids, so if it somehow happens the
    // till has no business guessing which of them the customer is holding.
    return carrying.length === 1 ? carrying[0] : null;
}

// A handheld wedge emits characters far faster than fingers can. Measured on
// the counter's own scanner these land 10-20ms apart; the quickest human
// typing here sits above 80ms, so 40 leaves room on both sides.
export const BURST_MAX_GAP_MS   = 40;

// Long enough that a fast double-tap, or a two-key correction after a pause,
// cannot look like a machine.
export const BURST_MIN_CHARS    = 6;

// The scanner's own Enter follows its last character immediately. A cashier
// who types quickly and then stops to look at the screen before pressing
// Enter has made a decision, and is not scanning.
export const BURST_ENTER_GAP_MS = 120;

/**
 * Watches the rhythm of characters arriving in the search box.
 *
 * Times are passed in rather than read from the clock so this can be tested
 * as arithmetic instead of as a race.
 */
export function createBurstTracker({
    maxGapMs   = BURST_MAX_GAP_MS,
    minChars   = BURST_MIN_CHARS,
    enterGapMs = BURST_ENTER_GAP_MS,
} = {}) {
    let runLength = 0;
    let lastAt    = null;

    return {
        record(at) {
            // A gap breaks the run rather than ending the measurement: the
            // cashier who pauses mid-word and then types the rest quickly is
            // starting a new run, not continuing a machine's.
            runLength = (lastAt === null || at - lastAt > maxGapMs) ? 1 : runLength + 1;
            lastAt    = at;
        },

        reset() {
            runLength = 0;
            lastAt    = null;
        },

        isBurst(at) {
            if (lastAt === null) return false;

            return runLength >= minChars && (at - lastAt) <= enterGapMs;
        },
    };
}
