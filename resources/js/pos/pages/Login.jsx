import { useState, useRef, useEffect } from 'react';
import api from '../lib/api';
import { db } from '../lib/db';

const DOTS = 4;
const CONFIG = window.POS_CONFIG ?? {};

export default function Login({ onLogin }) {
    const lockedVendorId   = CONFIG.vendorId   ?? null;
    const lockedVendorName = CONFIG.vendorName ?? null;
    const panelUrl         = CONFIG.panelUrl   ?? null;

    const [pin, setPin]         = useState('');
    const [vendorId, setVendorId] = useState(
        () => lockedVendorId ?? localStorage.getItem('pos_vendor_id') ?? ''
    );
    // Why the till was signed out, if it was — see main.jsx. Read once.
    const [error, setError]     = useState(() => {
        try {
            const notice = sessionStorage.getItem('pos_login_notice') ?? '';
            sessionStorage.removeItem('pos_login_notice');
            return notice;
        } catch {
            return '';
        }
    });
    const [loading, setLoading] = useState(false);
    // Offered when the PIN belongs to someone who works in several branches:
    // the till has to know which one it is standing in before it can sell.
    const [branches, setBranches] = useState(null);
    const vendorInputRef        = useRef(null);
    const pinInputRef           = useRef(null);

    useEffect(() => {
        if (lockedVendorId) pinInputRef.current?.focus();
        else vendorInputRef.current?.focus();
    }, []);

    const press = (digit) => {
        if (pin.length < DOTS) setPin((p) => p + digit);
    };

    const backspace = () => setPin((p) => p.slice(0, -1));

    const submit = async (storeId = null) => {
        if (!vendorId || pin.length < 4) return;
        setLoading(true);
        setError('');
        try {
            const { data } = await api.post('/auth/login', {
                vendor_id: Number(vendorId),
                pin,
                store_id:  storeId ?? undefined,
            });
            localStorage.setItem('pos_token', data.token);
            localStorage.setItem('pos_vendor_id', String(vendorId));
            localStorage.setItem('pos_user', JSON.stringify(data.user));
            localStorage.setItem('pos_store', JSON.stringify(data.store ?? null));
            localStorage.setItem('pos_vendor_settings', JSON.stringify(data.vendor ?? { vat_enabled: true, vat_rate: 7.5 }));

            const { data: products } = await api.get('/products', { params: { vendor_id: vendorId } });
            await db.products.clear();
            await db.products.bulkPut(products);

            // Customers too, so a credit sale can still name who owes when the
            // connection drops. Non-fatal on its own: failing to cache them must
            // never stop a cashier signing in and trading.
            try {
                const { data: customers } = await api.get('/customers', { params: { vendor_id: vendorId } });
                await db.customers.clear();
                await db.customers.bulkPut(customers);
            } catch { /* offline or refused — the till still opens */ }

            onLogin(data.user, Number(vendorId), data.store ?? null);
        } catch (e) {
            const body = e?.response?.data;

            // The PIN was right; the till just needs to know which branch.
            // The PIN is kept so choosing one signs straight in.
            if (body?.code === 'choose_store') {
                setBranches(body.stores ?? []);
                if (storeId) setError(body.message);
                return;
            }

            // A wrong PIN says so. Anything else the server explained — no
            // branch assigned, the account blocked — is shown as it said it,
            // because "Invalid PIN" would send the cashier retyping a PIN that
            // was never the problem.
            setBranches(null);
            setError(e?.response?.status === 401 || !body?.message ? 'Invalid PIN. Try again.' : body.message);
            setPin('');
            pinInputRef.current?.focus();
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (pin.length === DOTS) submit();
    }, [pin]); // eslint-disable-line react-hooks/exhaustive-deps

    const handlePinChange = (e) => {
        const val = e.target.value.replace(/\D/g, '').slice(0, DOTS);
        setPin(val);
    };

    const handlePinKeyDown = (e) => {
        if (e.key === 'Enter' && pin.length >= 4) submit();
    };

    const keys = ['1','2','3','4','5','6','7','8','9','','0','⌫'];

    if (branches) {
        return (
            <div className="min-h-screen bg-[#F9FAFB] dark:bg-gray-950 flex items-center justify-center p-4">
                <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-sm p-6 sm:p-10">
                    <div className="text-center mb-6">
                        <p className="text-lg font-bold text-gray-800 dark:text-gray-100">Which branch are you in?</p>
                        <p className="text-gray-400 dark:text-gray-500 text-sm mt-1">
                            Sales on this till will come out of that branch's stock.
                        </p>
                    </div>

                    <div className="space-y-2.5">
                        {branches.map((branch) => (
                            <button
                                key={branch.id}
                                onClick={() => submit(branch.id)}
                                disabled={loading}
                                className="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-[#F9FAFB] dark:bg-gray-800 px-4 py-3 text-left text-sm font-semibold text-gray-800 dark:text-gray-100 hover:border-[#068B03] hover:bg-green-50 dark:hover:bg-gray-700 disabled:opacity-50 active:scale-[0.99]"
                            >
                                {branch.name}
                            </button>
                        ))}
                    </div>

                    {error && <p className="text-red-500 text-xs text-center mt-4">{error}</p>}

                    <button
                        onClick={() => { setBranches(null); setPin(''); setError(''); }}
                        className="w-full mt-5 text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"
                    >
                        Not you? Enter a different PIN
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-[#F9FAFB] dark:bg-gray-950 flex items-center justify-center p-4">
            <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-sm p-6 sm:p-10">

                {/* Back to panel */}
                {panelUrl && (
                    <a href={panelUrl}
                        className="flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 mb-5 -mt-1">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                        </svg>
                        Back to Dashboard
                    </a>
                )}

                <div className="text-center mb-6">
                    <p className="text-2xl font-bold" style={{ fontFamily: 'Montserrat, sans-serif', color: '#068B03' }}>
                        GadgetPlug POS
                    </p>
                    {lockedVendorName
                        ? <p className="text-gray-600 dark:text-gray-400 text-sm font-medium mt-1">{lockedVendorName}</p>
                        : <p className="text-gray-400 dark:text-gray-500 text-sm mt-1">Enter your PIN to continue</p>
                    }
                </div>

                {/* Vendor ID field */}
                {!lockedVendorId && (
                    <input
                        ref={vendorInputRef}
                        type="number"
                        placeholder="Vendor ID"
                        value={vendorId}
                        onChange={(e) => setVendorId(e.target.value)}
                        onBlur={() => setTimeout(() => pinInputRef.current?.focus(), 100)}
                        className="w-full border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:placeholder-gray-500 rounded-lg px-4 py-2.5 text-sm text-center mb-5 focus:outline-none focus:border-[#068B03]"
                    />
                )}

                <p className="text-xs text-gray-400 dark:text-gray-500 text-center mb-3">
                    {lockedVendorId ? 'Tap below or type your PIN' : 'Enter your PIN'}
                </p>

                {/* PIN dots — invisible input overlaid so tapping opens the keyboard */}
                <div
                    className="relative flex justify-center gap-4 mb-2 py-3 cursor-text rounded-xl"
                    onClick={() => pinInputRef.current?.focus()}
                >
                    <input
                        ref={pinInputRef}
                        type="tel"
                        inputMode="numeric"
                        value={pin}
                        onChange={handlePinChange}
                        onKeyDown={handlePinKeyDown}
                        maxLength={DOTS}
                        autoComplete="off"
                        className="absolute inset-0 w-full h-full opacity-0 cursor-text"
                        aria-label="PIN"
                    />
                    {Array.from({ length: DOTS }).map((_, i) => (
                        <div
                            key={i}
                            className={`w-4 h-4 rounded-full border-2 transition-all ${
                                i < pin.length
                                    ? 'bg-[#068B03] border-[#068B03] scale-110'
                                    : 'border-gray-300 dark:border-gray-600'
                            }`}
                        />
                    ))}
                </div>

                {error
                    ? <p className="text-red-500 text-xs text-center mb-4">{error}</p>
                    : <div className="mb-4" />
                }

                {/* Numpad */}
                <div className="grid grid-cols-3 gap-2.5">
                    {keys.map((k, i) => (
                        <button
                            key={i}
                            onClick={() => {
                                pinInputRef.current?.focus();
                                if (k === '⌫') backspace();
                                else if (k !== '') press(k);
                            }}
                            disabled={loading || k === ''}
                            className={`h-14 rounded-xl text-xl font-semibold transition-all
                                ${k === '' ? 'invisible' : ''}
                                ${k === '⌫'
                                    ? 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 active:scale-95'
                                    : 'bg-[#F9FAFB] dark:bg-gray-800 text-gray-800 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 active:scale-95'
                                }
                            `}
                        >
                            {loading && k === '0' ? '…' : k}
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}
