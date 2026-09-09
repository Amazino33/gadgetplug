import { useState, useRef, useEffect, useCallback, forwardRef, useImperativeHandle } from 'react';
import { db } from '../lib/db';
import api from '../lib/api';
import { createLatestSearch } from '../lib/latestSearch';
import { selectionForEnter } from '../lib/searchSelection';

const SearchBar = forwardRef(function SearchBar({ vendorId, onSelect, autoFocus = true }, ref) {
    const [query, setQuery]     = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen]       = useState(false);
    const [searching, setSearching] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);
    const inputRef              = useRef(null);
    const debounceRef           = useRef(null);
    const rootRef               = useRef(null);

    // See latestSearch.js — the local lookup and the network fallback take
    // unrelated amounts of time, so answers can come back in a different
    // order than the searches that asked for them started in.
    const latestSearch = useRef(createLatestSearch()).current;

    useImperativeHandle(ref, () => ({
        focus: () => inputRef.current?.focus(),
        // The till hands the keyboard away when something covers it. Without
        // this, a popup that fails to take focus leaves the cashier typing a
        // quantity into the search box behind it, where the characters are
        // both invisible and wrong.
        blur:  () => inputRef.current?.blur(),
    }));

    // Closing on the input's own blur is unreliable on a touchscreen — the
    // keyboard opening and closing fires focus events of its own, and a
    // scroll inside the list can steal focus without the cashier having
    // dismissed anything. So the panel closes on a tap outside it instead,
    // which is the only gesture that actually means "I'm done here", and it
    // closes every panel below, not just the results list.
    useEffect(() => {
        const closeOnOutsideTap = (e) => {
            if (rootRef.current && !rootRef.current.contains(e.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', closeOnOutsideTap);

        return () => document.removeEventListener('pointerdown', closeOnOutsideTap);
    }, []);

    const search = useCallback(async (q) => {
        const token = latestSearch.start();
        const trimmed = q.trim();

        if (!trimmed) { setResults([]); setOpen(false); setActiveIndex(-1); setSearching(false); return; }

        // The panel comes down as soon as there is something being searched
        // for, and stays down until the cashier picks something, clears the
        // box, presses Escape, or taps away. It is deliberately NOT tied to
        // whether there are results: doing that meant a search with no local
        // match rendered nothing at all — not "no matches", nothing — since
        // every panel below is gated on this.
        setOpen(true);
        setActiveIndex(-1);

        // IndexedDB first — instant and offline-capable, and it's re-seeded
        // on every login, so it's usually the whole answer.
        const local = await db.products
            .filter((p) =>
                p.barcode === trimmed ||
                (p.sku && p.sku.toLowerCase() === trimmed.toLowerCase()) ||
                (p.name && p.name.toLowerCase().includes(trimmed.toLowerCase()))
            )
            .limit(10)
            .toArray();

        // A newer search has started since this one began — its answer is
        // no longer relevant to what's on screen, so it's dropped rather
        // than shown.
        if (!latestSearch.isCurrent(token)) return;

        setResults(local);

        // The local catalogue is only ever written at login and never
        // refreshed, so it goes stale the moment a product is added or
        // restocked mid-shift. The server is therefore asked on every
        // search, not just when the cache came up empty: skipping it
        // whenever the cache had *any* match meant a cashier typing "TECNO"
        // saw yesterday's TECNOs and never the one added this morning.
        //
        // Local results are already on screen by now, so this costs the
        // cashier nothing — it only ever adds what the device did not know
        // about. Out-of-order answers are handled by the guard below rather
        // than by not asking.
        if (navigator.onLine) {
            setSearching(true);
            try {
                // Short timeout on top of the client's generous default — a
                // cashier waiting on a search result needs an answer in
                // seconds, not whatever the slowest thing on the till can
                // tolerate. Local results (if any) are already showing.
                const { data } = await api.get('/products/search', {
                    params: { vendor_id: vendorId, q: trimmed },
                    timeout: 5000,
                });
                if (!latestSearch.isCurrent(token)) return;

                // The server's answer replaces the local one rather than
                // merging into it: it is the authority on what this branch
                // may sell right now. A product the cache still lists but
                // the server no longer returns — unpublished, moved branch,
                // sold out — must stop being offered, not linger because a
                // stale copy exists on the device.
                setResults(data);
                setActiveIndex(-1);
            } catch { /* offline or too slow — whatever the device knows is already showing */ }
            finally {
                if (latestSearch.isCurrent(token)) setSearching(false);
            }
        }
    }, [vendorId, latestSearch]); // eslint-disable-line react-hooks/exhaustive-deps

    const pick = (product) => {
        onSelect(product);
        setQuery('');
        setResults([]);
        setOpen(false);
        setActiveIndex(-1);

        // Deliberately does NOT grab focus back. Picking a product opens the
        // quantity box, and this used to steal the caret out of it a tick
        // later — the box would highlight its "1" and then quietly lose the
        // keyboard, so a typed quantity went nowhere. The till decides where
        // focus belongs (POS.jsx): back here once nothing is covering it,
        // which includes the moment the quantity box closes.
    };

    const onChange = (e) => {
        const q = e.target.value;
        setQuery(q);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => search(q), 120);
    };

    const onKeyDown = (e) => {
        if (e.key === 'Escape') { setOpen(false); setQuery(''); setActiveIndex(-1); }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (open && results.length > 0) {
                setActiveIndex((prev) => (prev < results.length - 1 ? prev + 1 : prev));
            }
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (open && results.length > 0) {
                setActiveIndex((prev) => (prev > 0 ? prev - 1 : 0));
            }
        }
        if (e.key === 'Enter') {
            e.preventDefault();

            // Only ever adds the row the cashier arrowed onto. See
            // lib/searchSelection for the two softer rules that were tried
            // here and what each of them rang up by mistake.
            const chosen = selectionForEnter({ results, activeIndex });

            if (chosen) pick(chosen);
        }
    };

    return (
        <div className="relative flex-1" ref={rootRef}>
            <div className="flex items-center gap-2 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-2.5">
                {searching ? (
                    <svg className="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                    </svg>
                ) : (
                    <svg className="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                )}
                <input
                    ref={inputRef}
                    type="text"
                    value={query}
                    onChange={onChange}
                    onKeyDown={onKeyDown}
                    onFocus={() => query.trim() && setOpen(true)}
                    aria-label="Search products"
                    placeholder="Scan barcode or search product...  [F3]"
                    autoFocus={autoFocus}
                    className="flex-1 bg-transparent text-sm outline-none placeholder-gray-400 dark:placeholder-gray-500 text-gray-800 dark:text-gray-100"
                />
            </div>

            {open && results.length === 0 && query.trim() && searching && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg z-50 px-4 py-3">
                    <p className="text-xs text-gray-400 dark:text-gray-500">Searching…</p>
                </div>
            )}

            {open && results.length === 0 && query.trim() && !searching && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg z-50 px-4 py-3">
                    <p className="text-xs text-gray-400 dark:text-gray-500">No product matches "{query.trim()}".</p>
                </div>
            )}

            {open && results.length > 0 && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg z-50 max-h-72 overflow-y-auto">
                    {results.map((p, index) => (
                        <button
                            key={p.id}
                            type="button"
                            // A real click, not mousedown: on a touchscreen,
                            // mousedown fires as soon as a finger lands, so
                            // dragging the list to scroll added whatever
                            // happened to be under it. A click only lands
                            // when a tap starts and ends on the same row.
                            //
                            // The prevented mousedown stops the row stealing
                            // focus from the input on the way there — that
                            // blur mid-tap is what this used to be a mousedown
                            // handler to avoid.
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => pick(p)}
                            className={`w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 text-left border-b border-gray-50 dark:border-gray-800 last:border-0 ${activeIndex === index ? 'bg-gray-100 dark:bg-gray-800' : ''}`}
                        >
                            {p.image
                                ? <img src={p.image} className="w-10 h-10 rounded-lg object-cover shrink-0" />
                                : <div className="w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-800 shrink-0" />
                            }
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-medium text-gray-800 dark:text-gray-100 truncate">{p.name}</p>
                                <p className="text-xs text-gray-400 dark:text-gray-500">{p.sku ?? p.barcode ?? '—'}</p>
                            </div>
                            <div className="text-right shrink-0">
                                <p className="text-sm font-semibold text-gray-800 dark:text-gray-100">₦{Number(p.price).toLocaleString()}</p>
                                <p className={`text-xs ${p.is_low_stock ? 'text-orange-500' : 'text-gray-400 dark:text-gray-500'}`}>
                                    {p.available_stock} left
                                </p>
                                {p.reserved > 0 && (
                                    <p className="text-[11px] text-amber-600 dark:text-amber-400" title="Held by online order(s) not yet dispatched">
                                        {p.reserved} reserved online
                                    </p>
                                )}
                            </div>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
});

export default SearchBar;
