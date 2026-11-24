import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { fmt } from '../../pos/lib/format';
import {
    addProduct, calc, changeUnit, fromDetail, looksLikeCode, serialProblem, setPropagate,
    setUnitCost, setUnitPrice, toPayload, totals, unitsFor,
} from '../lib/lines';
import { addToIndex, buildIndex, fetchCatalogue, rankResults, resolveCode, searchHits, searchLocal, warmSearch } from '../lib/catalogue';
import { CreateProductModal, GrnModal, PaymentModal, SupplierModal } from './Modals';
import CameraScanner from './CameraScanner';
import { createSearchLearner } from '../../shared/searchLearning';
import { canDetectBarcodes, isTouch, useWide } from '../lib/device';

const CONFIG = window.PURCHASES_CONFIG ?? {};
const STEP = Number(CONFIG.roundingStep ?? 50);
// False for someone who records purchases but may not approve them: their
// purchases go to an approver instead of straight into stock.
const CAN_APPROVE = CONFIG.canApprove !== false;
// A shop that sells halves buys in halves (10½ crates); everyone else whole.
const HALVES = CONFIG.allowHalves === true;
const qtyOk = (qty) => {
    const n = Number(qty);
    if (! HALVES) return n >= 1;
    return n > 0 && Math.abs(n * 2 - Math.round(n * 2)) < 1e-6;
};
// On a phone the camera is the scanner, and the keyboard stays down until
// someone taps a field; the scan box is not pulled back into focus.
const TOUCH = isTouch();
const CAMERA = TOUCH || canDetectBarcodes();
const today = () => new Date().toLocaleDateString('en-CA');
const field = 'w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20';
const cell = 'no-spin w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-2 py-1.5 text-sm tabular-nums text-gray-900 dark:text-gray-100 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20';
// The figures someone types on a row: big enough to read back at a glance.
const entry = `${cell} h-10 px-2.5 text-right text-base font-semibold focus:bg-orange-50/60 dark:focus:bg-gray-700`;
const label = 'mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';

const emptyFooter = () => ({ supplierId: '', invoice: '', date: today(), notes: '' });

/**
 * Clicking or tabbing into a figure selects all of it, so typing 12 over a 1
 * gives 12, not 112 or 12-something. The mouseup that ends the click would
 * otherwise drop the selection again, so the first one after focus is held.
 */
const selectAll = {
    onFocus: (e) => { const el = e.target; el.dataset.justFocused = '1'; el.select(); },
    onMouseUp: (e) => { if (e.target.dataset.justFocused) { e.preventDefault(); delete e.target.dataset.justFocused; } },
    onBlur: (e) => { delete e.target.dataset.justFocused; },
};

/**
 * The Purchase Terminal. Built for a scanner in one hand and a box in the
 * other: the scan box always has the caret, whatever just happened — a line
 * added, a code not found, a modal closed — so the next scan always lands.
 *
 * Scans are answered from memory. The whole catalogue is loaded once, the way
 * the till loads its own, and every barcode, unit barcode and SKU is keyed in
 * a Map; the server is asked only about a code the Map does not know.
 */
