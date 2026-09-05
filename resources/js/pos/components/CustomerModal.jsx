import { useState, useEffect, useRef } from 'react';
import api from '../lib/api';
import { db } from '../lib/db';

export default function CustomerModal({ vendorId, current, onSelect, onClose }) {
    const [query, setQuery]       = useState('');
    const [results, setResults]   = useState([]);
    const [mode, setMode]         = useState('search'); // 'search' | 'create'
    const [form, setForm]         = useState({ name: '', phone: '', email: '' });
    const [loading, setLoading]   = useState(false);
    const inputRef                = useRef(null);

    useEffect(() => { inputRef.current?.focus(); }, []);

    const [offline, setOffline] = useState(false);

    // Cached names and phones, so a credit sale can still be attached to
    // somebody when the connection is gone. The server is asked first because
    // it knows about anyone added since this till last signed in.
    const searchCache = async (q) => {
        const needle = q.trim().toLowerCase();

        return db.customers
            .where('vendor_id').equals(Number(vendorId))
            .filter((c) => (c.name ?? '').toLowerCase().includes(needle)
                || (c.phone ?? '').includes(needle))
            .limit(30)
            .toArray();
    };

    const search = async (q) => {
        if (!q.trim()) { setResults([]); setOffline(false); return; }

        try {
            const { data } = await api.get('/customers', { params: { vendor_id: vendorId, q } });
            setResults(data);
            setOffline(false);
        } catch {
            setResults(await searchCache(q));
            setOffline(true);
        }
    };

    const createCustomer = async () => {
        if (!form.name.trim()) return;
        setLoading(true);
        try {
            const { data } = await api.post('/customers', { vendor_id: vendorId, ...form });
            // Straight into the cache, so the person just added is findable
            // again if the connection drops a minute later.
            await db.customers.put({ ...data, vendor_id: Number(vendorId) });
            onSelect(data);
        } catch {
            // A new customer needs a server id before anything can be charged
            // to them, so this is one thing the till genuinely cannot do
            // offline. Said plainly rather than failing silently.
            setOffline(true);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4">
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <h2 className="font-bold text-gray-800">Customer</h2>
                    <div className="flex gap-2">
                        <button onClick={() => setMode('search')}
                            className={`text-xs px-3 py-1.5 rounded-lg font-medium ${mode === 'search' ? 'bg-gray-900 text-white' : 'text-gray-500 hover:bg-gray-100'}`}>
                            Search
                        </button>
                        <button onClick={() => setMode('create')}
                            className={`text-xs px-3 py-1.5 rounded-lg font-medium ${mode === 'create' ? 'bg-gray-900 text-white' : 'text-gray-500 hover:bg-gray-100'}`}>
                            New
                        </button>
                    </div>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl">✕</button>
                </div>

                <div className="p-6">
                    {offline && (
                        <div className="mb-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                            {mode === 'create'
                                ? 'No connection — a new customer cannot be added until the till is back online. Search for an existing one instead.'
                                : 'No connection — searching the customers saved on this till.'}
                        </div>
                    )}

                    {mode === 'search' ? (
                        <>
                            <input
                                ref={inputRef}
                                type="text"
                                value={query}
                                onChange={(e) => { setQuery(e.target.value); search(e.target.value); }}
                                placeholder="Search by name or phone…"
                                className="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[#068B03] mb-4"
                            />
                            {current && (
                                <div className="mb-3 flex items-center justify-between bg-green-50 rounded-lg px-4 py-3">
                                    <div>
                                        <p className="text-sm font-semibold text-green-800">{current.name}</p>
                                        <p className="text-xs text-green-600">{current.phone}</p>
                                    </div>
                                    <button onClick={() => onSelect(null)} className="text-xs text-red-400 hover:text-red-600">Remove</button>
                                </div>
                            )}
                            <div className="space-y-1 max-h-64 overflow-y-auto">
                                {results.map((c) => (
                                    <button key={c.id} onClick={() => onSelect(c)}
                                        className="w-full flex items-center justify-between px-4 py-3 rounded-xl hover:bg-gray-50 text-left">
                                        <div>
                                            <p className="text-sm font-medium text-gray-800">{c.name}</p>
                                            <p className="text-xs text-gray-400">{c.phone}</p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-xs text-gray-400">{c.total_transactions} purchases</p>
                                        </div>
                                    </button>
                                ))}
                                {results.length === 0 && query && (
                                    <p className="text-center text-sm text-gray-400 py-4">
                                        No results. <button onClick={() => setMode('create')} className="text-[#068B03] font-medium">Create new?</button>
                                    </p>
                                )}
                            </div>
                        </>
                    ) : (
                        <div className="space-y-3">
                            {[
                                { key: 'name',  label: 'Full Name *', type: 'text', ref: inputRef },
                                { key: 'phone', label: 'Phone',        type: 'tel' },
                                { key: 'email', label: 'Email',        type: 'email' },
                            ].map(({ key, label, type, ref: r }) => (
                                <div key={key}>
                                    <label className="text-xs font-semibold text-gray-500 mb-1 block">{label}</label>
                                    <input
                                        ref={r}
                                        type={type}
                                        value={form[key]}
                                        onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.value }))}
                                        className="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-[#068B03]"
                                    />
                                </div>
                            ))}
                            <button
                                onClick={createCustomer}
                                disabled={!form.name.trim() || loading}
                                className="w-full mt-2 py-3 bg-[#068B03] text-white rounded-xl font-semibold text-sm disabled:opacity-40"
                            >
                                {loading ? 'Saving…' : 'Save & Select'}
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
