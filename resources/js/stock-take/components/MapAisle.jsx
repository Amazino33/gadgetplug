import { useCallback, useEffect, useRef, useState } from 'react';
import CameraScanner from '../../purchases/components/CameraScanner';
import { isTouch } from '../../purchases/lib/device';
import api, { errorMessage } from '../lib/api';
import { good, setSound, soundOn, warn } from '../lib/feedback';
import { search as searchIndex, lookupCode } from '../offline/lookup';
import { flush, loadCatalogue, onMapQueue, queueMap, startMapQueue, unqueueMap, waitingFor } from '../offline/mapping';
import UnknownItemForm from './UnknownItemForm';

const TOUCH = isTouch();

/**
 * Walk an aisle and scan what is on it. Each scan puts the product on the
 * aisle's map (or says it is already there) and the camera keeps going.
 *
 * Fast: the shop's products are on the phone (offline/mapping), so a scan is
 * found here, beeps and shows at once, and goes up to the server in a batch
 * a moment later. Only a code the phone does not know asks the server.
 *
 * A Bluetooth or USB scanner types the code and presses Enter, so the scan box
 * takes those too; on a computer it keeps the focus for the next one. Name
 * search is the fallback for a missing or unreadable barcode, and a code the
 * system does not know is written down with a name and a photo.
 */
