import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { liveQuery } from 'dexie';
import { isTouch, useWide } from '../../purchases/lib/device';
import { again, good, tick, unknown as unknownSound, warn } from '../lib/feedback';
import { cameraByDefault, cameraMode } from '../lib/camera';
import { plusOne, press } from '../lib/keypad';
import { stillToCount } from '../lib/missing';
import { useSyncStatus } from '../lib/syncStatus';
import { anyTyped, formatQty, key, mergeBreakdown, plural, spell, toBreakdown, totalOf, unitRows, usesPacks } from '../lib/units';
import { useWakeLock } from '../lib/wakeLock';
import { lookupCode, search } from '../offline/lookup';
import { entriesFor, removeCount, saveCount } from '../offline/outbox';
import EntryPanel, { badOf, cardReady } from './EntryPanel';
import Lists from './Lists';
import NeedsAttention from './NeedsAttention';
import Sheet from './Sheet';
import SyncChip from './SyncChip';
import UnknownItemForm from './UnknownItemForm';
import Viewfinder from './Viewfinder';

const TOUCH = isTouch();

/** Reading pauses this long after each key press, so the camera can't act while a thumb is typing. */
const TYPING_PAUSE_MS = 700;

/** A hand-held scanner types faster than this per key; a person, slower. */
const WEDGE_MS_PER_KEY = 30;

/** At this width and up, a desk: the lists sit beside the counting (B8). */
const DESK = '(min-width: 900px)';

/**
 * Counting: one screen for the whole loop, never left.
 *
 *   ┌───────────────┐  header: back · aisle (tap to switch) · 34 / 60 · sync chip · Tap-count
 *   │    camera     │  on the whole time, started once; torch and sound buttons
 *   ├───────────────┤
 *   │ product, units│  the thumb's half: a product opened by a scan, with a
 *   │    keypad     │  keypad; otherwise the barcode box, search, what's next
 *   └───────────────┘  on the shelf, and the lists (a sheet: swipe up).
 *
 * - **Scan next to save:** with a number typed, scanning the next product
 *   saves this one and opens that one. Most products need no Save tap.
 * - **Tap-to-count:** every scan of the open product adds 1.
 * - **Counted before here:** [+ Add] / [Replace] in the panel. Typing adds.
 * - **No "Are you sure?":** each save or take-back shows Undo for 5 seconds.
 * - **Needs attention:** what the server refused, in a list opened from the
 *   sync chip, never a pop-up in the middle of counting.
 *
 * Everything is on the phone (release 2): a scan is a lookup in the task
 * pack, a save is written to the phone's store, the upload follows in the
 * background. The screen moves on before the phone's store has even answered.
 * The screen stays on while counting.
 *
 * At a desk (900 px and wider): the lists sit on the right, always showing.
 * There's no camera unless asked for: a hand-held scanner types into the
 * barcode box, which stays focused. Digits typed by hand go straight into the
 * quantity, and Enter saves.
 */
