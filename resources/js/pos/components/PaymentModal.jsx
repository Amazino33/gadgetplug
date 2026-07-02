import { useState, useEffect, useRef } from 'react';
import { useKeyboard } from '../hooks/useKeyboard';
import { fmt } from '../lib/format';

const METHODS = [
    { key: 'cash',          label: 'Cash',          color: '#068B03' },
    { key: 'card',          label: 'POS / Card',    color: '#3B82F6' },
    { key: 'bank_transfer', label: 'Bank Transfer', color: '#8B5CF6' },
];

const DEFAULT_SPLITS = { cash: '', card: '', bank_transfer: '' };
const DEFAULT_REFS   = { cash: '', card: '', bank_transfer: '' };

const NUMPAD_KEYS = ['1','2','3','4','5','6','7','8','9','00','0','⌫'];

export default function PaymentModal({ total, cart, customer, subtotal, discountAmount, vatAmount, onComplete, onClose }) {
    const [mode, setMode]         = useState('single');
    const [method, setMethod]     = useState('cash');
    const [tendered, setTendered] = useState('');
    const [bankRef, setBankRef]   = useState('');
    const [splits, setSplits]     = useState(DEFAULT_SPLITS);
    const [refs, setRefs]         = useState(DEFAULT_REFS);
    const [loading, setLoading]   = useState(false);
    const tenderedRef             = useRef(null);

    useEffect(() => { tenderedRef.current?.focus(); }, []);

    // ── Single payment logic ────────────────────────────────────────────────
    const tenderedNum       = parseFloat(tendered) || 0;
    const singleChange      = Math.max(0, tenderedNum - total);
    const singleCanComplete = method !== 'cash'
        ? (method === 'bank_transfer' ? bankRef.length >= 4 : true)
        : tenderedNum >= total;

    // ── Split payment logic ─────────────────────────────────────────────────
    const splitAmounts = Object.fromEntries(
        Object.entries(splits).map(([k, v]) => [k, parseFloat(v) || 0])
    );
    const totalAllocated = Object.values(splitAmounts).reduce((a, b) => a + b, 0);
    const remaining      = Math.max(0, total - totalAllocated);
    const splitOverpaid  = Math.max(0, totalAllocated - total);
    const activeSplits   = METHODS.filter(m => splitAmounts[m.key] > 0);
    const splitCanComplete =
        remaining === 0 &&
        activeSplits.length >= 2 &&
        (splitAmounts.bank_transfer === 0 || refs.bank_transfer.length >= 4);

    const setSplit = (key, value) => setSplits(prev => ({ ...prev, [key]: value }));
    const setRef   = (key, value) => setRefs(prev => ({ ...prev, [key]: value }));

    const switchMode = (m) => {
        setMode(m);
        setSplits(DEFAULT_SPLITS);
        setRefs(DEFAULT_REFS);
        setTendered('');
        setBankRef('');
        setTimeout(() => tenderedRef.current?.focus(), 50);
    };

    const handleNumpad = (key) => {
        if (key === '⌫') {
            setTendered(t => t.slice(0, -1));
        } else {
            setTendered(t => {
                const next = t + key;
                return next.length <= 9 ? next : t;
            });
        }
    };

    // ── Complete ────────────────────────────────────────────────────────────
    const complete = async () => {
        if (loading) return;
        if (mode === 'single' && !singleCanComplete) return;
        if (mode === 'split'  && !splitCanComplete)  return;

        setLoading(true);
        try {
            if (mode === 'single') {
                await onComplete({
                    paymentMethod:  method,
                    amountTendered: method === 'cash' ? tenderedNum : total,
                    bankRef:        method === 'bank_transfer' ? bankRef : null,
                    payments:       null,
                });
            } else {
                const payments = activeSplits.map(m => ({
                    method:    m.key,
                    amount:    splitAmounts[m.key],
                    reference: refs[m.key] || null,
                }));
                await onComplete({
                    paymentMethod:  'split',
                    amountTendered: totalAllocated,
                    bankRef:        null,
                    payments,
                });
            }
        } finally {
            setLoading(false);
        }
    };

    useKeyboard(
        { Enter: complete, Escape: onClose },
        [mode, method, tendered, bankRef, splits, refs, singleCanComplete, splitCanComplete, loading],
        { allowInInput: true }
    );

    return (
        <div className="fixed inset-0 z-50 flex items-end md:items-center md:justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white dark:bg-gray-900 rounded-t-3xl md:rounded-2xl shadow-2xl w-full md:max-w-md md:mx-4 overflow-hidden max-h-[92vh] flex flex-col">

                {/* Handle bar — mobile only */}
                <div className="md:hidden w-11 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full mx-auto mt-3 shrink-0" />

                {/* Header */}
                <div className="bg-[#068B03] px-6 py-5 shrink-0">
                    <p className="text-white text-sm font-medium opacity-80">Total Due</p>
                    <p className="text-white text-5xl font-extrabold" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                        {fmt(total)}
                    </p>
                </div>

                <div className="p-6 space-y-4 overflow-y-auto flex-1">

                    {/* Breakdown */}
                    <div className="text-xs text-gray-400 dark:text-gray-500 space-y-1">
                        <div className="flex justify-between"><span>Subtotal</span><span>{fmt(subtotal)}</span></div>
                        {discountAmount > 0 && (
                            <div className="flex justify-between text-brand-orange">
                                <span>Discount</span><span>−{fmt(discountAmount)}</span>
                            </div>
                        )}
                        <div className="flex justify-between"><span>VAT (7.5%)</span><span>{fmt(vatAmount)}</span></div>
                    </div>

                    {/* Mode toggle */}
                    <div className="flex gap-2">
                        <button
                            onClick={() => switchMode('single')}
                            className={`flex-1 py-2 rounded-xl text-xs font-bold border-2 transition-all
                                ${mode === 'single' ? 'border-[#068B03] bg-[#068B03] text-white' : 'border-gray-200 dark:border-gray-700 text-gray-400 dark:text-gray-500'}`}
                        >
                            Single Payment
                        </button>
                        <button
                            onClick={() => switchMode('split')}
                            className={`flex-1 py-2 rounded-xl text-xs font-bold border-2 transition-all
                                ${mode === 'split' ? 'border-[#F97316] bg-[#F97316] text-white' : 'border-gray-200 dark:border-gray-700 text-gray-400 dark:text-gray-500'}`}
                        >
                            Split Payment
                        </button>
                    </div>

                    {/* ── SINGLE MODE ── */}
                    {mode === 'single' && (<>
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

                        {method === 'cash' && (
                            <div>
                                {/* Desktop: text input */}
                                <div className="hidden md:block">
                                    <label className="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">Amount Tendered</label>
                                    <input
                                        ref={tenderedRef}
                                        type="number"
                                        value={tendered}
                                        onChange={(e) => setTendered(e.target.value)}
                                        placeholder={fmt(total)}
                                        className="w-full border-2 border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-xl px-4 py-3 text-2xl font-bold text-gray-800 text-center focus:outline-none focus:border-[#068B03]"
                                    />
                                    {tenderedNum >= total && tendered && (
                                        <div className="mt-3 bg-green-50 dark:bg-green-950 rounded-xl px-4 py-3 text-center">
                                            <p className="text-xs text-gray-400 dark:text-gray-500">Change Due</p>
                                            <p className="text-3xl font-extrabold text-[#068B03]" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                                                {fmt(singleChange)}
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {/* Mobile: numpad */}
                                <div className="md:hidden">
                                    {/* Tendered + change display */}
                                    <div className="flex justify-between items-center bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 mb-3">
                                        <div>
                                            <p className="text-xs text-gray-400 dark:text-gray-500">Tendered</p>
                                            <p className="text-2xl font-bold text-gray-800 dark:text-gray-100" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                                                {tendered ? fmt(parseFloat(tendered)) : '₦0'}
                                            </p>
                                        </div>
                                        {tendered && (
                                            <div className={`text-right ${tenderedNum >= total ? 'text-[#068B03]' : 'text-[#F97316]'}`}>
                                                <p className="text-xs">{tenderedNum >= total ? 'Change' : 'Short'}</p>
                                                <p className="text-xl font-extrabold" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                                                    {fmt(Math.abs(tenderedNum - total))}
                                                </p>
                                            </div>
                                        )}
                                    </div>

                                    {/* Quick chips */}
                                    <div className="flex gap-2 mb-3 flex-wrap">
                                        {[
                                            { label: 'Exact',    value: String(Math.ceil(total)) },
                                            { label: '₦5,000',   value: '5000'  },
                                            { label: '₦10,000',  value: '10000' },
                                            { label: '₦20,000',  value: '20000' },
                                        ].map(chip => (
                                            <button
                                                key={chip.label}
                                                onClick={() => setTendered(chip.value)}
                                                className="border border-gray-200 dark:border-gray-700 rounded-full px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 bg-white dark:bg-gray-800 active:scale-95 transition-all"
                                            >
                                                {chip.label}
                                            </button>
                                        ))}
                                    </div>

                                    {/* Numpad */}
                                    <div className="grid grid-cols-3 gap-2">
                                        {NUMPAD_KEYS.map(key => (
                                            <button
                                                key={key}
                                                onClick={() => handleNumpad(key)}
                                                className="h-14 border border-gray-200 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-800 font-semibold text-xl text-gray-800 dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700 active:scale-95 transition-all"
                                            >
                                                {key}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )}

                        {method === 'bank_transfer' && (
                            <div>
                                <label className="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1 block">Transfer Reference (last 4+ digits)</label>
                                <input
                                    ref={tenderedRef}
                                    type="text"
                                    value={bankRef}
                                    onChange={(e) => setBankRef(e.target.value)}
                                    placeholder="e.g. 4821"
                                    maxLength={20}
                                    className="w-full border-2 border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-xl px-4 py-3 text-xl font-bold text-gray-800 text-center focus:outline-none focus:border-purple-500"
                                />
                            </div>
                        )}

                        {method === 'card' && (
                            <div className="bg-blue-50 dark:bg-blue-950 rounded-xl px-4 py-4 text-center">
                                <p className="text-sm font-semibold text-blue-700 dark:text-blue-400">Charge {fmt(total)} on POS terminal</p>
                                <p className="text-xs text-blue-400 dark:text-blue-500 mt-1">Press Enter once payment is approved</p>
                            </div>
                        )}
                    </>)}

                    {/* ── SPLIT MODE ── */}
                    {mode === 'split' && (<>

                        <div className={`rounded-xl px-4 py-3 text-center ${remaining === 0 ? 'bg-green-50 dark:bg-green-950' : 'bg-orange-50 dark:bg-orange-950'}`}>
                            <p className="text-xs text-gray-400 dark:text-gray-500">Remaining to Allocate</p>
                            <p className={`text-3xl font-extrabold ${remaining === 0 ? 'text-[#068B03]' : 'text-[#F97316]'}`}
                                style={{ fontFamily: 'Montserrat, sans-serif' }}>
                                {fmt(remaining)}
                            </p>
                            {splitOverpaid > 0 && (
                                <p className="text-xs text-amber-500 mt-0.5">Over by {fmt(splitOverpaid)} — change will be given</p>
                            )}
                        </div>

                        <div className="space-y-3">
                            {METHODS.map((m) => (
                                <div key={m.key}
                                    className={`border-2 rounded-xl p-3 transition-all ${splitAmounts[m.key] > 0 ? '' : 'border-gray-200 dark:border-gray-700'}`}
                                    style={splitAmounts[m.key] > 0 ? { borderColor: m.color } : {}}>
                                    <div className="flex items-center gap-3">
                                        <span className="text-xs font-bold w-24 shrink-0"
                                            style={{ color: splitAmounts[m.key] > 0 ? m.color : '#9ca3af' }}>
                                            {m.label}
                                        </span>
                                        <input
                                            type="number"
                                            min="0"
                                            value={splits[m.key]}
                                            onChange={(e) => setSplit(m.key, e.target.value)}
                                            placeholder="0"
                                            className="flex-1 border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-bold text-gray-800 text-right focus:outline-none focus:border-gray-400"
                                        />
                                    </div>

                                    {m.key === 'cash' && splitAmounts.cash > 0 && splitOverpaid > 0 && (
                                        <p className="text-xs text-[#068B03] mt-1.5 text-right font-semibold">
                                            Change due: {fmt(splitOverpaid)}
                                        </p>
                                    )}

                                    {m.key === 'bank_transfer' && splitAmounts.bank_transfer > 0 && (
                                        <input
                                            type="text"
                                            value={refs.bank_transfer}
                                            onChange={(e) => setRef('bank_transfer', e.target.value)}
                                            placeholder="Transfer reference (last 4+ digits)"
                                            maxLength={20}
                                            className="mt-2 w-full border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 rounded-lg px-3 py-2 text-xs text-gray-700 focus:outline-none focus:border-purple-400"
                                        />
                                    )}

                                    {m.key === 'card' && splitAmounts.card > 0 && (
                                        <p className="text-xs text-blue-400 mt-1.5">
                                            Charge {fmt(splitAmounts.card)} on POS terminal
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>

                        {activeSplits.length < 2 && totalAllocated > 0 && (
                            <p className="text-xs text-gray-400 dark:text-gray-500 text-center">Add at least 2 payment methods for a split payment</p>
                        )}
                    </>)}

                    {/* Customer info */}
                    {customer && (
                        <div className="text-xs text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800 rounded-lg px-3 py-2">
                            Receipt for: <span className="font-semibold text-gray-600 dark:text-gray-300">{customer.name}</span>
                            {customer.phone && <span> · {customer.phone}</span>}
                        </div>
                    )}

                    {/* Actions */}
                    <div className="flex gap-3">
                        <button
                            onClick={onClose}
                            className="flex-1 py-3 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800"
                        >
                            Cancel (Esc)
                        </button>
                        <button
                            onClick={complete}
                            disabled={(mode === 'single' ? !singleCanComplete : !splitCanComplete) || loading}
                            className={`flex-1 py-3 rounded-xl text-sm font-bold text-white transition-all
                                ${(mode === 'single' ? singleCanComplete : splitCanComplete) && !loading
                                    ? 'bg-[#068B03] hover:bg-[#057002] active:scale-95'
                                    : 'bg-gray-200 dark:bg-gray-700 text-gray-400 dark:text-gray-500 cursor-not-allowed'}`}
                        >
                            {loading ? 'Processing…' : 'Complete [Enter]'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