export default function MapAisle({ aisle, task, onBack }) {
    const [products, setProducts] = useState(null);
    const [error, setError]       = useState('');
    const [toast, setToast]       = useState(null);
    const [camera, setCamera]     = useState(false);
    const [code, setCode]         = useState('');
    const [query, setQuery]       = useState('');
    const [results, setResults]   = useState([]);
    const [unknown, setUnknown]   = useState(null);
    const [index, setIndex]       = useState(null);
    const [pending, setPending]   = useState(() => new Set());
    const [queueState, setQueueState] = useState({ waiting: 0, offline: false });
    const [sound, setSoundState] = useState(soundOn);
    const scanRef     = useRef(null);
    const productsRef = useRef(null);
    const taskId  = task?.id ?? null;
    productsRef.current = products;

    const refocus = useCallback(() => { if (!TOUCH) scanRef.current?.focus(); }, []);

    useEffect(() => {
        api.get(`/aisles/${aisle.id}`)
            // Keep anything scanned while this was still on its way.
            .then(({ data }) => setProducts((local) => {
                const onServer = new Set(data.products.map((p) => p.id));
                return [...(local ?? []).filter((p) => !onServer.has(p.id)), ...data.products];
            }))
            .catch((e) => setError(errorMessage(e, 'Could not load this aisle.')));
    }, [aisle.id]);

    useEffect(() => { refocus(); }, [refocus]);

    // The shop's products on the phone, and the queue that saves the map.
    useEffect(() => {
        let live = true;
        loadCatalogue(api, (found) => { if (live) setIndex(found); }).catch(() => {});
        startMapQueue(api).catch(() => {});
        const stop = onMapQueue((state) => {
            if (!live) return;
            setQueueState({ waiting: state.waiting, offline: state.offline });
            setPending(waitingFor(aisle.id));
            if (state.refused?.aisleId === aisle.id) {
                const gone = new Set(state.refused.ids);
                setProducts((list) => (list ?? []).filter((p) => !gone.has(p.id)));
                warn();
                setToast({ name: state.refused.message, tone: 'error', at: Date.now() });
            }
            if (state.missing?.aisleId === aisle.id) {
                const gone = new Set(state.missing.ids);
                setProducts((list) => (list ?? []).filter((p) => !gone.has(p.id)));
            }
        });
        return () => { live = false; stop(); flush().catch(() => {}); };
    }, [aisle.id]);

    // Turning sound on plays a beep, so the counter knows they will hear one.
    const toggleSound = () => {
        const on = !sound;
        setSound(on);
        setSoundState(on);
        if (on) good();
    };

    const say = (name, tone = 'ok') => setToast({ name, tone, at: Date.now() });

    const added = (product) => setProducts((list) => [product, ...(list ?? []).filter((p) => p.id !== product.id)]);

    const handle = useCallback((data, scanned) => {
        if (data.status === 'added') {
            good();
            added({ ...data.product, source: 'mapping' });
            say(data.product.name);
        } else if (data.status === 'already') {
            warn();
            say(`Already in this aisle: ${data.product.name}`, 'warn');
        } else if (data.status === 'unknown') {
            warn();
            setCamera(false);
            setUnknown({ barcode: scanned });
        }
    }, []);

    // Scanned here earlier and still waiting on the phone (no signal then): show them.
    useEffect(() => {
        if (!index || products === null) return;
        const missing = [...pending].filter((id) => !products.some((p) => p.id === id) && index.products.has(id));
        if (missing.length) {
            setProducts((list) => [...missing.map((id) => ({ ...index.products.get(id), source: 'mapping' })), ...(list ?? [])]);
        }
    }, [index, products, pending]);

    /** On the phone's map at once; saved in the next batch. */
    const putLocally = useCallback((product) => {
        if ((productsRef.current ?? []).some((p) => p.id === product.id)) {
            warn();
            say(`Already in this aisle: ${product.name}`, 'warn');
            return;
        }
        good();
        added({ ...product, barcode: product.barcodes?.[0] ?? null, source: 'mapping' });
        say(product.name);
        setPending((set) => new Set(set).add(product.id));
        queueMap(api, aisle.id, taskId, product.id).catch(() => {});
    }, [aisle.id, taskId]);

    const scan = useCallback(async (raw) => {
        const value = raw.trim();
        if (!value) return;
        setError('');

        // Found on the phone: no waiting for the server.
        const hit = index ? lookupCode(index, value) : null;
        if (hit) {
            putLocally(hit.product);
            return;
        }

        try {
            const { data } = await api.post(`/aisles/${aisle.id}/scan`, { code: value, task_id: taskId });
            handle(data, value);
        } catch (e) {
            warn();
            say(errorMessage(e), 'error');
        }
    }, [aisle.id, taskId, handle, index, putLocally]);

    const submitCode = (e) => {
        e.preventDefault();
        scan(code);
        setCode('');
        refocus();
    };

    useEffect(() => {
        if (query.trim().length < 2) { setResults([]); return undefined; }
        if (index) { setResults(searchIndex(index, query)); return undefined; }
        const timer = setTimeout(() => {
            api.get('/products', { params: { q: query } })
                .then(({ data }) => setResults(data.products))
                .catch(() => setResults([]));
        }, 250);
        return () => clearTimeout(timer);
    }, [query, index]);

    const addFromSearch = async (product) => {
        if (index?.products.has(product.id)) {
            putLocally(index.products.get(product.id));
            setQuery('');
            setResults([]);
            return;
        }
        try {
            const { data } = await api.post(`/aisles/${aisle.id}/products`, { product_id: product.id, task_id: taskId });
            handle(data);
            setQuery('');
            setResults([]);
        } catch (e) {
            say(errorMessage(e), 'error');
        }
    };

    const remove = async (product) => {
        if (!window.confirm(`Take ${product.name} off ${aisle.label}?`)) return;
        // Not sent yet: just drop it. Otherwise make sure it is up before taking it off.
        if (await unqueueMap(aisle.id, product.id)) {
            setProducts((list) => list.filter((p) => p.id !== product.id));
            return;
        }
        try {
            await flush();
            await api.delete(`/aisles/${aisle.id}/products/${product.id}`);
            setProducts((list) => list.filter((p) => p.id !== product.id));
        } catch (e) {
            say(errorMessage(e), 'error');
        }
    };

    return (
        <div className="mx-auto max-w-xl space-y-3 p-3 pb-28">
            <button type="button" onClick={onBack} className="px-1 text-sm font-semibold text-teal-700">← Aisles</button>
            <div className="flex items-end justify-between gap-2 px-1">
                <h1 className="text-lg font-extrabold text-gray-800 dark:text-gray-100">{aisle.label}</h1>
                <div className="flex items-center gap-2">
                    <span className="text-xs text-gray-500">{products?.length ?? '…'} mapped</span>
                    <button type="button" onClick={toggleSound} aria-pressed={sound}
                        className={`rounded-full px-3 py-1.5 text-xs font-bold ${sound ? 'bg-teal-50 text-teal-800 dark:bg-teal-900/40 dark:text-teal-200' : 'bg-gray-200 text-gray-700 dark:bg-gray-800 dark:text-gray-300'}`}>
                        {sound ? '🔊 Sound on' : '🔇 Sound off'}
                    </button>
                </div>
            </div>
            {queueState.waiting > 0 && (
                <p role="note" className={`px-1 text-xs font-semibold ${queueState.offline ? 'text-amber-700' : 'text-gray-500'}`}>
                    {queueState.offline
                        ? `${queueState.waiting} waiting on this phone — they go up when there is signal`
                        : `Saving ${queueState.waiting}…`}
                </p>
            )}

            {toast && !camera && (
                <p key={toast.at} role="status" className={`rounded-xl px-3 py-2 text-sm font-bold text-white ${toast.tone === 'ok' ? 'bg-green-600' : toast.tone === 'warn' ? 'bg-amber-600' : 'bg-red-600'}`}>
                    {toast.tone === 'ok' ? `✓ Added: ${toast.name}` : toast.name}
                </p>
            )}
            {error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700">{error}</p>}

            <button
                type="button"
                onClick={() => setCamera(true)}
                aria-label="Scan with camera"
                className="w-full rounded-2xl bg-teal-700 px-4 py-5 text-lg font-extrabold text-white shadow-sm active:scale-[0.99]"
            >
                Scan with camera
            </button>

            <form onSubmit={submitCode} className="flex gap-2">
                <input
                    ref={scanRef}
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    inputMode="numeric"
                    placeholder="Scan or type a barcode"
                    aria-label="Barcode"
                    className="min-w-0 flex-1 rounded-xl border border-gray-300 bg-white px-3 py-3 text-base outline-none focus:border-teal-600 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                />
                <button type="submit" className="rounded-xl bg-gray-800 px-4 text-sm font-bold text-white">Add</button>
            </form>

            <div className="space-y-2">
                <input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="No barcode? Search by name"
                    aria-label="Search by name"
                    className="w-full rounded-xl border border-gray-300 bg-white px-3 py-3 text-base outline-none focus:border-teal-600 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                />
                {results.length > 0 && (
                    <ul className="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
                        {results.map((p) => (
                            <li key={p.id} className="flex items-center gap-3 p-3">
                                <span className="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 dark:text-gray-100">{p.name}</span>
                                <button type="button" onClick={() => addFromSearch(p)} className="rounded-lg bg-teal-700 px-3 py-2 text-xs font-bold text-white">Add here</button>
                            </li>
                        ))}
                    </ul>
                )}
                <button type="button" onClick={() => setUnknown({ barcode: '' })} className="px-1 text-sm font-semibold text-teal-700">
                    Not in the system? Record it
                </button>
            </div>

            <h2 className="px-1 pt-2 text-sm font-bold uppercase tracking-wide text-gray-500">On this aisle</h2>
            {products === null && !error && <p className="p-4 text-center text-sm text-gray-400">Loading…</p>}
            {products?.length === 0 && <p className="rounded-2xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">Nothing mapped here yet. Scan the first product.</p>}
            <ul className="space-y-2">
                {products?.map((p) => (
                    <li key={p.id} className="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-2 dark:border-gray-800 dark:bg-gray-900">
                        {p.image
                            ? <img src={p.image} alt="" className="h-12 w-12 shrink-0 rounded-lg object-cover" />
                            : <div className="h-12 w-12 shrink-0 rounded-lg bg-gray-100 dark:bg-gray-800" />}
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-semibold text-gray-800 dark:text-gray-100">{p.name}</p>
                            <p className="truncate text-xs text-gray-500">{p.sku ?? p.barcode ?? ''}{p.source === 'count' ? ' · found while counting' : ''}{pending.has(p.id) ? ' · saving' : ''}</p>
                        </div>
                        <button type="button" onClick={() => remove(p)} aria-label={`Remove ${p.name}`} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-lg text-gray-400 hover:bg-red-50 hover:text-red-600">✕</button>
                    </li>
                ))}
            </ul>

            {camera && (
                <CameraScanner
                    tone="teal"
                    title={`Mapping ${aisle.label}`}
                    subtitle={`${products?.length ?? 0} product${products?.length === 1 ? '' : 's'} on this aisle`}
                    onCode={scan}
                    onClose={() => { setCamera(false); refocus(); }}
                    last={toast}
                />
            )}

            {unknown && (
                <UnknownItemForm
                    aisle={aisle}
                    taskId={taskId}
                    barcode={unknown.barcode}
                    onClose={() => { setUnknown(null); refocus(); }}
                    onSaved={(name) => { setUnknown(null); say(`Recorded for a manager: ${name}`, 'warn'); refocus(); }}
                />
            )}
        </div>
    );
}
