import { useState, useRef, useEffect } from 'react';
import { useKeyboard } from '../hooks/useKeyboard';
import { fmt } from '../lib/format';

// Negotiate a line price at the till. The floor shown here comes from the
// product feed and is only ever the lowest allowed price — the cost it was
// derived from stays on the server. This check is a courtesy to the cashier so
// they find out before the customer does; the server re-checks every sale and
// is what actually holds the line.
export default function PriceModal({ item, onConfirm, onClose }) {
    const listPrice = item.listPrice ?? item.price;
    const floor     = item.min_price ?? listPrice;

    const [price, setPrice] = useState(String(item.price));
    const inputRef = useRef(null);

    useEffect(() => { inputRef.current?.select(); }, []);

    const parsed = parseFloat(price);
    const tooLow = !isNaN(parsed) && parsed < floor;
    const valid  = !isNaN(parsed) && parsed >= floor;
    const off    = valid ? listPrice - parsed : 0;

    const confirm = () => { if (valid) onConfirm(parsed); };

    useKeyboard({ Enter: confirm, Escape: onClose }, [price], { allowInInput: true });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-80 mx-4 p-6">
                <h2 className="font-bold text-gray-800 mb-1">Negotiate Price</h2>
                <p className="text-xs text-gray-400 mb-4 truncate">{item.name}</p>

                <input
                    ref={inputRef}
                    type="number"
                    inputMode="decimal"
                    value={price}
                    min={floor}
                    step="0.01"
                    onChange={(e) => setPrice(e.target.value)}
                    className={`w-full border-2 rounded-xl px-4 py-3 text-3xl font-bold text-center focus:outline-none mb-3 ${
                        tooLow
                            ? 'border-red-400 text-red-600 focus:border-red-500'
                            : 'border-gray-200 text-gray-800 focus:border-[#068B03]'
                    }`}
                />

                <div className="flex justify-between text-xs text-gray-400 mb-1">
                    <span>Normal price</span>
                    <span className="font-semibold text-gray-600">{fmt(listPrice)}</span>
                </div>
                <div className="flex justify-between text-xs text-gray-400 mb-4">
                    <span>Lowest you can go</span>
                    <span className="font-semibold text-gray-600">{fmt(floor)}</span>
                </div>

                {tooLow && (
                    <p className="text-xs text-red-600 font-semibold text-center mb-4">
                        Too low — {fmt(floor)} is the minimum on this product.
                    </p>
                )}

                {valid && off > 0 && (
                    <p className="text-xs text-[#068B03] font-semibold text-center mb-4">
                        {fmt(off)} off for the customer
                    </p>
                )}

                <div className="flex gap-3">
                    <button onClick={onClose}
                        className="flex-1 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold text-gray-500">
                        Cancel
                    </button>
                    <button onClick={confirm}
                        disabled={!valid}
                        className="flex-1 py-2.5 rounded-xl bg-[#068B03] text-white text-sm font-bold hover:bg-[#057002] disabled:opacity-40 disabled:cursor-not-allowed">
                        Set [Enter]
                    </button>
                </div>
            </div>
        </div>
    );
}
