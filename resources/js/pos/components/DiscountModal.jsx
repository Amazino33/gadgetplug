import { useState, useRef, useEffect } from 'react';
import { fmt } from '../lib/format';
import api from '../lib/api';

export default function DiscountModal({ vendorId, subtotal, current, onApply, onClose }) {
    const [type, setType]           = useState(current.type || 'fixed');
    const [amount, setAmount]       = useState(current.amount || '');
    const [managerPin, setManagerPin] = useState('');
    const [approving, setApproving] = useState(false);
    const [error, setError]         = useState('');
    const amountRef                 = useRef(null);

    useEffect(() => { amountRef.current?.focus(); }, []);

    const discountValue = type === 'percentage'
        ? subtotal * ((parseFloat(amount) || 0) / 100)
        : parseFloat(amount) || 0;

    const afterDiscount = Math.max(0, subtotal - discountValue);

    const apply = async () => {
        if (!amount || discountValue <= 0) return;
        setApproving(true);
        setError('');
        try {
            const { data } = await api.post('/discounts/approve', {
                vendor_id:       vendorId,
                manager_pin:     managerPin,
                discount_amount: discountValue,
                discount_type:   type,
            });
            onApply({ amount: discountValue, type, approvedBy: data.approved_by });
        } catch {
            setError('Invalid manager PIN. Try again.');
        } finally {
            setApproving(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
                <div className="flex items-center justify-between mb-5">
                    <h2 className="font-bold text-gray-800">Apply Discount</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                {/* Type toggle */}
                <div className="flex gap-2 mb-4">
                    {['fixed', 'percentage'].map((t) => (
                        <button key={t} onClick={() => { setType(t); setAmount(''); }}
                            className={`flex-1 py-2 rounded-xl text-sm font-bold border-2 transition-all
                                ${type === t ? 'border-[#F97316] bg-[#F97316] text-white' : 'border-gray-200 text-gray-500'}`}>
                            {t === 'fixed' ? '₦ Fixed Amount' : '% Percentage'}
                        </button>
                    ))}
                </div>

                {/* Amount */}
                <div className="mb-4">
                    <label className="text-xs font-semibold text-gray-500 mb-1 block">
                        {type === 'fixed' ? 'Discount Amount (₦)' : 'Discount (%)'}
                    </label>
                    <input
                        ref={amountRef}
                        type="number"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        min={0}
                        max={type === 'percentage' ? 100 : subtotal}
                        className="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-2xl font-bold text-gray-800 text-center focus:outline-none focus:border-[#F97316]"
                    />
                </div>

                {/* Preview */}
                {discountValue > 0 && (
                    <div className="bg-orange-50 rounded-xl px-4 py-3 mb-4 text-center">
                        <p className="text-xs text-gray-400">After Discount</p>
                        <p className="text-2xl font-extrabold text-[#F97316]" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                            {fmt(afterDiscount)}
                        </p>
                        <p className="text-xs text-gray-400 mt-0.5">Saving {fmt(discountValue)}</p>
                    </div>
                )}

                {/* Manager PIN */}
                <div className="mb-4">
                    <label className="text-xs font-semibold text-gray-500 mb-1 block">Manager PIN (required)</label>
                    <input
                        type="password"
                        value={managerPin}
                        onChange={(e) => setManagerPin(e.target.value)}
                        placeholder="Enter manager PIN"
                        className="w-full border-2 border-gray-200 rounded-xl px-4 py-2.5 text-center text-lg tracking-widest focus:outline-none focus:border-[#F97316]"
                    />
                    {error && <p className="text-xs text-red-500 mt-1 text-center">{error}</p>}
                </div>

                <div className="flex gap-3">
                    <button onClick={onClose}
                        className="flex-1 py-3 rounded-xl border border-gray-200 text-sm font-semibold text-gray-500 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button
                        onClick={apply}
                        disabled={!amount || !managerPin || approving}
                        className="flex-1 py-3 rounded-xl bg-[#F97316] text-white text-sm font-bold disabled:opacity-40 hover:bg-[#ea6c0a]">
                        {approving ? 'Verifying…' : 'Apply Discount'}
                    </button>
                </div>
            </div>
        </div>
    );
}
