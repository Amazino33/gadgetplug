import { useState, useRef, useEffect } from 'react';
import { useKeyboard } from '../hooks/useKeyboard';

export default function QuantityModal({ item, onConfirm, onClose }) {
    const [qty, setQty] = useState(String(item.qty));
    const inputRef = useRef(null);

    useEffect(() => { inputRef.current?.select(); }, []);

    const confirm = () => {
        const n = parseInt(qty, 10);
        if (!isNaN(n) && n >= 0) onConfirm(n);
    };

    useKeyboard({ Enter: confirm, Escape: onClose }, [qty], { allowInInput: true });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-80 mx-4 p-6">
                <h2 className="font-bold text-gray-800 mb-1">Change Quantity</h2>
                <p className="text-xs text-gray-400 mb-4 truncate">{item.name}</p>
                <input
                    ref={inputRef}
                    type="number"
                    value={qty}
                    min={0}
                    onChange={(e) => setQty(e.target.value)}
                    className="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-4xl font-bold text-gray-800 text-center focus:outline-none focus:border-[#068B03] mb-4"
                />
                <p className="text-xs text-gray-400 text-center mb-4">Set to 0 to remove the item</p>
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
