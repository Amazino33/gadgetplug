import { useState, useRef, useCallback, forwardRef, useImperativeHandle } from 'react';
import { db } from '../lib/db';
import api from '../lib/api';

const SearchBar = forwardRef(function SearchBar({ vendorId, onSelect }, ref) {
    const [query, setQuery]     = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen]       = useState(false);
    const inputRef              = useRef(null);
    const debounceRef           = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => inputRef.current?.focus(),
    }));

    const search = useCallback(async (q) => {
        if (!q.trim()) { setResults([]); setOpen(false); return; }

        // Search IndexedDB first (offline-capable)
        const local = await db.products
            .filter((p) =>
                p.barcode === q ||
                (p.sku && p.sku.toLowerCase() === q.toLowerCase()) ||
                (p.name && p.name.toLowerCase().includes(q.toLowerCase()))
            )
            .limit(10)
            .toArray();

        if (local.length > 0) {
            setResults(local);
            setOpen(true);

            // Exact barcode match — auto-add immediately
            const exact = local.find((p) => p.barcode === q || p.sku === q);
            if (exact) { pick(exact); return; }
        }

        // Fallback to API when online
        if (navigator.onLine) {
            try {
                const { data } = await api.get('/products/search', { params: { vendor_id: vendorId, q } });
                setResults(data);
                setOpen(data.length > 0);
                const exact = data.find((p) => p.barcode === q || p.sku === q);
                if (exact) { pick(exact); return; }
            } catch { /* stay offline */ }
        }
    }, [vendorId]); // eslint-disable-line react-hooks/exhaustive-deps

    const pick = (product) => {
        onSelect(product);
        setQuery('');
        setResults([]);
        setOpen(false);
        inputRef.current?.focus();
    };

    const onChange = (e) => {
        const q = e.target.value;
        setQuery(q);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => search(q), 120);
    };

    const onKeyDown = (e) => {
        if (e.key === 'Escape') { setOpen(false); setQuery(''); }
        if (e.key === 'Enter' && results.length === 1) pick(results[0]);
    };

    return (
        <div className="relative flex-1">
            <div className="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5">
                <svg className="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    ref={inputRef}
                    type="text"
                    value={query}
                    onChange={onChange}
                    onKeyDown={onKeyDown}
                    onFocus={() => query && setOpen(true)}
                    onBlur={() => setTimeout(() => setOpen(false), 150)}
                    placeholder="Scan barcode or search product…  [F3]"
                    autoFocus
                    className="flex-1 bg-transparent text-sm outline-none placeholder-gray-400"
                />
            </div>

            {open && results.length > 0 && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg z-50 max-h-72 overflow-y-auto">
                    {results.map((p) => (
                        <button
                            key={p.id}
                            onMouseDown={() => pick(p)}
                            className="w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 text-left border-b border-gray-50 last:border-0"
                        >
                            {p.image
                                ? <img src={p.image} className="w-10 h-10 rounded-lg object-cover shrink-0" />
                                : <div className="w-10 h-10 rounded-lg bg-gray-100 shrink-0" />
                            }
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-medium text-gray-800 truncate">{p.name}</p>
                                <p className="text-xs text-gray-400">{p.sku ?? p.barcode ?? '—'}</p>
                            </div>
                            <div className="text-right shrink-0">
                                <p className="text-sm font-semibold text-gray-800">₦{Number(p.price).toLocaleString()}</p>
                                <p className={`text-xs ${p.available_stock < 5 ? 'text-orange-500' : 'text-gray-400'}`}>
                                    {p.available_stock} left
                                </p>
                            </div>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
});

export default SearchBar;
