const FIELD_TAGS = ['INPUT', 'TEXTAREA', 'SELECT'];

/**
 * Whether a keystroke that landed on the page at large should be redirected
 * into the product search box.
 *
 * A till is a keyboard instrument. The cashier's hands are on the counter and
 * on the goods, not on a mouse, and every second spent clicking into the
 * search box before typing a product name is a second the queue waits. So any
 * ordinary character typed anywhere on the screen goes to the search box —
 * the same way a scanner's output does, which means a handheld scanner works
 * without anyone clicking into a field first either.
 *
 * It stays out of the way of everything else:
 *
 *   - a field already has focus (the quantity box, a customer's name, a
 *     discount) — those keystrokes belong to whoever asked for them
 *   - something is covering the till (a modal, a receipt, an error, a sale
 *     being recorded), where typing means something else entirely
 *   - the key is not a plain character: shortcuts, function keys, Tab,
 *     Enter, arrows and the rest keep working as they always did
 */
export function shouldRedirectTypingToSearch({
    key = '',
    ctrlKey = false,
    metaKey = false,
    altKey = false,
    activeTag = null,
    blocked = false,
} = {}) {
    if (blocked) return false;

    // A modifier means a command, not typing.
    if (ctrlKey || metaKey || altKey) return false;

    // Anything with a name rather than a character — Enter, Tab, F3, ArrowUp,
    // Escape, Backspace — is already spoken for.
    if (key.length !== 1) return false;

    if (FIELD_TAGS.includes((activeTag ?? '').toUpperCase())) return false;

    return true;
}