export default function Terminal({ active, resume, onResumed, onSaved, refreshKey = 0 }) {
    const [lines, setLines]       = useState([]);
    const [footer, setFooter]     = useState(emptyFooter);
    const [draft, setDraft]       = useState(null); // { id, reference } when continuing one
    const [suppliers, setSuppliers] = useState([]);
    const [term, setTerm]         = useState('');
    const [results, setResults]   = useState([]);
    const [activeIdx, setActiveIdx] = useState(-1);
    const [flash, setFlash]       = useState(null);
    const [notice, setNotice]     = useState(null); // { type: 'ok' | 'error', text }
    const [errors, setErrors]     = useState({});
    const [modal, setModal]       = useState(null);
    const [busy, setBusy]         = useState(false);
    const [payError, setPayError] = useState('');
    const [pending, setPending]   = useState(0);
    const [catalogue, setCatalogue] = useState({ state: 'loading', count: 0 });
    const [camera, setCamera]     = useState(false);
    const [lastScan, setLastScan] = useState(null); // what the camera last added, for its banner
    const wide = useWide();

    const scanRef  = useRef(null);
    const products = useRef([]);        // the in-memory catalogue
    const index    = useRef(new Map()); // every code → { product, unitId }
    const searchId = useRef(0);
    const listRef  = useRef(null);
    // A word that found nothing, then a product found another way, teaches
    // the shop's search that word (shared/searchLearning.js).
    const learner  = useRef(createSearchLearner((productId, term) => {
        api.post('/search-shortcuts', { product_id: productId, term }).catch(() => {});
    })).current;

    const focusScan = useCallback(() => { if (!TOUCH) setTimeout(() => scanRef.current?.focus(), 0); }, []);
    const say = useCallback((type, text) => setNotice({ type, text, at: Date.now() }), []);

    // ── Catalogue ─────────────────────────────────────────────
    // Swapped in whole once every page has arrived, so a refresh never leaves
    // half an old catalogue and half a new one.
    const loadCatalogue = useCallback(async () => {
        try {
            const all = await fetchCatalogue();
            products.current = all;
            index.current = buildIndex(all);
            setCatalogue({ state: 'ready', count: all.length });
            warmSearch(all);
        } catch {
            // Scans still work, one server lookup at a time.
            setCatalogue((c) => ({ ...c, state: c.count ? 'ready' : 'offline' }));
        }
    }, []);

    useEffect(() => { loadCatalogue(); }, [loadCatalogue]);

    // ── Suppliers ─────────────────────────────────────────────
    const loadSuppliers = useCallback(() => {
        api.get('/suppliers').then(({ data }) => setSuppliers(data.suppliers)).catch(() => {});
    }, []);

    // Again whenever a purchase is saved or paid, so the balance owed is current.
    useEffect(() => { loadSuppliers(); }, [loadSuppliers, refreshKey]);

    // ── Focus discipline ──────────────────────────────────────
    useEffect(() => { if (active && !modal) focusScan(); }, [active, modal, focusScan]);

    // A key pressed while nothing that takes typing has focus — a button just
    // clicked, the page itself — belongs to the scan box. That is a scanner
    // firing, and its code must not be lost.
    useEffect(() => {
        if (!active) return undefined;
        const onKey = (e) => {
            if (modal || camera || e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1 || e.key === ' ') return;
            const el = document.activeElement;
            const typing = el && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable);
            if (!typing) scanRef.current?.focus();
        };
        window.addEventListener('keydown', onKey, true);
        return () => window.removeEventListener('keydown', onKey, true);
    }, [active, modal, camera]);

    // An unknown code read by the camera opens the create-product form, which
    // has to be in front: the camera steps aside for it.
    useEffect(() => { if (modal?.type === 'create') setCamera(false); }, [modal]);

    useEffect(() => {
        if (!notice) return undefined;
        const t = setTimeout(() => setNotice(null), notice.type === 'error' ? 6000 : 3500);
        return () => clearTimeout(t);
    }, [notice]);

    // ── A draft opened from History ───────────────────────────
    useEffect(() => {
        if (!resume) return;
        setLines(fromDetail(resume));
        setFooter({
            supplierId: resume.supplier_id ? String(resume.supplier_id) : '',
            invoice: resume.invoice_number ?? '',
            date: resume.purchased_on ?? today(),
            notes: resume.notes ?? '',
        });
        setDraft({ id: resume.id, reference: resume.reference, awaiting: !!resume.awaiting_approval, by: resume.recorded_by });
        setErrors({});
        onResumed();
        say('ok', resume.awaiting_approval ? `Reviewing ${resume.reference} before receiving it.` : `Continuing draft ${resume.reference}.`);
    }, [resume, onResumed, say]);

    // ── Adding ────────────────────────────────────────────────
    const add = useCallback((product) => {
        setLines((prev) => addProduct(prev, product).lines);
        setFlash({ id: product.id, unitId: product.scanned_unit_id ?? null, at: Date.now() });
        setLastScan({ name: product.name, at: Date.now() });
        setErrors({});
    }, []);

    const reset = useCallback(() => {
        setLines([]);
        setFooter(emptyFooter());
        setDraft(null);
        setErrors({});
        setTerm('');
        setResults([]);
        setActiveIdx(-1);
        focusScan();
    }, [focusScan]);

    // Name search as the person types — from memory when the catalogue is in,
    // from the server until then. A barcode is never searched this way: a
    // scanner types a whole code in milliseconds and Enter settles it.
    useEffect(() => {
        const q = term.trim();
        if (q.length < 2 || looksLikeCode(q)) { setResults([]); setActiveIdx(-1); return undefined; }

        if (catalogue.state === 'ready') {
            const found = searchLocal(products.current, q);
            setResults(found);
            learner.searched(q, found.length);
            setActiveIdx(-1);
            return undefined;
        }

        const id = ++searchId.current;
        const t = setTimeout(async () => {
            try {
                const { data } = await api.get('/products', { params: { q } });
                if (id === searchId.current) { setResults(rankResults(data.products, q)); setActiveIdx(-1); }
            } catch { /* the Enter path still works */ }
        }, 220);
        return () => clearTimeout(t);
    }, [term, catalogue.state]);

    const resolve = useCallback(async (code) => {
        // Answered on the spot when the code is known: no request at all.
        const known = resolveCode(index.current, code);
        if (known) { learner.reset(); add(known); focusScan(); return; }

        setPending((n) => n + 1);
        try {
            const { data } = await api.get('/lookup', { params: { code } });
            if (data.found) {
                // Learned, so the next scan of it is instant too.
                addToIndex(index.current, data.product);
                add(data.product);
                return;
            }

            if (looksLikeCode(code)) {
                setModal({ type: 'create', code });
                return;
            }

            // Typed a name and pressed Enter: one match is that product; several
            // stay listed so the next Enter takes the first.
            // A guess (a typo forgiven) is listed, never added on its own: the
            // name typed may be a new product that only looks like an old one.
            const hits = catalogue.state === 'ready'
                ? searchHits(products.current, code)
                : rankResults((await api.get('/products', { params: { q: code } })).data.products, code).map((product) => ({ product, fuzzy: false }));
            const found = hits.map((h) => h.product);

            if (found.length === 1 && !hits[0].fuzzy) {
                learner.picked(found[0], code, 0);
                add(found[0]);
            } else if (found.length > 0) {
                setTerm(code);
                setResults(found);
                setActiveIdx(0);
            } else {
                setModal({ type: 'create', code: '', name: code });
            }
        } catch (err) {
            say('error', errorMessage(err));
        } finally {
            setPending((n) => n - 1);
            focusScan();
        }
    }, [add, focusScan, say, catalogue.state]);

    // The arrow keys move the highlight; the list follows it, so a row
    // below the fold is seen before Enter takes it.
    useEffect(() => {
        if (activeIdx < 0) return;
        listRef.current?.children[activeIdx]?.scrollIntoView?.({ block: 'nearest' });
    }, [activeIdx]);

    const onScanKey = (e) => {
        if (e.key === 'ArrowDown' && results.length) { e.preventDefault(); setActiveIdx((i) => Math.min(results.length - 1, i + 1)); return; }
        if (e.key === 'ArrowUp' && results.length) { e.preventDefault(); setActiveIdx((i) => Math.max(-1, i - 1)); return; }
        if (e.key === 'Escape') { setTerm(''); setResults([]); setActiveIdx(-1); return; }
        if (e.key !== 'Enter') return;

        e.preventDefault();
        const code = term.trim();
        const picked = activeIdx >= 0 ? results[activeIdx] : null;

        // Cleared before anything else, so the next scan types into an empty
        // box instead of onto the end of this one.
        setTerm('');
        setResults([]);
        setActiveIdx(-1);

        if (picked) { learner.picked(picked, code, activeIdx); add(picked); return; }
        if (code) resolve(code);
    };

    const pick = (product) => {
        learner.picked(product, term, results.findIndex((p) => p.id === product.id));
        add(product);
        setTerm('');
        setResults([]);
        setActiveIdx(-1);
        focusScan();
    };

    // ── Editing lines ─────────────────────────────────────────
    const update = (key, patch) => setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)));
    const remove = (key) => { setLines((prev) => prev.filter((l) => l.key !== key)); focusScan(); };
    const backToScan = (e) => { if (e.key === 'Enter') { e.preventDefault(); focusScan(); } };

    const sum      = useMemo(() => totals(lines), [lines]);
    const setF     = (k) => (e) => setFooter((f) => ({ ...f, [k]: e.target.value }));
    const supplier = suppliers.find((s) => String(s.id) === String(footer.supplierId));

    // ── Saving ────────────────────────────────────────────────
    const problems = (receive) => {
        if (lines.length === 0) return 'Scan or add at least one product.';
        if (!footer.supplierId) return 'Choose the supplier.';
        if (lines.some((l) => ! qtyOk(l.qty))) return HALVES ? 'Every line needs a quantity, in halves if needed (½, 1, 1½ …).' : 'Every line needs a quantity of at least 1.';
        if (receive) {
            const bad = lines.find((l) => serialProblem(l));
            if (bad) return `${bad.product.name}: ${serialProblem(bad)}.`;
        }
        return null;
    };

    const save = async (action, payment = null) => {
        const problem = problems(action === 'receive');
        if (problem) { say('error', problem); setModal(null); focusScan(); return; }

        setBusy(true);
        setErrors({});
        setPayError('');
        try {
            const { data } = await api.post('/purchases', {
                action,
                id: draft?.id ?? null,
                supplier_id: Number(footer.supplierId),
                invoice_number: footer.invoice || null,
                purchased_on: footer.date || null,
                notes: footer.notes || null,
                payment,
                lines: toPayload(lines),
            });

            onSaved?.();
            loadSuppliers();

            if (action === 'receive') {
                setModal({ type: 'grn', purchase: data.purchase });
                // Stock, cost and prices have all moved: the next delivery
                // scans against the catalogue as it now stands.
                loadCatalogue();
            } else if (action === 'submit') {
                setModal(null);
                reset();
                say('ok', `${data.purchase.reference} sent for approval. A manager will receive it into stock.`);
            } else {
                setModal(null);
                reset();
                say('ok', `Draft ${data.purchase.reference} saved. Open it from Purchase History to continue.`);
            }
        } catch (err) {
            const fields = fieldErrors(err);
            const paymentProblem = Object.keys(fields).find((k) => k.startsWith('payment'));

            // A payment problem is answered in the payment modal, which stays
            // open; anything else is about the lines, so it goes back there.
            if (action === 'receive' && paymentProblem) {
                setPayError(fields[paymentProblem][0]);
            } else {
                setModal(null);
                setErrors(fields);
                say('error', errorMessage(err));
                focusScan();
            }
        } finally {
            setBusy(false);
        }
    };

    const closeModal = () => { setModal(null); setPayError(''); focusScan(); };

    // The same handlers for a table row and a phone card.
    const lineProps = (line, i) => ({
        line,
        index: i,
        flash: flash && flash.id === line.product.id && (flash.unitId ?? line.unitId) === line.unitId ? flash.at : null,
        errors,
        onChange: (patch) => update(line.key, patch),
        onUnit: (unitId) => setLines((prev) => prev.map((l) => (l.key === line.key ? changeUnit(l, unitId) : l))),
        onCost: (unit, value) => setLines((prev) => setUnitCost(prev, line.product.id, unit, value)),
        onPrice: (key, price, anchor) => setLines((prev) => setUnitPrice(prev, line.product.id, key, price, { propagate: line.propagate, step: STEP, anchor })),
        onPropagate: (on) => setLines((prev) => setPropagate(prev, line.product.id, on)),
        onRemove: () => remove(line.key),
        onEnter: backToScan,
    });

    const actionButtons = (
        <>
            <button type="button" disabled={busy} onClick={() => save('draft')} className="rounded-xl border-2 border-orange-500 py-3 text-sm font-bold text-orange-700 dark:text-orange-400 hover:bg-orange-50 dark:hover:bg-orange-950/40 disabled:opacity-50">
                Save Draft
            </button>
            {CAN_APPROVE ? (
                <button
                    type="button"
                    disabled={busy}
                    onClick={() => {
                        const problem = problems(true);
                        if (problem) { say('error', problem); focusScan(); return; }
                        setPayError('');
                        setModal({ type: 'pay' });
                    }}
                    className="rounded-xl bg-orange-600 py-3 text-sm font-extrabold text-white shadow-sm hover:bg-orange-700 disabled:opacity-50"
                >
                    Receive Stock
                </button>
            ) : (
                <button
                    type="button"
                    disabled={busy}
                    onClick={() => save('submit')}
                    className="rounded-xl bg-orange-600 py-3 text-sm font-extrabold text-white shadow-sm hover:bg-orange-700 disabled:opacity-50"
                >
                    Submit for Approval
                </button>
            )}
        </>
    );

    // ── Render ────────────────────────────────────────────────
    return (
        // Below lg the whole terminal scrolls as one page — scan bar pinned at
        // the top, total and buttons pinned at the bottom — rather than two
        // panes fighting for a phone's height. From lg it is the till's layout.
        <div className={`${active ? 'flex' : 'hidden'} flex-1 min-h-0 flex-col overflow-y-auto lg:flex-row lg:overflow-hidden`}>
            <div className="flex min-w-0 shrink-0 flex-col lg:flex-1 lg:shrink lg:overflow-hidden">
                {/* Scan bar */}
                <div className="sticky top-0 z-20 shrink-0 border-b border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 px-3 py-3 sm:px-4 lg:relative">
                    <div className="flex items-center gap-2">
                        <div className="relative flex-1">
                            <svg className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h1m2 0h1m3 0h2m2 0h1m2 0h1M4 18h1m2 0h1m3 0h2m2 0h1m2 0h1M4 6v12m16-12v12M8 9v6m4-6v6m4-6v6" />
                            </svg>
                            <input
                                ref={scanRef}
                                autoFocus={!TOUCH}
                                value={term}
                                onChange={(e) => setTerm(e.target.value)}
                                onKeyDown={onScanKey}
                                placeholder={wide ? 'Scan barcode, or type a product name…' : 'Scan or search…'}
                                aria-label="Scan barcode or search products"
                                autoComplete="off"
                                spellCheck={false}
                                className="w-full rounded-xl border-2 border-orange-300 dark:border-orange-800 bg-orange-50/40 dark:bg-gray-800 py-3 pl-11 pr-28 text-base font-medium text-gray-900 dark:text-gray-100 outline-none focus:border-orange-500 focus:ring-4 focus:ring-orange-500/20 max-sm:pr-10"
                            />
                            <span className="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] font-semibold">
                                {pending > 0 ? (
                                    <span className="text-orange-600">Looking up…</span>
                                ) : catalogue.state === 'loading' ? (
                                    <span className="text-gray-400">… <span className="max-sm:hidden">Loading products</span></span>
                                ) : catalogue.state === 'ready' ? (
                                    <span className="text-green-600" title="Scans are answered instantly from this list">● <span className="max-sm:hidden">{catalogue.count.toLocaleString('en-NG')} products</span></span>
                                ) : (
                                    <span className="text-amber-600">● <span className="max-sm:hidden">Online lookup</span></span>
                                )}
                            </span>
                        </div>
                        {CAMERA && (
                            <button
                                type="button"
                                onClick={() => setCamera(true)}
                                aria-label="Scan with camera"
                                title="Scan with camera"
                                className="flex h-[52px] w-[52px] shrink-0 items-center justify-center rounded-xl bg-orange-600 text-white shadow-sm active:scale-95"
                            >
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </button>
                        )}
                    </div>

                    {results.length > 0 && (
                        <ul ref={listRef} role="listbox" className="absolute left-4 right-4 top-full z-30 mt-1 max-h-80 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-xl">
                            {results.map((p, i) => (
                                <li key={p.id} role="option" aria-selected={i === activeIdx}>
                                    <button
                                        type="button"
                                        onMouseDown={(e) => e.preventDefault()}
                                        onClick={() => pick(p)}
                                        className={`flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-sm ${i === activeIdx ? 'bg-orange-50 dark:bg-orange-950/50' : 'hover:bg-gray-50 dark:hover:bg-gray-800'}`}
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate font-semibold text-gray-800 dark:text-gray-100">{p.name}</span>
                                            <span className="block truncate font-mono text-[11px] text-gray-400">{p.barcode || p.sku || '—'}</span>
                                        </span>
                                        <span className="shrink-0 text-right">
                                            <span className="block text-xs tabular-nums text-gray-500">{fmt(p.price)}</span>
                                            <StockLeft product={p} className="block text-[11px]" />
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    {notice && (
                        <div role={notice.type === 'error' ? 'alert' : 'status'} className={`mt-2 rounded-lg px-3 py-2 text-xs font-semibold ${notice.type === 'error' ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' : 'bg-green-50 text-green-700 dark:bg-green-950/50 dark:text-green-300'}`}>
                            {notice.text}
                        </div>
                    )}
                </div>

                {draft && (
                    <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-orange-100 dark:border-orange-900 bg-orange-50 dark:bg-orange-950/40 px-4 py-2 text-xs text-orange-800 dark:text-orange-300">
                        {draft.awaiting ? (
                            <span>Reviewing <strong>{draft.reference}</strong>{draft.by ? `, submitted by ${draft.by}` : ''} — correct it if needed, then Receive Stock.</span>
                        ) : (
                            <span>Continuing draft <strong>{draft.reference}</strong></span>
                        )}
                        <button type="button" onClick={reset} className="ml-auto font-semibold hover:underline">Start a new purchase instead</button>
                    </div>
                )}

                {/* Lines */}
                <div className="bg-white dark:bg-gray-900 lg:min-h-0 lg:flex-1 lg:overflow-auto">
                    {lines.length === 0 ? (
                        <div className="flex min-h-[35vh] flex-col items-center justify-center gap-2 p-8 text-center lg:h-full">
                            <p className="text-sm font-semibold text-gray-500 dark:text-gray-400">
                                {TOUCH ? 'Tap the camera to scan the first item.' : 'Scan the first item to start.'}
                            </p>
                            <p className="text-xs text-gray-400">Same item twice adds one to its line. An unknown barcode lets you create the product.</p>
                        </div>
                    ) : !wide ? (
                        // A phone: one card per line, thumbs-sized controls.
                        <div className="divide-y divide-gray-100 dark:divide-gray-800">
                            {lines.map((line, i) => <LineCard key={line.key} {...lineProps(line, i)} />)}
                        </div>
                    ) : (
                        // Fixed layout and no minimum width: a row always fits
                        // the screen. The product column takes what is left and
                        // truncates; the figures that belong together share a
                        // cell (cost with its per-base cost, price with margin).
                        <table className="w-full table-fixed text-sm">
                            <thead className="sticky top-0 z-10 bg-gray-50 dark:bg-gray-800 text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th className="px-3 py-2 text-left">Product</th>
                                    <th className="w-[6.5rem] px-1 py-2 text-left xl:w-32">Unit</th>
                                    <th className="w-20 px-1 py-2 text-right xl:w-24">Qty</th>
                                    <th className="w-28 px-1 py-2 text-right xl:w-32">Cost / unit</th>
                                    <th className="w-24 px-1 py-2 text-right xl:w-28">Total</th>
                                    <th className="w-24 px-1 py-2 text-right xl:w-28">Sell · margin</th>
                                    <th className="w-[4.5rem]" />
                                </tr>
                            </thead>
                            <tbody>
                                {lines.map((line, i) => <LineRow key={line.key} {...lineProps(line, i)} />)}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            {/* Footer panel — the till's right-hand column */}
            <aside className="flex w-full shrink-0 flex-col border-t lg:border-t-0 lg:border-l border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 lg:w-[300px] lg:overflow-y-auto xl:w-[340px]">
                <div className="space-y-3 p-4">
                    <div>
                        <label className={label} htmlFor="supplier">Supplier</label>
                        <div className="flex gap-2">
                            <select id="supplier" className={field} value={footer.supplierId} onChange={setF('supplierId')}>
                                <option value="">Choose supplier…</option>
                                {suppliers.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}{s.balance > 0 ? ` — owed ${fmt(s.balance)}` : ''}
                                    </option>
                                ))}
                            </select>
                            <button type="button" onClick={() => setModal({ type: 'supplier' })} className="shrink-0 rounded-xl border border-orange-300 px-3 text-sm font-bold text-orange-700 dark:text-orange-400 hover:bg-orange-50 dark:hover:bg-orange-950/40" title="Add a supplier">+ New</button>
                        </div>
                        {supplier && (
                            <p className={`mt-1 text-xs ${supplier.balance > 0 ? 'font-semibold text-red-600' : 'text-gray-400'}`}>
                                {supplier.balance > 0 ? `You already owe ${supplier.name} ${fmt(supplier.balance)}` : `Nothing owed to ${supplier.name}`}
                            </p>
                        )}
                        {errors.supplier_id && <p className="mt-1 text-xs text-red-600">{errors.supplier_id[0]}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <div>
                            <label className={label} htmlFor="invoice">Invoice / Ref no</label>
                            <input id="invoice" className={field} value={footer.invoice} onChange={setF('invoice')} />
                        </div>
                        <div>
                            <label className={label} htmlFor="pdate">Date</label>
                            <input id="pdate" type="date" max={today()} className={field} value={footer.date} onChange={setF('date')} />
                        </div>
                    </div>
                    <div>
                        <label className={label} htmlFor="notes">Notes</label>
                        <textarea id="notes" rows={2} className={field} value={footer.notes} onChange={setF('notes')} />
                    </div>
                </div>

                <div className="mt-auto hidden space-y-2 border-t-2 border-gray-100 dark:border-gray-800 p-4 lg:block">
                    <div className="flex justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>{lines.length} line{lines.length === 1 ? '' : 's'}</span>
                        <span>{sum.units.toLocaleString('en-NG')} base units</span>
                    </div>
                    <div className="flex items-baseline justify-between">
                        <span className="text-lg font-bold text-gray-700 dark:text-gray-300" style={{ fontFamily: 'Montserrat, sans-serif' }}>TOTAL</span>
                        <span className="font-mono text-3xl font-extrabold tabular-nums text-gray-900 dark:text-gray-100">{fmt(sum.total)}</span>
                    </div>

                    <div className="grid grid-cols-2 gap-2 pt-2">{actionButtons}</div>
                    <p className="text-center text-[11px] text-gray-400">
                        {CAN_APPROVE ? "You'll be asked how it was paid." : 'Stock goes in once a manager approves it.'}
                    </p>
                    {lines.length > 0 && (
                        <button type="button" onClick={() => { if (window.confirm('Clear every line from this purchase?')) reset(); }} className="w-full pt-1 text-xs font-semibold text-gray-400 hover:text-red-600">
                            Clear purchase
                        </button>
                    )}
                </div>
            </aside>

            {/* Phones: the total and the two buttons, always in reach. */}
            <div className="sticky bottom-0 z-20 mt-auto shrink-0 border-t border-gray-200 dark:border-gray-800 bg-white/95 dark:bg-gray-900/95 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-2 shadow-[0_-4px_12px_rgba(0,0,0,0.06)] backdrop-blur lg:hidden">
                <div className="mb-2 flex items-baseline justify-between">
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        {lines.length} line{lines.length === 1 ? '' : 's'} · {sum.units.toLocaleString('en-NG')} units
                    </span>
                    <span className="font-mono text-2xl font-extrabold tabular-nums text-gray-900 dark:text-gray-100">{fmt(sum.total)}</span>
                </div>
                <div className="grid grid-cols-[2fr_3fr] gap-2">{actionButtons}</div>
            </div>

            {camera && (
                <CameraScanner
                    onCode={resolve}
                    onClose={() => setCamera(false)}
                    last={lastScan}
                    count={lines.length}
                />
            )}

            {modal?.type === 'create' && (
                <CreateProductModal
                    code={modal.code}
                    name={modal.name}
                    onClose={closeModal}
                    onCreated={(product) => {
                        addToIndex(index.current, product);
                        products.current = [...products.current, product];
                        add(product);
                        say('ok', `${product.name} created and added.`);
                        closeModal();
                        loadCatalogue();
                    }}
                />
            )}
            {modal?.type === 'supplier' && (
                <SupplierModal
                    onClose={closeModal}
                    onCreated={(s) => {
                        setSuppliers((list) => [...list, { balance: 0, ...s }].sort((a, b) => a.name.localeCompare(b.name)));
                        setFooter((f) => ({ ...f, supplierId: String(s.id) }));
                        closeModal();
                    }}
                />
            )}
            {modal?.type === 'pay' && (
                <PaymentModal
                    title={`Receive stock into ${CONFIG.storeName ?? 'this branch'}`}
                    due={sum.total}
                    allowNone
                    busy={busy}
                    error={payError}
                    confirmLabel="Receive Stock"
                    onClose={closeModal}
                    onConfirm={(payment) => save('receive', payment)}
                />
            )}
            {modal?.type === 'grn' && (
                <GrnModal purchase={modal.purchase} storeName={CONFIG.storeName} onClose={() => { setModal(null); reset(); }} />
            )}
        </div>
    );
}

/**
 * A number input that holds what is typed while it has focus, and reports
 * each valid value as it goes. Without it a field showing a worked-out figure
 * (a carton's cost from the piece cost) would rewrite itself mid-keystroke.
 */
function NumField({ value, onCommit, onFocus, className = '', ...rest }) {
    const [text, setText] = useState(null);

    return (
        <input
            type="number"
            min="0"
            step="0.01"
            inputMode="decimal"
            {...rest}
            className={className}
            value={text ?? (Number.isFinite(Number(value)) ? value : '')}
            onFocus={(e) => { setText(String(value ?? '')); selectAll.onFocus(e); onFocus?.(e); }}
            onMouseUp={selectAll.onMouseUp}
            onBlur={(e) => { setText(null); selectAll.onBlur(e); }}
            onChange={(e) => {
                setText(e.target.value);
                if (e.target.value !== '' && Number.isFinite(Number(e.target.value))) onCommit(Number(e.target.value));
            }}
        />
    );
}

function LineRow({ line, index, flash, errors, onChange, onUnit, onCost, onPrice, onPropagate, onRemove, onEnter }) {
    const c = calc(line);
    const units = unitsFor(line.product);
    const serialIssue = serialProblem(line);
    const lineErrors = Object.entries(errors).filter(([k]) => k.startsWith(`lines.${index}.`)).map(([, v]) => v[0]);
    const lit = useFlash(flash);

    // The panel opens itself on a loss, so the problem and the fix are seen
    // together; otherwise it is one tap away.
    const showPrices = line.showPrices || c.warn;
    const extra = showPrices || line.product.track_serial || lineErrors.length > 0;

    return (
        <>
            <tr className={`border-b ${extra ? 'border-transparent' : 'border-gray-100 dark:border-gray-800'} transition-colors ${lit ? 'bg-orange-100 dark:bg-orange-900/40' : ''}`}>
                <td className="min-w-0 px-3 py-2 align-middle">
                    <div className="truncate font-semibold text-gray-800 dark:text-gray-100" title={line.product.name}>{line.product.name}</div>
                    <div className="truncate font-mono text-[11px] text-gray-400">{line.product.barcode || line.product.sku || '—'}</div>
                    <StockLeft product={line.product} adding={c.baseQty} className="mt-0.5 block truncate text-[11px]" />
                </td>
                <td className="px-1 py-2">
                    <select
                        className={`${cell} h-10`}
                        value={line.unitId ?? ''}
                        onChange={(e) => onUnit(e.target.value === '' ? null : Number(e.target.value))}
                        aria-label={`Unit for ${line.product.name}`}
                    >
                        {units.map((u) => (
                            <option key={u.id ?? 'base'} value={u.id ?? ''}>{u.name}{u.factor > 1 ? ` (${u.factor})` : ''}</option>
                        ))}
                    </select>
                </td>
                <td className="px-1 py-2">
                    <input type="number" min={HALVES ? 0.5 : 1} step={HALVES ? 0.5 : 1} inputMode={HALVES ? 'decimal' : 'numeric'} className={entry} value={line.qty}
                        {...selectAll}
                        onChange={(e) => onChange({ qty: e.target.value })} onKeyDown={onEnter} aria-label={`Quantity of ${line.product.name}`} />
                </td>
                <td className="px-1 py-2">
                    <NumField className={entry} value={c.cost}
                        onCommit={(v) => onCost(c.unit, v)} onKeyDown={onEnter} aria-label={`Cost per ${c.unit.name} of ${line.product.name}`} />
                    {c.factor > 1 && (
                        <div className="mt-0.5 truncate text-right text-[10px] tabular-nums text-gray-400">{fmt(c.costBase)} / {line.product.base_unit || 'base'}</div>
                    )}
                </td>
                <td className="truncate px-1 py-2 text-right text-base font-bold tabular-nums text-gray-900 dark:text-gray-100" title={fmt(c.lineTotal)}>{fmt(c.lineTotal)}</td>
                <td className="px-1 py-2 text-right tabular-nums">
                    <div className="truncate text-gray-700 dark:text-gray-300">{fmt(c.sell)}</div>
                    <div className={`truncate text-[11px] ${c.margin === null || c.margin <= 0 ? 'font-bold text-red-600' : 'text-green-700 dark:text-green-400'}`}>
                        {fmt(c.profit)} · {c.margin === null ? '—' : `${c.margin}%`}
                    </div>
                </td>
                <td className="px-1 py-2">
                    <div className="flex items-center justify-end gap-0.5">
                        <button
                            type="button"
                            onClick={() => onChange({ showPrices: !line.showPrices })}
                            aria-expanded={showPrices}
                            title="Prices for every unit"
                            className={`rounded-lg px-1.5 py-1 text-[11px] font-bold ${c.warn ? 'bg-red-50 text-red-700 dark:bg-red-950/40' : 'text-orange-700 hover:bg-orange-50 dark:text-orange-400 dark:hover:bg-orange-950/40'}`}
                        >
                            ₦{showPrices ? '▴' : '▾'}
                        </button>
                        <button type="button" onClick={onRemove} aria-label={`Remove ${line.product.name}`} className="h-7 w-7 shrink-0 rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40">✕</button>
                    </div>
                </td>
            </tr>
            {extra && (
                <tr className="border-b border-gray-100 dark:border-gray-800">
                    <td colSpan={7} className="px-3 pb-3">
                        {showPrices && (
                            <PricesPanel line={line} rows={c.rows} warn={c.warn} onCost={onCost} onPrice={onPrice} onPropagate={onPropagate} onEnter={onEnter} />
                        )}
                        {line.product.track_serial && (
                            <div className="mt-2 flex items-start gap-2">
                                <textarea
                                    rows={Math.min(4, Math.max(1, c.baseQty))}
                                    value={(line.serials ?? []).join('\n')}
                                    onChange={(e) => onChange({ serials: e.target.value.split('\n') })}
                                    placeholder={`Serial / IMEI numbers — one per line, ${c.baseQty} needed`}
                                    className={`${cell} font-mono text-xs ${serialIssue ? 'border-amber-400' : 'border-green-400'}`}
                                    aria-label={`Serial numbers for ${line.product.name}`}
                                />
                                <span className={`shrink-0 pt-1.5 text-[11px] font-semibold ${serialIssue ? 'text-amber-600' : 'text-green-600'}`}>
                                    {serialIssue ?? `${c.baseQty} / ${c.baseQty} ✓`}
                                </span>
                            </div>
                        )}
                        {lineErrors.map((m) => <p key={m} className="mt-1 text-xs font-semibold text-red-600">{m}</p>)}
                    </td>
                </tr>
            )}
        </>
    );
}

/** A line just added or scanned again lights up for a moment. */
function useFlash(flash) {
    const [lit, setLit] = useState(false);

    useEffect(() => {
        if (!flash) return undefined;
        setLit(true);
        const t = setTimeout(() => setLit(false), 600);
        return () => clearTimeout(t);
    }, [flash]);

    return lit;
}

/**
 * One purchase line on a phone: the same fields as a table row, stacked, with
 * a stepper for the quantity so a box can be counted in with a thumb.
 */
function LineCard({ line, index, flash, errors, onChange, onUnit, onCost, onPrice, onPropagate, onRemove, onEnter }) {
    const c = calc(line);
    const units = unitsFor(line.product);
    const serialIssue = serialProblem(line);
    const lineErrors = Object.entries(errors).filter(([k]) => k.startsWith(`lines.${index}.`)).map(([, v]) => v[0]);
    const lit = useFlash(flash);
    const showPrices = line.showPrices || c.warn;
    // A shop that sells halves steps in halves: ½, 1, 1½ …
    const unitStep = HALVES ? 0.5 : 1;
    const qty = Math.max(unitStep, Math.round((Number(line.qty) || 0) / unitStep) * unitStep);
    const step = 'flex h-11 w-11 shrink-0 items-center justify-center text-xl font-bold text-orange-700 active:bg-orange-50 disabled:text-gray-300 dark:text-orange-400';

    return (
        <div className={`px-3 py-3 transition-colors ${lit ? 'bg-orange-100 dark:bg-orange-900/40' : ''}`}>
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <div className="truncate font-semibold text-gray-800 dark:text-gray-100">{line.product.name}</div>
                    <div className="truncate font-mono text-[11px] text-gray-400">{line.product.barcode || line.product.sku || '—'}</div>
                    <StockLeft product={line.product} adding={c.baseQty} className="mt-0.5 block truncate text-[11px]" />
                </div>
                <button type="button" onClick={onRemove} aria-label={`Remove ${line.product.name}`} className="-mr-1 h-10 w-10 shrink-0 rounded-lg text-lg text-gray-400 active:bg-red-50 active:text-red-600">✕</button>
            </div>

            <div className="mt-2 grid grid-cols-[minmax(0,1fr)_auto] gap-2">
                <select
                    className={`${cell} h-11`}
                    value={line.unitId ?? ''}
                    onChange={(e) => onUnit(e.target.value === '' ? null : Number(e.target.value))}
                    aria-label={`Unit for ${line.product.name}`}
                >
                    {units.map((u) => (
                        <option key={u.id ?? 'base'} value={u.id ?? ''}>{u.name}{u.factor > 1 ? ` (${u.factor})` : ''}</option>
                    ))}
                </select>
                <div className="flex items-center overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                    <button type="button" className={step} disabled={qty <= unitStep} onClick={() => onChange({ qty: qty - unitStep })} aria-label={`One less ${line.product.name}`}>−</button>
                    <input
                        type="number" min={unitStep} step={unitStep} inputMode={HALVES ? 'decimal' : 'numeric'}
                        className="no-spin h-11 w-16 border-x border-gray-200 bg-white text-center text-lg font-bold tabular-nums text-gray-900 outline-none focus:bg-orange-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                        value={line.qty}
                        {...selectAll}
                        onChange={(e) => onChange({ qty: e.target.value })}
                        onKeyDown={onEnter}
                        aria-label={`Quantity of ${line.product.name}`}
                    />
                    <button type="button" className={step} onClick={() => onChange({ qty: qty + unitStep })} aria-label={`One more ${line.product.name}`}>+</button>
                </div>
            </div>

            <div className="mt-2 grid grid-cols-2 gap-2">
                <label className="block">
                    <span className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">Cost / {c.unit.name}</span>
                    <NumField className={`${cell} h-11 text-right text-base`} value={c.cost}
                        onCommit={(v) => onCost(c.unit, v)} onKeyDown={onEnter} aria-label={`Cost per ${c.unit.name} of ${line.product.name}`} />
                    {c.factor > 1 && (
                        <span className="mt-0.5 block text-right text-[10px] tabular-nums text-gray-400">{fmt(c.costBase)} / {line.product.base_unit || 'base'}</span>
                    )}
                </label>
                <div className="text-right">
                    <span className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">Line total</span>
                    <div className="flex h-11 items-center justify-end font-mono text-lg font-extrabold tabular-nums text-gray-900 dark:text-gray-100">{fmt(c.lineTotal)}</div>
                </div>
            </div>

            <div className="mt-2 flex items-center justify-between gap-2">
                <div className="min-w-0 truncate text-xs tabular-nums">
                    <span className="text-gray-500">Sells {fmt(c.sell)}</span>
                    <span className={c.margin === null || c.margin <= 0 ? 'font-bold text-red-600' : 'text-green-700 dark:text-green-400'}>
                        {' '}· {fmt(c.profit)} · {c.margin === null ? '—' : `${c.margin}%`}
                    </span>
                </div>
                <button
                    type="button"
                    onClick={() => onChange({ showPrices: !line.showPrices })}
                    aria-expanded={showPrices}
                    className={`shrink-0 rounded-lg px-3 py-2 text-xs font-bold ${c.warn ? 'bg-red-50 text-red-700 dark:bg-red-950/40' : 'bg-orange-50 text-orange-700 dark:bg-orange-950/40 dark:text-orange-400'}`}
                >
                    Prices {showPrices ? '▴' : '▾'}
                </button>
            </div>

            {showPrices && (
                <div className="mt-2">
                    <PricesPanel line={line} rows={c.rows} warn={c.warn} onCost={onCost} onPrice={onPrice} onPropagate={onPropagate} onEnter={onEnter} />
                </div>
            )}

            {line.product.track_serial && (
                <div className="mt-2">
                    <textarea
                        rows={Math.min(4, Math.max(2, c.baseQty))}
                        value={(line.serials ?? []).join('\n')}
                        onChange={(e) => onChange({ serials: e.target.value.split('\n') })}
                        placeholder={`Serial / IMEI numbers — one per line, ${c.baseQty} needed`}
                        className={`${cell} font-mono text-xs ${serialIssue ? 'border-amber-400' : 'border-green-400'}`}
                        aria-label={`Serial numbers for ${line.product.name}`}
                    />
                    <span className={`text-[11px] font-semibold ${serialIssue ? 'text-amber-600' : 'text-green-600'}`}>
                        {serialIssue ?? `${c.baseQty} / ${c.baseQty} ✓`}
                    </span>
                </div>
            )}
            {lineErrors.map((m) => <p key={m} className="mt-1 text-xs font-semibold text-red-600">{m}</p>)}
        </div>
    );
}

/**
 * Every unit the product comes in: what it costs (from the one base cost) and
 * what it sells for. Typing a cost for any unit resets the base cost, so all
 * the costs move; typing a selling price moves the others by the same % when
 * the box is ticked, rounded to the shop's step. Saved on Receive Stock.
 */
function PricesPanel({ line, rows, warn, onCost, onPrice, onPropagate, onEnter }) {
    // The prices as they stood when someone started typing one, so a % change
    // is measured from there rather than compounding keystroke by keystroke.
    const anchor = useRef(null);

    return (
        <div className={`rounded-xl border ${warn ? 'border-red-200 dark:border-red-900' : 'border-gray-200 dark:border-gray-700'} bg-gray-50/60 dark:bg-gray-800/40 p-3`}>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <span className="text-[11px] font-bold uppercase tracking-wide text-gray-500">Prices · {line.product.name}</span>
                <label className="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                    <input type="checkbox" checked={line.propagate} onChange={(e) => onPropagate(e.target.checked)} className="h-4 w-4 accent-orange-600" />
                    Update other units by same %{STEP > 0 ? ` (to nearest ₦${STEP})` : ''}
                </label>
            </div>
            <table className="w-full table-fixed text-sm">
                <thead className="text-[10px] uppercase tracking-wide text-gray-400">
                    <tr>
                        <th className="py-1 text-left">Unit</th>
                        <th className="w-12 py-1 text-right max-sm:hidden">×</th>
                        <th className="w-[5.5rem] py-1 text-right sm:w-28">Cost</th>
                        <th className="w-[5.5rem] py-1 text-right sm:w-28">Selling</th>
                        <th className="w-12 py-1 text-right sm:w-16">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.key} className={r.warn ? 'text-red-700 dark:text-red-400' : ''}>
                            <td className="truncate py-1 font-semibold">
                                {r.unit.name}
                                {r.unit.is_base
                                    ? <span className="ml-1 text-[10px] font-normal text-gray-400">base</span>
                                    : <span className="ml-1 text-[10px] font-normal text-gray-400 sm:hidden">×{r.unit.factor}</span>}
                            </td>
                            <td className="py-1 text-right tabular-nums text-gray-500 max-sm:hidden">{r.unit.factor}</td>
                            <td className="py-1 pl-2">
                                <NumField className={`${cell} text-right`} value={r.cost} onCommit={(v) => onCost(r.unit, v)} onKeyDown={onEnter}
                                    aria-label={`Cost per ${r.unit.name}`} />
                            </td>
                            <td className="py-1 pl-2">
                                <NumField className={`${cell} text-right ${r.warn ? 'border-red-400' : ''}`} value={r.price}
                                    onFocus={() => { anchor.current = { ...line.prices }; }}
                                    onCommit={(v) => onPrice(r.key, v, anchor.current ?? line.prices)}
                                    onKeyDown={onEnter}
                                    aria-label={`Selling price per ${r.unit.name}`} />
                            </td>
                            <td className={`py-1 text-right tabular-nums ${r.warn ? 'font-bold' : 'text-green-700 dark:text-green-400'}`}>
                                {r.margin === null ? '—' : `${r.margin}%`}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {warn && (
                <p className="mt-2 rounded-lg bg-red-50 dark:bg-red-950/40 px-3 py-1.5 text-xs font-semibold text-red-700 dark:text-red-300">
                    ⚠ A unit sells at or below what it costs. Raise its selling price before receiving.
                </p>
            )}
        </div>
    );
}

/**
 * What is left of a product on this branch's shelf, in base units — and, on a
 * line, what it will be once this delivery is received. Red at or below zero:
 * a product the till has been selling ahead of its stock.
 */
function StockLeft({ product, adding = null, className = '' }) {
    if (product?.stock == null) return null;

    const now  = Number(product.stock);
    const unit = product.base_unit || 'unit';
    const n    = (v) => Number(v).toLocaleString('en-NG');

    return (
        <span className={`${className} tabular-nums ${now <= 0 ? 'font-semibold text-red-600' : 'text-gray-500 dark:text-gray-400'}`}>
            {adding == null ? (
                <>{n(now)} in {CONFIG.storeName || 'branch'}</>
            ) : (
                <>In {CONFIG.storeName || 'this branch'}: {n(now)} {unit}{adding > 0 && <span className="text-green-700 dark:text-green-400"> → {n(now + adding)} after</span>}</>
            )}
        </span>
    );
}
