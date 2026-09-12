import { useEffect, useRef } from 'react';

/**
 * An amount you can type or tap.
 *
 * Both, always — not one or the other. A counter tablet needs the big keys, and
 * a till with a real keyboard needs to be able to type a figure and hit Enter
 * without reaching for the screen. Making that a device guess would be wrong
 * half the time on the same counter, since the busiest shops run a keyboard
 * beside a touchscreen.
 *
 * Shared by the float entry, both cash-up counts and the drawer payout, because
 * a cashier counting money should meet the same thing every time — and because
 * four copies of a keypad is four places for the decimal rule to drift apart.
 */
export default function AmountKeypad({
    value,
    onChange,
    autoFocusLabel,
    onSubmit,
    autoFocus = true,
}) {
    const inputRef = useRef(null);

    useEffect(() => {
        if (autoFocus) inputRef.current?.focus();
    }, [autoFocus]);

    /** Digits and at most one decimal point, at most two places after it. */
    const clean = (raw) => {
        const stripped = String(raw).replace(/[^\d.]/g, '');
        const [whole, ...rest] = stripped.split('.');
        const decimals = rest.join('').slice(0, 2);

        // Leading zeros go, so typing over a zero reads as the number meant
        // rather than "0500".
        const head = whole.replace(/^0+(?=\d)/, '');

        return rest.length > 0 ? `${head}.${decimals}` : head;
    };

    const commit = (next) => {
        onChange(next);

        // The caret goes to the end after every change. Separators shift
        // everything right as the number grows, and an amount is typed
        // left-to-right, so the end is where the next digit belongs.
        requestAnimationFrame(() => {
            const el = inputRef.current;
            if (el) el.selectionStart = el.selectionEnd = el.value.length;
        });
    };

    const press = (key) => {
        if (key === 'back') {
            commit(value.slice(0, -1));
        } else if (key === '.') {
            if (!value.includes('.')) commit(value === '' ? '0.' : `${value}.`);
        } else {
            commit(clean(value + key));
        }

        // Straight back to the field, so a cashier who taps a key can carry on
        // typing the rest.
        inputRef.current?.focus();
    };

    return (
        <div>
            <div className="flex items-center gap-1 rounded-xl border-2 border-gray-200 bg-white px-4 py-2.5 focus-within:border-[#068B03] dark:border-gray-700 dark:bg-gray-900 dark:focus-within:border-[#068B03]">
                <span className="text-2xl font-extrabold text-gray-400 dark:text-gray-500">₦</span>
                <input
                    ref={inputRef}
                    // Not type="number": it rejects a stray character silently,
                    // shows spinner arrows nobody wants on a till, and on
                    // Android offers a keyboard with no decimal point.
                    type="text"
                    inputMode="decimal"
                    autoComplete="off"
                    aria-label={autoFocusLabel ?? 'Amount'}
                    value={withSeparators(value)}
                    onChange={(e) => commit(clean(e.target.value))}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && onSubmit) {
                            e.preventDefault();
                            onSubmit();
                        }
                    }}
                    placeholder="0.00"
                    className="w-full bg-transparent text-center text-2xl font-extrabold tabular-nums text-gray-900 outline-none placeholder:text-gray-300 [@media(min-height:760px)]:text-3xl dark:text-gray-50 dark:placeholder:text-gray-600"
                />
            </div>

            {/* Keys grow only when there is HEIGHT for them, not width. A
                1366x768 laptop is wide and short — the common till — so a width
                breakpoint would hand it the tall keys that push the primary
                button off the bottom, which is the exact problem. */}
            <div className="mt-3 grid grid-cols-3 gap-1.5 [@media(min-height:760px)]:gap-2">
                {['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0', 'back'].map((key) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => press(key)}
                        // Kept off the tab order: somebody tabbing through the
                        // form wants the next field, not twelve digits.
                        tabIndex={-1}
                        aria-label={key === 'back' ? 'Delete' : key}
                        className="h-11 rounded-xl border border-gray-200 bg-white text-lg font-semibold text-gray-800 transition-all active:scale-95 [@media(min-height:760px)]:h-14 [@media(min-height:760px)]:text-xl dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    >
                        {key === 'back' ? '⌫' : key}
                    </button>
                ))}
            </div>
        </div>
    );
}

/**
 * Thousand separators as the figure grows.
 *
 * Applied to the whole part only, and never to what is being typed after the
 * decimal point — reformatting "20000.5" to two places while somebody is still
 * typing would fight them for the field.
 */
function withSeparators(raw) {
    if (raw === '' || raw == null) return '';

    const [whole, decimals] = String(raw).split('.');
    const grouped = (whole || '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return decimals === undefined ? grouped : `${grouped}.${decimals}`;
}
