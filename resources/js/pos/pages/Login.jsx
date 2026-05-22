import { useState, useRef, useEffect } from 'react';
import api from '../lib/api';
import { db } from '../lib/db';

const DOTS = 6;
const CONFIG = window.POS_CONFIG ?? {};

export default function Login({ onLogin }) {
    // If the page was opened from Filament (/pos/{slug}), vendor is locked.
    const lockedVendorId   = CONFIG.vendorId   ?? null;
    const lockedVendorName = CONFIG.vendorName ?? null;
    const panelUrl         = CONFIG.panelUrl   ?? null;

    const [pin, setPin]       = useState('');
    const [vendorId, setVendorId] = useState(
        () => lockedVendorId ?? localStorage.getItem('pos_vendor_id') ?? ''
    );
    const [error, setError]   = useState('');
    const [loading, setLoading] = useState(false);
    const inputRef            = useRef(null);

    useEffect(() => { inputRef.current?.focus(); }, []);

    const press = (digit) => {
        if (pin.length < DOTS) setPin((p) => p + digit);
    };

    const backspace = () => setPin((p) => p.slice(0, -1));

    const submit = async () => {
        if (!vendorId || pin.length < 4) return;
        setLoading(true);
        setError('');
        try {
            const { data } = await api.post('/auth/login', { vendor_id: Number(vendorId), pin });
            localStorage.setItem('pos_token', data.token);
            localStorage.setItem('pos_vendor_id', String(vendorId));
            localStorage.setItem('pos_user', JSON.stringify(data.user));

            // Seed IndexedDB with this vendor's product catalogue
            const { data: products } = await api.get('/products', { params: { vendor_id: vendorId } });
            await db.products.clear();
            await db.products.bulkPut(products);

            onLogin(data.user, Number(vendorId));
        } catch {
            setError('Invalid PIN. Try again.');
            setPin('');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (pin.length === DOTS) submit();
    }, [pin]); // eslint-disable-line react-hooks/exhaustive-deps

    const keys = ['1','2','3','4','5','6','7','8','9','','0','⌫'];

    return (
        <div className="min-h-screen bg-[#F9FAFB] flex items-center justify-center">
            <div className="bg-white rounded-2xl shadow-xl p-10 w-full max-w-sm">

                {/* Back to panel link */}
                {panelUrl && (
                    <a href={panelUrl}
                        className="flex items-center gap-1.5 text-xs text-gray-400 hover:text-gray-600 mb-6 -mt-2">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                        </svg>
                        Back to Dashboard
                    </a>
                )}

                <div className="text-center mb-8">
                    <p className="text-2xl font-bold" style={{ fontFamily: 'Montserrat, sans-serif', color: '#068B03' }}>
                        GadgetPlug POS
                    </p>
                    {lockedVendorName
                        ? <p className="text-gray-600 text-sm font-medium mt-1">{lockedVendorName}</p>
                        : <p className="text-gray-400 text-sm mt-1">Enter your PIN to continue</p>
                    }
                </div>

                {/* Vendor ID field — hidden when locked from URL, visible as fallback */}
                {!lockedVendorId && (
                    <input
                        ref={inputRef}
                        type="number"
                        placeholder="Vendor ID"
                        value={vendorId}
                        onChange={(e) => setVendorId(e.target.value)}
                        className="w-full border border-gray-200 rounded-lg px-4 py-2 text-sm text-center mb-6 focus:outline-none focus:border-[#068B03]"
                    />
                )}

                <p className="text-xs text-gray-400 text-center mb-4">Enter your PIN</p>

                {/* PIN dots */}
                <div className="flex justify-center gap-4 mb-8">
                    {Array.from({ length: DOTS }).map((_, i) => (
                        <div
                            key={i}
                            className={`w-4 h-4 rounded-full border-2 transition-all ${
                                i < pin.length
                                    ? 'bg-[#068B03] border-[#068B03]'
                                    : 'border-gray-300'
                            }`}
                        />
                    ))}
                </div>

                {error && <p className="text-red-500 text-xs text-center mb-4">{error}</p>}

                {/* Numpad */}
                <div className="grid grid-cols-3 gap-3">
                    {keys.map((k, i) => (
                        <button
                            key={i}
                            onClick={() => {
                                if (k === '⌫') backspace();
                                else if (k !== '') press(k);
                            }}
                            disabled={loading || k === ''}
                            className={`h-14 rounded-xl text-xl font-semibold transition-all
                                ${k === '' ? 'invisible' : ''}
                                ${k === '⌫'
                                    ? 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                    : 'bg-[#F9FAFB] text-gray-800 hover:bg-gray-100 active:scale-95'
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
