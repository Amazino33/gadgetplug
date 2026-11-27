/**
 * The counting keypad: big keys for a thumb, so the phone's own keyboard
 * never opens (it is slow to open, covers half the screen and moves the
 * layout every time).
 *
 *   1 2 3 ⌫
 *   4 5 6 C
 *   7 8 9 Save ✓
 *   ½ 0 0     (½ only where the shop counts halves; else a wide 0)
 *
 * Keys don't take focus, so on a desktop the barcode box stays ready for the
 * hand-held scanner.
 */
export default function Keypad({ allowHalves, onKey, onSave, canSave, saveLabel = 'Save ✓' }) {
    const keep = (e) => e.preventDefault();
    const key = 'h-full min-h-11 [@media(max-height:620px)]:min-h-10 touch-manipulation rounded-2xl bg-white text-2xl font-extrabold text-gray-900 shadow-sm ring-1 ring-gray-200 active:bg-gray-200 dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:active:bg-gray-700';
    const digit = (d) => (
        <button key={d} type="button" onMouseDown={keep} onClick={() => onKey(d)} className={key}>{d}</button>
    );

    return (
        <div className="grid h-full max-h-[300px] touch-manipulation grid-cols-4 grid-rows-[repeat(4,minmax(2.75rem,1fr))] [@media(max-height:620px)]:grid-rows-[repeat(4,minmax(2.5rem,1fr))] gap-1.5 [@media(max-height:620px)]:gap-1" aria-label="Keypad">
            {digit('1')}{digit('2')}{digit('3')}
            <button type="button" onMouseDown={keep} onClick={() => onKey('back')} aria-label="Delete" className={`${key} text-xl`}>⌫</button>

            {digit('4')}{digit('5')}{digit('6')}
            <button type="button" onMouseDown={keep} onClick={() => onKey('clear')} aria-label="Clear" className={`${key} text-lg text-gray-500`}>C</button>

            {digit('7')}{digit('8')}{digit('9')}
            <button
                type="button"
                onMouseDown={keep}
                onClick={onSave}
                disabled={!canSave}
                className="row-span-2 rounded-2xl bg-green-600 text-lg font-extrabold leading-tight text-white shadow-sm active:bg-green-700 disabled:bg-green-600/35"
            >
                {saveLabel}
            </button>

            {allowHalves && (
                <button type="button" onMouseDown={keep} onClick={() => onKey('½')} className={key}>½</button>
            )}
            <button type="button" onMouseDown={keep} onClick={() => onKey('0')} className={`${key} ${allowHalves ? 'col-span-2' : 'col-span-3'}`}>0</button>
        </div>
    );
}
