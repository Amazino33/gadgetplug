import { useState, useEffect } from 'react';
import api from '../lib/api';

export default function SuspendTray({ vendorId, cart, customer, onSuspend, onResume, onClose }) {
    const [slots, setSlots]     = useState([null, null, null]);
    const [label, setLabel]     = useState('');
    const [loading, setLoading] = useState(false);

    useEffect(() => { loadSlots(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const loadSlots = async () => {
        try {
            const { data } = await api.get('/suspended', { params: { vendor_id: vendorId } });
            const arr = [null, null, null];
            data.forEach((s) => { arr[s.slot - 1] = s; });
            setSlots(arr);
        } catch { /* offline */ }
    };

    const suspend = async (slot) => {
        setLoading(true);
        try {
            await api.post('/suspended', {
                vendor_id:   vendorId,
                slot,
                label:       label || `Hold ${slot}`,
                customer_id: customer?.id ?? null,
                cart_data:   { items: cart, customer },
            });
            onSuspend();
        } catch { /* handle */ } finally {
            setLoading(false);
        }
    };

    const resume = async (slot) => {
        try {
            const { data } = await api.post(`/suspended/${slot}/resume`, { vendor_id: vendorId });
            onResume(data);
        } catch { /* handle */ }
    };

    const clear = async (slot) => {
        await api.delete(`/suspended/${slot}`, { data: { vendor_id: vendorId } });
        loadSlots();
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
                <div className="flex items-center justify-between mb-5">
                    <h2 className="font-bold text-gray-800">Suspend / Resume Sale</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                {/* Existing holds */}
                <div className="space-y-2 mb-5">
                    {slots.map((slot, idx) => (
                        <div key={idx}
                            className={`flex items-center gap-3 px-4 py-3 rounded-xl border-2 ${slot ? 'border-orange-200 bg-orange-50' : 'border-gray-100 bg-gray-50'}`}>
                            <span className={`text-sm font-bold w-6 h-6 rounded-full flex items-center justify-center shrink-0
                                ${slot ? 'bg-[#F97316] text-white' : 'bg-gray-200 text-gray-400'}`}>
                                {idx + 1}
                            </span>
                            {slot ? (
                                <>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-semibold text-gray-700 truncate">{slot.label}</p>
                                        {slot.customer && <p className="text-xs text-gray-400">{slot.customer.name}</p>}
                                        <p className="text-xs text-gray-400">{slot.cart_data?.items?.length ?? 0} items</p>
                                    </div>
                                    <button onClick={() => resume(idx + 1)}
                                        className="text-xs font-bold bg-[#068B03] text-white px-3 py-1.5 rounded-lg hover:bg-[#057002]">
                                        Resume
                                    </button>
                                    <button onClick={() => clear(idx + 1)}
                                        className="text-xs text-red-400 hover:text-red-600">✕</button>
                                </>
                            ) : (
                                <p className="text-sm text-gray-400">Empty slot</p>
                            )}
                        </div>
                    ))}
                </div>

                {/* Suspend current cart */}
                {cart.length > 0 && (
                    <div className="border-t border-gray-100 pt-4">
                        <p className="text-xs font-semibold text-gray-500 mb-2">Suspend Current Cart ({cart.length} items)</p>
                        <input
                            type="text"
                            value={label}
                            onChange={(e) => setLabel(e.target.value)}
                            placeholder="Label (optional)"
                            className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mb-3 focus:outline-none focus:border-[#F97316]"
                        />
                        <div className="flex gap-2">
                            {slots.map((s, idx) => (
                                <button
                                    key={idx}
                                    onClick={() => !loading && suspend(idx + 1)}
                                    disabled={loading}
                                    className={`flex-1 py-2.5 rounded-xl text-sm font-bold border-2 transition-all
                                        ${s
                                            ? 'border-orange-200 bg-orange-50 text-orange-600 hover:bg-orange-100'
                                            : 'border-[#F97316] bg-[#F97316] text-white hover:bg-[#ea6c0a]'
                                        }`}
                                >
                                    {loading ? '…' : `Slot ${idx + 1}`}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
