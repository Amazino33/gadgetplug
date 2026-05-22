import { useState, useEffect, useRef } from 'react';
import { useKeyboard } from '../hooks/useKeyboard';
import { fmt } from '../lib/format';

const METHODS = [
    { key: 'cash',          label: 'Cash',          color: '#068B03' },
    { key: 'card',          label: 'POS / Card',    color: '#3B82F6' },
    { key: 'bank_transfer', label: 'Bank Transfer', color: '#8B5CF6' },
];

export default function PaymentModal({ total, cart, customer, subtotal, discountAmount, vatAmount, onComplete, onClose }) {
    const [method, setMethod]         = useState('cash');
    const [tendered, setTendered]     = useState('');
    const [bankRef, setBankRef]       = useState('');
    const [loading, setLoading]       = useState(false);
    const tenderedRef                 = useRef(null);

    useEffect(() => { tenderedRef.current?.focus(); }, []);

    const tenderedNum = parseFloat(tendered) || 0;
    const change      = Math.max(0, tenderedNum - total);

    const canComplete = method !== 'cash'
        ? (method === 'bank_transfer' ? bankRef.length >= 4 : true)
        : tenderedNum >= total;

    const complete = async () => {
        if (!canComplete || loading) return;
        setLoading(true);
        try {
            await onComplete({
                paymentMethod:  method,
                amountTendered: method === 'cash' ? tenderedNum : total,
                bankRef:        method === 'bank_transfer' ? bankRef : null,
            });
        } finally {
            setLoading(false);
        }
    };

    useKeyboard({ Enter: complete, Escape: onClose }, [method, tendered, bankRef, canComplete, loading], { allowInInput: true });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">

                {/* Header */}
                <div className="bg-[#068B03] px-6 py-5">
                    <p className="text-white text-sm font-medium opacity-80">Total Due</p>
                    <p className="text-white text-5xl font-extrabold" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                        {fmt(total)}
                    </p>
                </div>

                <div className="p-6 space-y-5">
                    {/* Breakdown */}
                    <div className="text-xs text-gray-400 space-y-1">
                        <div className="flex justify-between"><span>Subtotal</span><span>{fmt(subtotal)}</span></div>
                        {discountAmount > 0 && <div className="flex justify-between text-[#F97316]"><span>Discount</span><span>−{fmt(discountAmount)}</span></div>}
                        <div className="flex justify-between"><span>VAT (7.5%)</span><span>{fmt(vatAmount)}</span></div>
                    </div>

                    {/* Payment method toggle */}
                    <div className="flex gap-2">
                        {METHODS.map((m) => (
                            <button
                                key={m.key}
                                onClick={() => { setMethod(m.key); setTendered(''); setBankRef(''); setTimeout(() => tenderedRef.current?.focus(), 50); }}
                                className="flex-1 py-2.5 rounded-xl text-xs font-bold border-2 transition-all"
                                style={method === m.key
                                    ? { borderColor: m.color, background: m.color, color: '#fff' }
                                    : { borderColor: '#e5e7eb', color: '#6b7280' }}
                            >
                                {m.label}
                            </button>
                        ))}
                    </div>

                    {/* Cash: amount tendered */}
                    {method === 'cash' && (
                        <div>
                            <label className="text-xs font-semibold text-gray-500 mb-1 block">Amount Tendered</label>
                            <input
                                ref={tenderedRef}
                                type="number"
                                value={tendered}
                                onChange={(e) => setTendered(e.target.value)}
                                placeholder={fmt(total)}
                                className="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-2xl font-bold text-gray-800 text-center focus:outline-none focus:border-[#068B03]"
                            />
                            {tenderedNum >= total && (
                                <div className="mt-3 bg-green-50 rounded-xl px-4 py-3 text-center">
                                    <p className="text-xs text-gray-400">Change Due</p>
                                    <p className="text-3xl font-extrabold text-[#068B03]" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                                        {fmt(change)}
                                    </p>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Bank transfer: reference field */}
                    {method === 'bank_transfer' && (
                        <div>
                            <label className="text-xs font-semibold text-gray-500 mb-1 block">
                                Transfer Reference (last 4+ digits)
                            </label>
                            <input
                                ref={tenderedRef}
                                type="text"
                                value={bankRef}
                                onChange={(e) => setBankRef(e.target.value)}
                                placeholder="e.g. 4821"
                                maxLength={20}
                                className="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-xl font-bold text-gray-800 text-center focus:outline-none focus:border-purple-500"
                            />
                        </div>
                    )}

                    {/* Card: just confirm */}
                    {method === 'card' && (
                        <div className="bg-blue-50 rounded-xl px-4 py-4 text-center">
                            <p className="text-sm font-semibold text-blue-700">Charge {fmt(total)} on POS terminal</p>
                            <p className="text-xs text-blue-400 mt-1">Press Enter once payment is approved</p>
                        </div>
                    )}

                    {/* Customer info */}
                    {customer && (
                        <div className="text-xs text-gray-400 bg-gray-50 rounded-lg px-3 py-2">
                            Receipt for: <span className="font-semibold text-gray-600">{customer.name}</span>
                            {customer.phone && <span> · {customer.phone}</span>}
                        </div>
                    )}

                    {/* Actions */}
                    <div className="flex gap-3">
                        <button
                            onClick={onClose}
                            className="flex-1 py-3 rounded-xl border border-gray-200 text-sm font-semibold text-gray-500 hover:bg-gray-50"
                        >
                            Cancel (Esc)
                        </button>
                        <button
                            onClick={complete}
                            disabled={!canComplete || loading}
                            className={`flex-1 py-3 rounded-xl text-sm font-bold text-white transition-all
                                ${canComplete && !loading ? 'bg-[#068B03] hover:bg-[#057002] active:scale-95' : 'bg-gray-200 text-gray-400 cursor-not-allowed'}`}
                        >
                            {loading ? 'Processing…' : 'Complete [Enter]'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