export default function CountScreen({ session, aisle, aisles = [], onAisle, onWhere = null, blocked = false, recount = null, onBack, onChanged, onHandIn }) {
    const { taskId, pack, index, clockOffset, syncer } = session;
    const allowHalves = !!pack.task.allow_halves;
    // Count and map in one walk: nothing is expected anywhere, so nothing is "missing" or "not on the list".
    const quick = !!pack.task.quick || !!aisle?.quick;
    const mode = useMemo(() => cameraMode(), []);
    const wide = useWide(DESK);
    const status = useSyncStatus(taskId);
    useWakeLock(true);

    const [cameraOn, setCameraOn]         = useState(() => mode !== 'none' && cameraByDefault());
    const [cameraState, setCameraState]   = useState(null);
    const [rows, setRowsState]            = useState(null);
    const [card, setCardState]            = useState(null);
    const [unknownItem, setUnknownState]  = useState(null);
    const [toast, setToast]               = useState(null);
    const [tapMode, setTapMode]           = useState(false);
    const [code, setCode]                 = useState('');
    const [query, setQuery]               = useState('');
    const [sheet, setSheet]               = useState(null);
    const [tab, setTab]                   = useState('counted');

    // Read by the scan handler, which can run twice before React re-renders.
    const rowsRef    = useRef(null);
    const cardRef    = useRef(null);
    const unknownRef = useRef(null);
    const aisleRef   = useRef(aisle);
    aisleRef.current = aisle;
    // "Are you still here?" is up: scans wait for the answer.
    const blockedRef = useRef(blocked);
    blockedRef.current = blocked;

    const pauseUntil = useRef(0);
    const writes     = useRef(Promise.resolve());
    const scanRef    = useRef(null);
    const keys       = useRef({ n: 0, first: 0, last: 0 });
    const beforeTyping = useRef(null);
    const swipe      = useRef(null);

    const setRows    = (next) => { rowsRef.current = next; setRowsState(next); };
    const setCard    = (next) => { cardRef.current = next; beforeTyping.current = null; setCardState(next); };
    const setUnknown = (next) => { unknownRef.current = next; setUnknownState(next); };

    const expected = useMemo(
        () => new Set(pack.task.aisles.find((a) => a.id === aisle?.id)?.expected ?? []),
        [pack, aisle],
    );
    const recountIds = useMemo(() => new Set(pack.task.recount ?? []), [pack]);
    const labelOf = (aisleId) => aisles.find((a) => a.id === aisleId)?.label ?? recount?.aisleLabel?.(aisleId) ?? '';
    const nameOf = (productId) => index.products.get(productId)?.name ?? `#${productId}`;

    const say = (text, tone = 'ok', undo = null) => setToast({ text, tone, undo, at: `${Date.now()}-${Math.random()}` });

    useEffect(() => {
        if (!toast) return undefined;
        const t = setTimeout(() => setToast(null), toast.undo ? 5000 : 3500);
        return () => clearTimeout(t);
    }, [toast]);

    const refocus = useCallback(() => { if (!TOUCH) scanRef.current?.focus(); }, []);

    /** What has been counted on this task, from the phone's own store, kept up to date. */
    const load = useCallback(async () => {
        const fresh = await entriesFor(taskId);
        rowsRef.current = fresh;
        setRowsState(fresh);
    }, [taskId]);

    useEffect(() => {
        const sub = liveQuery(() => entriesFor(taskId)).subscribe({
            next: (fresh) => { rowsRef.current = fresh; setRowsState(fresh); },
            error: () => {},
        });
        return () => sub.unsubscribe();
    }, [taskId]);

    useEffect(() => { refocus(); }, [refocus, aisle, card]);

    // --- Writing, in order, after the screen has already moved on ----------

    const write = (fn) => {
        const done = writes.current.then(fn);
        writes.current = done.catch(() => {});
        done.then(
            () => { onChanged?.(); syncer?.nudge(taskId); },
            () => { warn(); say('Could not save on this phone. Is its storage full?', 'error'); load(); },
        );
    };

    const same = (r, aisleId, productId) => r.aisleId === aisleId && r.productId === productId;
    const putRow  = (row) => setRows([row, ...(rowsRef.current ?? []).filter((r) => !same(r, row.aisleId, row.productId))]);
    const dropRow = (aisleId, productId) => setRows((rowsRef.current ?? []).filter((r) => !same(r, aisleId, productId)));

    /** Put an entry back as it was (Undo): the earlier count, or nothing. */
    const restore = (prev, aisleId, productId) => {
        if (prev) {
            putRow({ ...prev, syncStatus: 'pending', reason: null, updatedAt: Date.now() });
            write(() => saveCount(taskId, clockOffset, {
                aisleId: prev.aisleId,
                productId: prev.productId,
                counted: prev.counted,
                breakdown: prev.breakdown,
                damaged: prev.damaged ?? 0,
                expired: prev.expired ?? 0,
                unlisted: prev.unlisted,
                countedAt: prev.countedAt,
                deviceCountedAt: prev.deviceCountedAt,
            }));
        } else {
            dropRow(aisleId, productId);
            write(() => removeCount(taskId, aisleId, productId));
        }
        say('Undone', 'ok');
    };

    /**
     * Save what a card holds. False if there's nothing (valid) to save;
     * 'ask' if the number is far above normal for this product and has not
     * been confirmed yet (the card then asks "Save 4,800 pieces?").
     */
    const commit = (c) => {
        if (!cardReady(c, allowHalves)) return false;

        const typed = toBreakdown(c.rows, c.values);
        const adding = c.existing && c.mode !== 'replace';
        // Replace with only damaged/expired typed: the good count stays as it was.
        const breakdown = adding
            ? mergeBreakdown(c.existing.breakdown, typed)
            : (anyTyped(c.rows, c.values) || !c.existing ? typed : (c.existing.breakdown ?? []));
        const counted = totalOf(breakdown);
        // Damaged and expired: kept apart, in base units; Add adds to what was there.
        const typedBad = badOf(c);
        const damaged = (adding ? Number(c.existing.damaged ?? 0) : 0) + typedBad.damaged;
        const expired = (adding ? Number(c.existing.expired ?? 0) : 0) + typedBad.expired;

        if (c.product.ceiling && counted > c.product.ceiling && !c.bigOk) {
            warn();
            setCard({ ...c, bigAsk: true });
            return 'ask';
        }

        const entry = { aisleId: c.aisleId, productId: c.product.id, counted, breakdown, damaged, expired, unlisted: c.unlisted };
        const prev = c.existing;

        putRow({ ...entry, taskId, syncStatus: 'pending', reason: null, updatedAt: Date.now() });
        write(() => saveCount(taskId, clockOffset, entry));
        const base = `${formatQty(counted)} ${plural(c.product.base_unit ?? 'Piece', counted)}`;
        const bad = [damaged ? `${formatQty(damaged)} damaged` : '', expired ? `${formatQty(expired)} expired` : ''].filter(Boolean).join(', ');
        say(
            `${c.product.name} · ${usesPacks(breakdown) ? `${spell(breakdown)} = ${base}` : base}${bad ? ` + ${bad}` : ''} saved`,
            'ok',
            () => restore(prev, entry.aisleId, entry.productId),
        );
        return true;
    };

    /**
     * Moving on from the open product: saved if a number is typed, closed if
     * not. False (and it stays open) if what's typed can't be saved.
     */
    const leave = () => {
        const c = cardRef.current;
        if (!c) return true;
        if (anyTyped(c.rows, c.values)) {
            const saved = commit(c);
            if (saved === 'ask') return false;
            if (!saved) {
                warn();
                say(`Finish ${c.product.name} first`, 'warn');
                return false;
            }
        }
        setCard(null);
        return true;
    };

    // --- Opening a product --------------------------------------------------

    const unitKeyFor = (productRows, unitId) => {
        const unit = (unitId != null && productRows.find((u) => u.id === unitId))
            || productRows.find((u) => u.factor === 1)
            || productRows[productRows.length - 1];
        return key(unit);
    };

    /** Decide, on the phone, what a product means here, and open it. */
    const open = (product, unitId) => {
        const here = aisleRef.current;
        const id = product.id;

        if (recount && !recountIds.has(id)) {
            warn();
            say(`${product.name} is not on this recount — skip`, 'warn');
            return;
        }

        const done = !recount ? pack.done?.[id] : null;
        if (done) {
            warn();
            say(`Already counted on ${done[0]} in ${done[1].join(', ')} — skip`, 'warn');
            return;
        }

        const productRows = unitRows(product);
        const unit = unitKeyFor(productRows, unitId);
        const existing = (rowsRef.current ?? []).find((r) => same(r, here.id, id)) ?? null;

        setCard({
            product,
            rows: productRows,
            unit,
            values: tapMode ? { [unit]: '1' } : {},
            mode: existing ? (tapMode ? 'add' : null) : 'new',
            existing,
            unlisted: existing ? !!existing.unlisted : !recount && !quick && !aisleRef.current?.quick && !expected.has(id),
            aisleId: here.id,
            editing: false,
        });

        if (existing) again(); else if (tapMode) tick(); else good();
    };

    /** A barcode, from the camera, typed, or from a hand-held scanner. */
    const handleCode = (raw) => {
        const value = String(raw ?? '').trim();
        if (!value || blockedRef.current) return;

        if (!aisleRef.current) {
            warn();
            say('Pick the aisle you are in first', 'warn');
            return;
        }

        const hit = lookupCode(index, value);
        const c = cardRef.current;

        if (c && hit && hit.product.id === c.product.id) {
            // The open product again: one more in tap-to-count; otherwise the
            // camera seeing it twice.
            if (tapMode) {
                const unit = hit.unitId != null ? unitKeyFor(c.rows, hit.unitId) : c.unit;
                setCard({ ...c, unit, mode: c.mode ?? 'add', values: { ...c.values, [unit]: plusOne(c.values[unit]) } });
                tick();
            }
            return;
        }

        if (!leave()) return;

        if (!hit) {
            unknownSound();
            setUnknown({ barcode: value });
            return;
        }

        open(hit.product, hit.unitId);
    };

    const pick = (product) => {
        setQuery('');
        setSheet(null);
        if (!aisleRef.current) { warn(); say('Pick the aisle you are in first', 'warn'); return; }
        if (cardRef.current?.product.id === product.id) return;
        if (leave()) open(product, null);
    };

    const edit = (row) => {
        if (!leave()) return;
        setSheet(null);
        const product = index.products.get(row.productId);
        if (!product) return;
        const productRows = unitRows(product);
        const biggest = (row.breakdown ?? []).find((l) => Number(l.qty) !== 0);
        setCard({
            product,
            rows: productRows,
            unit: unitKeyFor(productRows, biggest?.unit_id ?? null),
            values: {},
            mode: 'replace',
            existing: row,
            unlisted: !!row.unlisted,
            aisleId: row.aisleId,
            editing: true,
            // Replace keeps what was damaged or expired unless it is changed.
            bad: {
                damaged: row.damaged ? String(row.damaged) : '',
                expired: row.expired ? String(row.expired) : '',
            },
        });
    };

    const takeBack = (row) => {
        dropRow(row.aisleId, row.productId);
        write(() => removeCount(taskId, row.aisleId, row.productId));
        say(`${nameOf(row.productId)} taken back`, 'ok', () => restore(row, row.aisleId, row.productId));
    };

    // --- The panel's keys ----------------------------------------------------

    const pressKey = (k) => {
        pauseUntil.current = performance.now() + TYPING_PAUSE_MS;
        const c = cardRef.current;
        if (!c) return;
        if (c.target === 'damaged' || c.target === 'expired') {
            const bad = { ...(c.bad ?? {}) };
            bad[c.target] = press(bad[c.target] ?? '', k, allowHalves);
            setCard({ ...c, mode: c.mode ?? 'add', bad });
            return;
        }
        setCard({ ...c, mode: c.mode ?? 'add', values: { ...c.values, [c.unit]: press(c.values[c.unit] ?? '', k, allowHalves) } });
    };
    // Good (the count), Damaged or Expired: where the keypad's digits go.
    const setTarget = (target) => { const c = cardRef.current; if (c) setCard({ ...c, target }); };

    const saveKey = () => {
        const c = cardRef.current;
        if (c && commit(c) === true) setCard(null);
    };

    // "Save 4,800 pieces?": yes, it is right — or back to fix the number.
    const confirmBig = () => {
        const c = cardRef.current;
        if (c && commit({ ...c, bigOk: true, bigAsk: false }) === true) setCard(null);
    };
    const fixBig = () => { const c = cardRef.current; if (c) setCard({ ...c, bigAsk: false }); };

    const setUnit = (unit) => { const c = cardRef.current; if (c) setCard({ ...c, unit, target: 'good' }); };
    const setMode = (m) => { const c = cardRef.current; if (c) setCard({ ...c, mode: m }); };
    const skip = () => setCard(null);

    // --- The barcode box (hand-held scanner, or typing) ----------------------

    const onCodeKey = (e) => {
        if (e.key.length !== 1) return;
        const now = performance.now();
        const k = keys.current;
        keys.current = k.n === 0 ? { n: 1, first: now, last: now } : { n: k.n + 1, first: k.first, last: now };
    };

    /**
     * At a desk, with a product open, digits typed go straight into the
     * quantity as they are typed (B8). If it turns out to be a scanner (fast,
     * or too long for a quantity), the quantity goes back to what it was.
     */
    const onCodeChange = (value) => {
        setCode(value);
        const c = cardRef.current;
        if (!c || TOUCH) return;

        const base = beforeTyping.current ?? c.values;
        const text = value.trim();
        const next = /^\d{1,5}(½)?$/.test(text)
            ? { ...base, [c.unit]: text }
            : base;
        cardRef.current = { ...c, values: next };
        setCardState(cardRef.current);
        beforeTyping.current = base;
    };

    const submitCode = (e) => {
        e.preventDefault();
        const text = code.trim();
        const k = keys.current;
        const base = beforeTyping.current;
        keys.current = { n: 0, first: 0, last: 0 };
        beforeTyping.current = null;
        setCode('');

        const c = cardRef.current;
        if (c && text === '') { saveKey(); return; }

        // Typed by a person (not a scanner), short, and a number: how many.
        const byHand = k.n === 1 || (k.n > 1 && (k.last - k.first) / (k.n - 1) > WEDGE_MS_PER_KEY);
        if (c && byHand && /^\d{1,5}(½|\.5)?$/.test(text)) {
            const next = { ...c, mode: c.mode ?? 'add', values: { ...(base ?? c.values), [c.unit]: text.replace('.5', '½') } };
            if (commit(next)) setCard(null);
            else { warn(); setCard(next); }
            return;
        }

        // A scan: whatever went into the quantity on the way comes back out.
        if (c && base) {
            cardRef.current = { ...c, values: base };
            setCardState(cardRef.current);
        }
        handleCode(text);
    };

    // --- What is shown --------------------------------------------------------

    const all = rows ?? [];
    const shown = recount ? all : all.filter((r) => r.aisleId === aisle?.id);
    const results = useMemo(() => (query.trim().length < 2 ? [] : search(index, query)), [query, index]);
    const extra = shown.filter((r) => !expected.has(r.productId)).length;
    const progress = recount
        ? `${new Set(all.map((r) => r.productId)).size} / ${recount.products.length} recounted`
        : quick
            ? `${shown.length} here · ${new Set(all.map((r) => r.productId)).size} in all`
            : `${shown.length - extra} / ${expected.size}${extra ? ` +${extra}` : ''}`;

    const toCount = useMemo(() => {
        if (recount) {
            const done = new Set(all.filter((r) => r.syncStatus !== 'rejected').map((r) => r.productId));
            return recount.products.filter((p) => !done.has(p.id));
        }
        return aisle ? stillToCount(pack, aisle.id, all).map((id) => index.products.get(id)).filter(Boolean) : [];
    }, [recount, pack, aisle, all, index]);

    const viewfinder = cameraOn && cameraState !== 'error';

    const barcodeForm = (
        <form onSubmit={submitCode} className="flex gap-2">
            <input
                ref={scanRef}
                value={code}
                onChange={(e) => onCodeChange(e.target.value)}
                onKeyDown={onCodeKey}
                inputMode={TOUCH ? 'numeric' : undefined}
                autoComplete="off"
                placeholder={card && !TOUCH ? 'Scan the next one · or type how many, Enter' : 'Scan or type a barcode'}
                aria-label="Barcode"
                className="h-12 min-w-0 flex-1 rounded-xl border border-gray-300 bg-white px-3 text-base outline-none focus:border-teal-600 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
            />
            <button type="submit" className="h-12 rounded-xl bg-gray-800 px-4 text-base font-bold text-white">Go</button>
        </form>
    );

    const toastBar = toast && (
        <div key={toast.at} role="status" className={`flex items-center gap-2 rounded-xl px-3 py-1.5 text-base font-bold text-white shadow-lg ${toast.tone === 'ok' ? 'bg-green-700' : toast.tone === 'warn' ? 'bg-amber-600' : 'bg-red-600'}`}>
            <span className="min-w-0 flex-1 truncate">{toast.tone === 'ok' ? '✓ ' : ''}{toast.text}</span>
            {toast.undo && (
                <button type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => { const u = toast.undo; setToast(null); u(); }} className="h-10 shrink-0 rounded-lg bg-white/25 px-4 text-base font-extrabold">
                    Undo
                </button>
            )}
        </div>
    );

    const lists = (
        <Lists
            tab={tab}
            onTab={setTab}
            counted={shown}
            toCount={toCount}
            productName={nameOf}
            aisleLabel={labelOf}
            recount={!!recount}
            onEdit={edit}
            onTakeBack={takeBack}
            onPick={pick}
        />
    );

    // Swipe up on the handle (or tap it) to open the lists.
    const handle = (
        <button
            type="button"
            onClick={() => setSheet('lists')}
            onPointerDown={(e) => { swipe.current = e.clientY; }}
            onPointerUp={(e) => { if (swipe.current !== null && swipe.current - e.clientY > 30) setSheet('lists'); swipe.current = null; }}
            aria-label="Open the lists"
            className="flex min-h-14 w-full touch-none flex-col items-center justify-center rounded-2xl border border-gray-200 bg-white px-3 py-1.5 dark:border-gray-800 dark:bg-gray-900"
        >
            <span className="mb-1 h-1.5 w-10 rounded-full bg-gray-300 dark:bg-gray-700" />
            <span className="text-base font-bold text-gray-800 dark:text-gray-100">{quick ? `${shown.length} counted in this aisle` : `Counted ${shown.length} · Still to count ${toCount.length}`}</span>
        </button>
    );

    const idle = (
        <div className="h-full space-y-2 overflow-y-auto p-2 pb-6">
            {viewfinder && (
                <>
                    <p className="px-1 text-center text-base font-semibold text-gray-600 dark:text-gray-300">Point the camera at a barcode</p>
                    {barcodeForm}
                </>
            )}

            {!wide && handle}

            <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="No barcode? Search by name" aria-label="Search by name"
                className="h-12 w-full rounded-xl border border-gray-300 bg-white px-3 text-base outline-none focus:border-teal-600 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
            {query.trim().length >= 2 && results.length === 0 && (
                <div className="flex items-center gap-2 rounded-2xl bg-white p-3 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p className="min-w-0 flex-1 text-base text-gray-700 dark:text-gray-200">No product called <strong>{query.trim()}</strong></p>
                    <button type="button" onClick={() => { if (aisle) setUnknown({ barcode: '' }); else say('Pick the aisle you are in first', 'warn'); }} className="h-10 shrink-0 rounded-full bg-teal-700 px-4 text-sm font-extrabold text-white">Record it</button>
                </div>
            )}
            {results.length > 0 && (
                <ul className="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
                    {results.map((p) => (
                        <li key={p.id}>
                            <button type="button" onClick={() => pick(p)} className="min-h-12 w-full truncate p-3 text-left text-base font-semibold text-gray-800 dark:text-gray-100">{p.name}</button>
                        </li>
                    ))}
                </ul>
            )}

            {!wide && toCount.length > 0 && (
                <div className="rounded-2xl bg-white p-2 dark:bg-gray-900">
                    <p className="px-1 text-sm font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300">{recount ? 'To recount' : 'Next on the shelf'}</p>
                    {toCount.slice(0, 3).map((p) => (
                        <button key={p.id} type="button" onClick={() => pick(p)} className="flex min-h-12 w-full items-center gap-2 px-1 text-left">
                            <span className="min-w-0 flex-1 truncate text-base text-gray-900 dark:text-gray-100">{p.name}</span>
                            <span className="shrink-0 text-base font-bold text-teal-700">Count</span>
                        </button>
                    ))}
                </div>
            )}

            {!(query.trim().length >= 2 && results.length === 0) && (<button type="button" onClick={() => { if (aisle) setUnknown({ barcode: '' }); else say('Pick the aisle you are in first', 'warn'); }} className="min-h-12 px-1 text-base font-semibold text-teal-700">Not in the system? Record it</button>)}
        </div>
    );

    const counting = (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col">
            {viewfinder ? (
                // Scanning: the camera takes the room. A product open: it shrinks
                // to a strip that still reads the next barcode, and the number,
                // chips and keypad below always fit.
                <div className={`relative shrink-0 transition-[height] duration-200 ${card ? 'h-[92px]' : 'h-[max(170px,calc(100%-330px))]'}`}>
                    <Viewfinder
                        mode={mode}
                        onCode={handleCode}
                        readable={() => !blockedRef.current && !unknownRef.current && !sheet && performance.now() > pauseUntil.current}
                        repeatMs={tapMode ? 500 : 1500}
                        onStatus={setCameraState}
                    />
                    <div className="absolute inset-x-2 bottom-2">{toastBar}</div>
                </div>
            ) : (
                <div className="shrink-0 space-y-1.5 border-b border-gray-200 bg-white p-2 dark:border-gray-800 dark:bg-gray-900">
                    {barcodeForm}
                    <div className="flex h-11 items-center gap-2">
                        <div className="min-w-0 flex-1">{toastBar}</div>
                        {!cameraOn && mode !== 'none' && (
                            <button type="button" onClick={() => setCameraOn(true)} className="h-11 shrink-0 rounded-xl border border-gray-300 px-3 text-base font-bold text-gray-700 dark:border-gray-700 dark:text-gray-200">Use camera</button>
                        )}
                    </div>
                </div>
            )}

            <div className={`min-h-0 flex-1 ${card ? 'overflow-hidden' : 'overflow-y-auto'} ${wide ? 'mx-auto w-full max-w-xl' : ''}`}>
                {card ? (
                    <EntryPanel
                        card={card}
                        allowHalves={allowHalves}
                        tapMode={tapMode}
                        onKey={pressKey}
                        onUnit={setUnit}
                        onMode={setMode}
                        onSave={saveKey}
                        onSkip={skip}
                        onConfirmBig={confirmBig}
                        onFixBig={fixBig}
                        onTarget={setTarget}
                    />
                ) : idle}
            </div>
        </div>
    );

    return (
        <div className="flex h-full flex-col bg-[#F3F4F6] dark:bg-gray-950">
            <header className="shrink-0 border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div className="flex items-center gap-1 px-1.5 pt-1">
                    <button type="button" onClick={() => { leave(); onBack(); }} aria-label="Back" className="flex h-11 w-10 shrink-0 items-center justify-center rounded-full text-2xl font-bold text-teal-700 active:bg-teal-50 dark:active:bg-teal-900/40">←</button>
                    <div className="min-w-0 flex-1">
                        {onWhere ? (
                            <button
                                type="button"
                                onClick={() => { leave(); onWhere(); }}
                                aria-label={`Aisle: ${aisle?.label ?? 'none'}. Change`}
                                className="flex h-11 w-full min-w-0 items-center gap-1.5 rounded-xl px-2 text-left active:bg-gray-100 dark:active:bg-gray-800"
                            >
                                <span className="shrink-0 text-lg">📍</span>
                                <span className="min-w-0 truncate text-lg font-extrabold text-gray-900 dark:text-white">{aisle?.label ?? 'Which aisle?'}</span>
                                {aisle?.pending && <span className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-900">new</span>}
                                <span className="shrink-0 text-base font-bold text-teal-700">▾</span>
                            </button>
                        ) : (
                            <select
                                value={aisle?.id ?? ''}
                                onChange={(e) => { leave(); onAisle?.(aisles.find((a) => a.id === Number(e.target.value)) ?? null); }}
                                aria-label="Aisle"
                                className="block h-11 w-full max-w-md truncate bg-transparent px-1 text-lg font-extrabold text-gray-900 outline-none dark:text-white"
                            >
                                {!aisle && <option value="" disabled>{recount ? 'Where are you counting?' : 'Pick the aisle'}</option>}
                                {aisles.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                            </select>
                        )}
                    </div>
                    {onHandIn && (
                        <button type="button" onClick={() => { leave(); onHandIn(); }} className="h-10 shrink-0 rounded-full bg-teal-700 px-4 text-sm font-extrabold text-white shadow-sm active:bg-teal-800">Submit</button>
                    )}
                </div>
                <div className="flex items-center gap-2 px-3 pb-1.5">
                    <span className="min-w-0 truncate text-sm font-semibold text-gray-600 dark:text-gray-300" title={recount || quick ? undefined : 'Counted / expected on this aisle (+ found here, not on its list)'}>{progress}</span>
                    <SyncChip status={status} onAttention={() => setSheet('attention')} />
                    <button
                        type="button"
                        onClick={() => setTapMode((t) => !t)}
                        aria-pressed={tapMode}
                        aria-label="Tap-count"
                        className={`ml-auto h-8 shrink-0 rounded-full px-3 text-xs font-extrabold ${tapMode ? 'bg-teal-700 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'}`}
                    >
                        👆 Tap-count{tapMode ? ' on' : ''}
                    </button>
                </div>
            </header>

            {status.blocked && (
                <p role="alert" className="shrink-0 bg-red-600 px-3 py-2 text-base font-semibold text-white">{status.blocked}</p>
            )}

            {wide ? (
                <div className="flex min-h-0 flex-1">
                    {counting}
                    <aside className="w-[420px] shrink-0 overflow-y-auto border-l border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900" aria-label="Lists">
                        {lists}
                    </aside>
                </div>
            ) : counting}

            {sheet === 'lists' && !wide && (
                <Sheet title={aisle?.label ?? 'Lists'} onClose={() => setSheet(null)}>{lists}</Sheet>
            )}

            {sheet === 'attention' && (
                <Sheet title="Needs attention" onClose={() => setSheet(null)}>
                    <NeedsAttention
                        status={status}
                        productName={nameOf}
                        aisleLabel={labelOf}
                        onCountAgain={(row) => edit(row)}
                        onTakeBack={(row) => { setSheet(null); takeBack(row); }}
                        onOpenHandIn={() => { setSheet(null); onHandIn?.(); }}
                    />
                </Sheet>
            )}

            {unknownItem && aisle && (
                <UnknownItemForm
                    aisle={aisle}
                    barcode={unknownItem.barcode}
                    endpoint={`/tasks/${taskId}/unknown-items`}
                    withQuantity
                    onClose={() => { setUnknown(null); refocus(); }}
                    onSaved={(name) => { setUnknown(null); say(`Recorded for a manager: ${name}`, 'warn'); refocus(); }}
                />
            )}
        </div>
    );
}
