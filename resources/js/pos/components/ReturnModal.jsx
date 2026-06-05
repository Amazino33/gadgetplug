import { useState, useRef, useEffect } from 'react';
import { fmt } from '../lib/format';
import api from '../lib/api';

export default function ReturnModal({ vendorId, onClose }) {
    const [step, setStep]         = useState('lookup');
    const [ref, setRef]           = useState('');
    const [sale, setSale]         = useState(null);
    const [selected, setSelected] = useState({});
    const [method, setMethod]     = useState('cash');
    const [reason, setReason]     = useState('');
    const [loading, setLoading]   = useState(false);
    const [error, setError]       = useState('');
    const [success, setSuccess]   = useState('');
    const refInput                = useRef(null);

    useEffect(() => { refInput.current?.focus(); }, []);

    const lookup = async () => {
        if (!ref.trim()) return;
        setLoading(true);
        setError('');
        try {
            const { data } = await api.get(`/sales/${ref.trim()}/by-ref`, { params: { vendor_id: vendorId } });
            setSale(data);
            setStep('select');
            // default all returnable items to qty 0
            const init = {};
            data.items.forEach((i) => { init[i.product_id] = 0; });
            setSelected(init);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'Sale not found. Check the reference and try again.');
        } finally {
            setLoading(false);
        }
    };

    const adjust = (productId, delta, max) =>
        setSelected((s) => ({ ...s, [productId]: Math.min(max, Math.max(0, (s[productId] || 0) + delta)) }));

    const refundTotal = sale
        ? sale.items.reduce((sum, item) => sum + (selected[item.product_id] || 0) * item.unit_price, 0)
        : 0;

    const submitReturn = async () => {
        const returnItems = Object.entries(selected)
            .filter(([, qty]) => qty > 0)
            .map(([product_id, quantity]) => ({ product_id: Number(product_id), quantity }));

        if (returnItems.length === 0) return;

        setLoading(true);
        setError('');
        try {
            await api.post(`/sales/${sale.id}/return`, {
                items:         returnItems,
                refund_method: method,
                reason,
            });
            setSuccess(`Return processed. Refund of ${fmt(refundTotal)} via ${method.replace('_', ' ')}.`);
            setTimeout(onClose, 2000);
        } catch (err) {
            setError(err?.response?.data?.message ?? 'Return failed. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4">
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <h2 className="font-bold text-gray-800">Process Return</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-lg leading-none">✕</button>
                </div>

                <div className="p-6">

                    {/* Success banner */}
                    {success && (
                        <div className="mb-4 flex items-center gap-2.5 bg-green-50 border border-green-200 rounded-xl px-4 py-3">
                            <span className="text-green-600 text-lg">✓</span>
                            <p className="text-sm font-semibold text-green-700">{success}</p>
                        </div>
                    )}

                    {/* Shared error banner */}
                    {error && (
                        <div className="mb-4 flex items-center gap-2.5 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
                            <span className="text-red-500 text-lg">⚠</span>
                            <p className="text-sm font-medium text-red-600">{error}</p>
                        </div>
                    )}

                    {step === 'lookup' && (
                        <div>
                            <label className="text-xs font-semibold text-gray-500 mb-1.5 block">Sale Reference</label>
                            <div className="flex gap-2">
                                <input
                                    ref={refInput}
                                    type="text"
                                    value={ref}
                                    onChange={(e) => setRef(e.target.value.toUpperCase())}
                                    onKeyDown={(e) => e.key === 'Enter' && lookup()}
                                    placeholder="e.g. POS-ABCD1234"
                                    className="flex-1 border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-brand"
                                />
                                <button onClick={lookup} disabled={!ref.trim() || loading}
                                    className="px-5 py-2.5 bg-gray-900 text-white text-sm font-semibold rounded-xl disabled:opacity-40">
                                    {loading ? '…' : 'Find'}
                                </button>
                            </div>
                        </div>
                    )}

                    {step === 'select' && sale && (
                        <div>
                            <div className="flex items-center justify-between mb-3">
                                <div>
                                    <p className="text-sm font-bold text-gray-700">{sale.reference}</p>
                                    {sale.status === 'partial_refund' && (
                                        <p className="text-xs text-amber-600 font-medium mt-0.5">Partial return — showing remaining returnable items only</p>
                                    )}
                                </div>
                                <button onClick={() => { setStep('lookup'); setError(''); setSale(null); }}
                                    className="text-xs text-gray-400 hover:text-gray-600">← Back</button>
                            </div>

                            <div className="space-y-2 max-h-64 overflow-y-auto mb-4">
                                {sale.items.map((item) => (
                                    <div key={item.product_id}
                                        className="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-xl">
                                        <div className="flex-1 min-w-0">
                                            <p className="text-sm font-medium text-gray-800 truncate">{item.product_name}</p>
                                            <p className="text-xs text-gray-400">
                                                {fmt(item.unit_price)} ×{' '}
                                                {item.returnable} returnable
                                                {item.returned > 0 && ` (${item.returned} already returned)`}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2 shrink-0">
                                            <button onClick={() => adjust(item.product_id, -1, item.returnable)}
                                                className="w-7 h-7 rounded-full bg-white border border-gray-200 text-gray-600 font-bold text-sm flex items-center justify-center">−</button>
                                            <span className="w-6 text-center text-sm font-semibold">{selected[item.product_id] || 0}</span>
                                            <button onClick={() => adjust(item.product_id, +1, item.returnable)}
                                                className="w-7 h-7 rounded-full bg-white border border-gray-200 text-gray-600 font-bold text-sm flex items-center justify-center">+</button>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="mb-3">
                                <label className="text-xs font-semibold text-gray-500 mb-1.5 block">Refund Method</label>
                                <div className="grid grid-cols-4 gap-2">
                                    {['cash', 'card', 'bank_transfer', 'store_credit'].map((m) => (
                                        <button key={m} onClick={() => setMethod(m)}
                                            className={`py-2 rounded-lg text-xs font-bold border-2 transition-all
                                                ${method === m ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-500 hover:border-gray-400'}`}>
                                            {m === 'bank_transfer' ? 'Transfer' : m === 'store_credit' ? 'Credit' : m.charAt(0).toUpperCase() + m.slice(1)}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <input type="text" value={reason} onChange={(e) => setReason(e.target.value)}
                                placeholder="Reason (optional)"
                                className="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm mb-4 focus:outline-none focus:border-brand"
                            />

                            {refundTotal > 0 && (
                                <div className="bg-orange-50 rounded-xl px-4 py-3 mb-4 text-center">
                                    <p className="text-xs text-gray-400">Refund Amount</p>
                                    <p className="text-2xl font-extrabold text-brand-orange">{fmt(refundTotal)}</p>
                                </div>
                            )}

                            <button onClick={submitReturn} disabled={refundTotal <= 0 || loading || !!success}
                                className="w-full py-3 bg-brand-orange hover:bg-[#ea6c0a] text-white rounded-xl font-bold text-sm disabled:opacity-40 transition-colors">
                                {loading ? 'Processing…' : 'Confirm Return'}
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
