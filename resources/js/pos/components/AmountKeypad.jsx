import { fmt } from '../lib/format';

/**
 * A big amount and the keys to build it.
 *
 * Shared by the float entry and both cash-up counts, because a cashier counting
 * money should meet the same thing every time — and because three copies of a
 * keypad is three places for the decimal-point rule to drift apart.
 *
 * Touch first: these run on cheap Android tablets where a native number input
 * raises a keyboard that covers half the screen and offers its own separate
 * delete key.
 */
export default function AmountKeypad({ value, onChange, autoFocusLabel }) {
    const press = (key) => {
        if (key === 'back') return onChange(value.slice(0, -1));
        if (key === '.' && value.includes('.')) return;

        // Two decimal places is as fine as money gets here.
        if (value.includes('.') && value.split('.')[1]?.length >= 2 && key !== '.') return;

        onChange((value + key).replace(/^0(?=\d)/, ''));
    };

    return (
        <div>
            <div
                className="rounded-2xl border-2 border-gray-200 bg-white px-4 py-5 text-center dark:border-gray-700 dark:bg-gray-900"
                aria-live="polite"
                aria-label={autoFocusLabel}
            >
                <p className="text-3xl font-extrabold tabular-nums text-gray-900 dark:text-gray-50">
                    {value === '' ? fmt(0) : fmt(Number(value) || 0)}
                </p>
            </div>

            <div className="mt-5 grid grid-cols-3 gap-2">
                {['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0', 'back'].map((key) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => press(key)}
                        aria-label={key === 'back' ? 'Delete' : key}
                        className="h-14 rounded-xl border border-gray-200 bg-white text-xl font-semibold text-gray-800 transition-all active:scale-95 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    >
                        {key === 'back' ? '⌫' : key}
                    </button>
                ))}
            </div>
        </div>
    );
}
