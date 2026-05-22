import { useState, useRef, useEffect } from 'react';
import { fmt } from '../lib/format';
import api from '../lib/api';

export default function ReturnModal({ vendorId, onClose }) {
    const [step, setStep]           = useState('lookup'); // 'lookup' | 'select' | 'confirm'
    const [ref, setRef]             = useState('');
    const [sale, setSale]           = useState(null);
    const [selected, setSelected]   = useState({});
    const [method, setMethod]       = useState('cash');
    const [reason, setReason]       = useState('');
    const [loading, setLoading]     = useState(false);
    const [error, setError]         = useState('');
    const refInput                  = useRef(null);

    useEffect(() => { refInput.current?.focus(); }, []);

    const lookup = async () => {
        setLoading(true);
        setError('');
        try {
            const { data } = await api.get(`/sales/${ref}/by-ref`, { params: { vendor_id: vendorId } });
            setSale(data);
            setStep('select');
            // default all items qty to 0 (none selected)
            const init = {};
            data.items.forEach((i) => { init[i.product_id] = 0; });
            setSelected(init);
        } catch {
            setError('Sale not found. Check the reference.');
        } finally {
            setLoading(false);
        }
    };

    const refundTotal = sale
        ? sale.items.reduce((sum, item) => {
            const qty = selected[item.product_id] || 0;
            return sum + qty * item.unit_price;
        }, 0)
        : 0;

    const submitReturn = async () => {
        const returnItems = Object.entries(selected)
            .filter(([, qty]) => qty > 0)
            .map(([product_id, quantity]) => ({ product_id: Number(product_id), quantity }));

        if (returnItems.length === 0) return;
        setLoading(true);
        try {
            await api.post(`/sales/${sale.id}/return`, {
                items:         returnItems,
                refund_method: method,
                reason,
            });
            onClose();
        } catch { /* handle */ } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4">
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <h2 className="font-bold text-gray-800">Process Return</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                <div className="p-6">
                    {step === 'lookup' && (
                        <div>
                            <label className="text-xs font-semibold text-gray-500 mb-1 block">Sale Reference</label>
                            <div className="flex gap-2">
                                <input
                                    ref={refInput}
                                    type="text"
                                    value={ref}
                                    onChange={(e) => setRef(e.target.value.toUpperCase())}
                                    onKeyDown={(e) => e.key === 'Enter' && lookup()}
                                    placeholder="e.g. POS-ABCD1234"
                                    className="flex-1 border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-[#068B03]"
                                />
                                <button onClick={lookup} disabled={!ref || loading}
                                    className="px-5 py-2.5 bg-gray-900 text-white text-sm font-semibold rounded-xl disabled:opacity-40">
                                    {loading ? '…' : 'Find'}
                                </button>
                            </div>
                            {error && <p className="text-xs text-red-500 mt-2">{error}</p>}
                        </div>
                    )}

                    {step === 'select' && sale && (
                        <div>
                            <div className="flex items-center justify-between mb-3">
                                <p className="text-sm font-semibold text-gray-700">{sale.reference}</p>
                                <button onClick={() => setStep('lookup')} className="text-xs text-gray-400 hover:text-gray-600">← Back</button>
                            </div>
                            <div className="space-y-2 max-h-64 overflow-y-auto mb-4">
                                {sale.items.map((item) => (
                                    <div key={item.product_id}
                                        className="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-xl">
                                        <div className="flex-1 min-w-0">
                                            <p className="text-sm font-medium text-gray-800 truncate">{item.product_name}</p>
                                            <p className="text-xs text-gray-400">{fmt(item.unit_price)} × {item.quantity} sold</p>
                                        </div>
                                        <div className="flex items-center gap-2 shrink-0">
                                            <button onClick={() => setSelected((s) => ({ ...s, [item.product_id]: Math.max(0, (s[item.product_id] || 0) - 1) }))}
                                                className="w-7 h-7 rounded-full bg-white border border-gray-200 text-gray-600 font-bold text-sm flex items-center justify-center">−</button>
                                            <span className="w-6 text-center text-sm font-semibold">{selected[item.product_id] || 0}</span>
                                            <button onClick={() => setSelected((s) => ({ ...s, [item.product_id]: Math.min(item.quantity, (s[item.product_id] || 0) + 1) }))}
                                                className="w-7 h-7 rounded-full bg-white border border-gray-200 text-gray-600 font-bold text-sm flex items-center justify-center">+</button>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="mb-3">
                                <label className="text-xs font-semibold text-gray-500 mb-1 block">Refund Method</label>
                                <div className="flex gap-2">
                                    {['cash', 'card', 'bank_transfer', 'store_credit'].map((m) => (
                                        <button key={m} onClick={() => setMethod(m)}
                                            className={`flex-1 py-2 rounded-lg text-xs font-bold border-2 transition-all
                                                ${method === m ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-500'}`}>
                                            {m === 'bank_transfer' ? 'Transfer' : m === 'store_credit' ? 'Credit' : m.charAt(0).toUpperCase() + m.slice(1)}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <input type="text" value={reason} onChange={(e) => setReason(e.target.value)}
                                placeholder="Reason (optional)"
                                className="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm mb-4 focus:outline-none focus:border-[#068B03]"
                            />

                            {refundTotal > 0 && (
                                <div className="bg-orange-50 rounded-xl px-4 py-3 mb-4 text-center">
                                    <p className="text-xs text-gray-400">Refund Amount</p>
                                    <p className="text-2xl font-extrabold text-[#F97316]">{fmt(refundTotal)}</p>
                                </div>
                            )}

                            <button onClick={submitReturn} disabled={refundTotal <= 0 || loading}
                                className="w-full py-3 bg-[#F97316] text-white rounded-xl font-bold text-sm disabled:opacity-40">
                                {loading ? 'Processing…' : 'Confirm Return'}
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
