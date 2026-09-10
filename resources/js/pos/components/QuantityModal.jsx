import { useState, useRef, useEffect } from 'react';
import { useKeyboard } from '../hooks/useKeyboard';

// Two jobs, told apart by the wording rather than by different components:
// changing the quantity of a line already in the cart (the default), and
// saying how many of a just-picked product to add. The mechanics are the
// same — open holding the keyboard, type over the number, Enter — and the
// caller decides what the number then means.
export default function QuantityModal({
    item,
    onConfirm,
    onClose,
    onNegotiate,
    title = 'Change Quantity',
    hint  = 'Type the quantity and press Enter · 0 removes the item',
}) {
    const [qty, setQty] = useState(String(item.qty));
    const inputRef = useRef(null);

    // Opens holding the keyboard with the current quantity selected, so the
    // cashier types the number they want straight over it and presses Enter.
    // No click, no clearing the box first.
    //
    // focus() is explicit rather than relying on select() to bring it along:
    // select() alone leaves the caret wherever it was on some browsers, and
    // on <input type="number"> it is ignored outright — which is why this is
    // a text input with a numeric keypad hint instead.
    useEffect(() => {
        const el = inputRef.current;

        if (! el) return;

        el.focus();
        el.select();
    }, []);

    // An emptied box means "leave it as it was" — the alternative is Enter
    // doing nothing at all, which reads as the till being stuck.
    const typedQty = () => (qty.trim() === '' ? item.qty : parseInt(qty, 10));

    const confirm = () => {
        const n = typedQty();

        if (!isNaN(n) && n >= 0) onConfirm(n);
    };

    // Carries the quantity out with it. Negotiating from here used to lose
    // whatever had been typed, because the product was already sitting in the
    // cart at qty 1 and the price modal took over from there — it isn't any
    // more (POS.jsx adds it on confirm, so Escape can mean nothing happened),
    // so the caller needs the number to add the line it is about to reprice.
    const negotiate = () => {
        const n = typedQty();

        onNegotiate(!isNaN(n) && n >= 1 ? n : 1);
    };

    useKeyboard({ Enter: confirm, Escape: onClose }, [qty], { allowInInput: true });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-80 mx-4 p-6">
                <h2 className="font-bold text-gray-800 mb-1">{title}</h2>
                <p className="text-xs text-gray-400 mb-4 truncate">{item.name}</p>
                <input
                    ref={inputRef}
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9]*"
                    autoComplete="off"
                    aria-label="Quantity"
                    value={qty}
                    // Digits only: a text input is what makes select() reliable,
                    // so it has to refuse the "e", "+" and "-" that a number
                    // input would have allowed through on its own.
                    onChange={(e) => setQty(e.target.value.replace(/[^0-9]/g, ''))}
                    className="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-4xl font-bold text-gray-800 text-center focus:outline-none focus:border-[#068B03] mb-4"
                />
                <p className="text-xs text-gray-400 text-center mb-4">{hint}</p>

                {item.can_negotiate && onNegotiate && (
                    <button
                        onClick={negotiate}
                        className="w-full mb-4 py-2.5 rounded-xl border border-[#068B03] text-sm font-semibold text-[#068B03] hover:bg-green-50"
                    >
                        Negotiate price
                    </button>
                )}
                <div className="flex gap-3">
                    <button onClick={onClose}
                        className="flex-1 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold text-gray-500">
                        Cancel
                    </button>
                    <button onClick={confirm}
                        className="flex-1 py-2.5 rounded-xl bg-[#068B03] text-white text-sm font-bold hover:bg-[#057002]">
                        Set [Enter]
                    </button>
                </div>
            </div>
        </div>
    );
}
