import { useEffect, useMemo, useState } from 'react';
import api from '../lib/api';
import {
    cachePickings,
    cachedPickings,
    markPickingPaymentSynced,
    queuePickingPayment,
    queuedAmountByItem,
} from '../lib/pickings';

const naira = (value) => `₦${Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * Taking money from the traders holding the shop's goods.
 *
 * The cashier picks the trader, ticks what is being paid for, and enters the
 * cash. What that settles is worked out on the server — this screen shows the
 * total of what was ticked as a guide, never as a promise, because the price
 * may have moved or somebody else may have paid first.
 */
export default function PickingsModal({ vendorId, isOnline, cart = [], onClose, onReleased }) {
    // 'pay' takes money for goods already out; 'release' hands new goods over,
    // using whatever the cashier has put in the cart.
    const [mode, setMode] = useState('pay');
    const [releaseTo, setReleaseTo] = useState('');
    const [releasing, setReleasing] = useState(false);
    const [pickers, setPickers] = useState([]);
    const [selected, setSelected] = useState(null);
    const [ticked, setTicked] = useState([]);
    const [amount, setAmount] = useState('');
    const [queued, setQueued] = useState({});
    const [stale, setStale] = useState(false);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        let alive = true;

        (async () => {
            // Local first so the screen fills immediately, then the server
            // corrects it. A till with no connection keeps the local copy and
            // says so rather than showing nothing.
            const cache = await cachedPickings(vendorId);

            if (alive && cache) {
                setPickers(cache.pickers ?? []);
                setStale(true);
            }

            setQueued(await queuedAmountByItem());

            if (!isOnline) return;

            try {
                const { data } = await api.get('/pickings', { params: { vendor_id: vendorId } });

                if (!alive) return;

                setPickers(data.pickers ?? []);
                setStale(false);
                await cachePickings(vendorId, data);
            } catch {
                // Leave the cached list up. It is what this till knows.
            }
        })();

        return () => { alive = false; };
    }, [vendorId, isOnline]);

    const picker = useMemo(
        () => pickers.find((p) => p.id === selected) ?? null,
        [pickers, selected],
    );

    const tickedTotal = useMemo(() => {
        if (!picker) return 0;

        return picker.lines
            .filter((line) => ticked.includes(line.id))
            .reduce((sum, line) => sum + Number(line.outstanding ?? 0), 0);
    }, [picker, ticked]);

    const toggle = (id) => setTicked((current) =>
        current.includes(id) ? current.filter((x) => x !== id) : [...current, id]);

    /**
     * Hand the cart over as a trip.
     *
     * Online only: whether this branch holds the goods is decided on the server,
     * and letting a release queue would mean goods leaving on a record that
     * could still be refused.
     */
    const releaseCart = async () => {
        if (!releaseTo || cart.length === 0 || !isOnline) return;

        setReleasing(true);
        setError(null);

        try {
            const { data } = await api.post('/pickings/release', {
                vendor_id: vendorId,
                picker_id: Number(releaseTo),
                items: cart.map((item) => ({ product_id: item.id, quantity: item.qty })),
            });

            setResult({ released: true, ...data });
            setReleaseTo('');

            // The cart is now a trip, so it must not also become a sale.
            onReleased?.();

            const refreshed = await api.get('/pickings', { params: { vendor_id: vendorId } });

            setPickers(refreshed.data.pickers ?? []);
            await cachePickings(vendorId, refreshed.data);
        } catch (e) {
            // Nothing left the shelf: the trip is one transaction, so a line the
            // branch cannot cover takes the whole thing with it.
            setError(e?.response?.data?.message ?? 'Nothing was released.');
        } finally {
            setReleasing(false);
        }
    };

    const submit = async () => {
        const money = Number(amount);

        if (!picker || ticked.length === 0 || !(money > 0)) return;

        setBusy(true);
        setError(null);

        // Queued first, always. If the upload fails halfway the payment still
        // exists on this device, which is the only thing standing between a
        // cashier and money they took with no record of it.
        const offlineId = await queuePickingPayment({
            vendorId,
            pickerId: picker.id,
            amount: money,
            itemIds: ticked,
        });

        if (!isOnline) {
            setResult({ queued: true, amount: money });
            setQueued(await queuedAmountByItem());
            setBusy(false);

            return;
        }

        try {
            const { data } = await api.post('/pickings/payment', {
                vendor_id: vendorId,
                picker_id: picker.id,
                amount: money,
                item_ids: ticked,
                reference: offlineId,
            });

            await markPickingPaymentSynced(offlineId, data);

            setResult(data);

            const refreshed = await api.get('/pickings', { params: { vendor_id: vendorId } });

            setPickers(refreshed.data.pickers ?? []);
            await cachePickings(vendorId, refreshed.data);
            setTicked([]);
            setAmount('');
        } catch (e) {
            // It stays in the queue and goes up with the next sync.
            setError(e?.response?.data?.message ?? 'Saved on this device — it will go up when the connection returns.');
            setResult({ queued: true, amount: money });
        } finally {
            setQueued(await queuedAmountByItem());
            setBusy(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 sm:items-center" onClick={onClose}>
            <div
                className="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl dark:bg-zinc-900"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-zinc-700">
                    <div>
                        <h2 className="text-lg font-bold text-gray-900 dark:text-white">Vendor Pickings</h2>
                        <p className="text-xs text-gray-500 dark:text-zinc-400">
                            {picker ? `${picker.name} · tick what is being paid for` : 'Who is holding your goods'}
                        </p>
                    </div>
                    <button onClick={onClose} className="rounded-lg px-3 py-1.5 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-zinc-800">
                        Close
                    </button>
                </div>

                <div className="flex gap-1 border-b border-gray-200 px-5 pt-3 dark:border-zinc-700">
                    {[['pay', 'Take payment'], ['release', `Record pickings${cart.length ? ` (${cart.length})` : ''}`]].map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => { setMode(key); setResult(null); setError(null); }}
                            className={`rounded-t-lg px-4 py-2 text-sm font-semibold ${
                                mode === key
                                    ? 'bg-gray-100 text-gray-900 dark:bg-zinc-800 dark:text-white'
                                    : 'text-gray-500 hover:text-gray-800 dark:text-zinc-400'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {stale && (
                    <div className="bg-amber-50 px-5 py-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                        Offline — showing what this till last saw. What a payment settles is worked out when it goes up.
                    </div>
                )}

                <div className="flex-1 overflow-y-auto px-5 py-4">
                    {mode === 'release' && (
                        <div className="space-y-4">
                            {!isOnline && (
                                <div className="rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                    Releasing needs a connection. Whether this branch actually holds the goods is
                                    decided on the server, and goods must not leave on a record that might be
                                    refused later.
                                </div>
                            )}

                            {cart.length === 0 ? (
                                <p className="py-10 text-center text-sm text-gray-500 dark:text-zinc-400">
                                    Put the goods in the cart first — scan or search as you would for a sale, then come back here.
                                </p>
                            ) : (
                                <>
                                    <div className="space-y-2">
                                        {cart.map((item) => (
                                            <div key={item.id} className="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3 dark:border-zinc-700">
                                                <span className="truncate font-semibold text-gray-900 dark:text-white">{item.name}</span>
                                                <span className="ml-3 shrink-0 text-sm text-gray-500 dark:text-zinc-400">x{item.qty}</span>
                                            </div>
                                        ))}
                                    </div>

                                    <select
                                        value={releaseTo}
                                        onChange={(e) => setReleaseTo(e.target.value)}
                                        className="w-full rounded-xl border border-gray-300 px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    >
                                        <option value="">Who is taking these goods?</option>
                                        {pickers.map((p) => (
                                            <option key={p.id} value={p.id}>{p.name}</option>
                                        ))}
                                    </select>

                                    {pickers.length === 0 && (
                                        <p className="text-xs text-gray-500 dark:text-zinc-400">
                                            No pickers yet. Add one in Dashboard → Point of Sale → Vendor Pickings.
                                        </p>
                                    )}

                                    <button
                                        onClick={releaseCart}
                                        disabled={releasing || !isOnline || !releaseTo}
                                        className="w-full rounded-xl bg-amber-600 px-6 py-3 font-bold text-white disabled:opacity-40"
                                    >
                                        {releasing ? '…' : 'Release these goods'}
                                    </button>

                                    <p className="text-[11px] text-gray-400 dark:text-zinc-500">
                                        The goods leave the shelf now. They stay yours until paid for, and can be asked back.
                                    </p>
                                </>
                            )}
                        </div>
                    )}

                    {mode === 'release' && result?.released && (
                        <div className="mb-4 rounded-xl bg-green-50 p-4 text-sm text-green-800 dark:bg-green-500/10 dark:text-green-300">
                            {result.reference} — {result.lines} line{result.lines === 1 ? '' : 's'} released to {result.picker}.
                            The goods are off the shelf and out on trust.
                        </div>
                    )}

                    {mode === 'release' && error && (
                        <div className="mb-4 rounded-xl bg-red-50 p-4 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-300">
                            {error}
                        </div>
                    )}

                    {mode === 'pay' && result && (
                        <div className="mb-4 rounded-xl bg-green-50 p-4 text-sm text-green-800 dark:bg-green-500/10 dark:text-green-300">
                            {result.queued ? (
                                <>Saved on this till: {naira(result.amount)}. It will be applied when the connection returns.</>
                            ) : (
                                <>
                                    Settled {result.settled_units} unit{result.settled_units === 1 ? '' : 's'}.
                                    {Number(result.change) > 0 && <> Hand back {naira(result.change)}.</>}
                                </>
                            )}
                        </div>
                    )}

                    {mode === 'pay' && error && (
                        <div className="mb-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            {error}
                        </div>
                    )}

                    {mode === 'pay' && !picker && (
                        pickers.length === 0 ? (
                            <p className="py-10 text-center text-sm text-gray-500 dark:text-zinc-400">
                                Nobody is holding goods from this branch.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {pickers.map((p) => {
                                    const owed = p.lines.reduce((sum, line) => sum + Number(line.outstanding ?? 0), 0);

                                    return (
                                        <button
                                            key={p.id}
                                            onClick={() => { setSelected(p.id); setTicked([]); setResult(null); }}
                                            className="flex w-full items-center justify-between rounded-xl border border-gray-200 px-4 py-3 text-left hover:border-green-500 dark:border-zinc-700"
                                        >
                                            <div>
                                                <p className="font-semibold text-gray-900 dark:text-white">{p.name}</p>
                                                <p className="text-xs text-gray-500 dark:text-zinc-400">
                                                    {p.lines.length} line{p.lines.length === 1 ? '' : 's'} out
                                                    {p.phone ? ` · ${p.phone}` : ''}
                                                </p>
                                            </div>
                                            <span className="font-bold text-amber-600 dark:text-amber-400">{naira(owed)}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        )
                    )}

                    {mode === 'pay' && picker && (
                        <div className="space-y-2">
                            <button
                                onClick={() => { setSelected(null); setTicked([]); }}
                                className="mb-2 text-xs font-semibold text-gray-500 hover:text-gray-800 dark:text-zinc-400"
                            >
                                ← All pickers
                            </button>

                            {picker.lines.map((line) => (
                                <label
                                    key={line.id}
                                    className={`flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 ${
                                        ticked.includes(line.id)
                                            ? 'border-green-500 bg-green-50 dark:bg-green-500/10'
                                            : 'border-gray-200 dark:border-zinc-700'
                                    }`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={ticked.includes(line.id)}
                                        onChange={() => toggle(line.id)}
                                        className="h-5 w-5 accent-green-600"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-semibold text-gray-900 dark:text-white">{line.product_name}</p>
                                        <p className="text-xs text-gray-500 dark:text-zinc-400">
                                            {line.held} held · {naira(line.unit_price)} each
                                            {Number(line.credit) > 0 && ` · ${naira(line.credit)} already paid`}
                                            {queued[line.id] > 0 && ` · ${naira(queued[line.id])} waiting to go up`}
                                        </p>
                                    </div>
                                    <span className="font-bold text-gray-900 dark:text-white">{naira(line.outstanding)}</span>
                                </label>
                            ))}
                        </div>
                    )}
                </div>

                {mode === 'pay' && picker && (
                    <div className="border-t border-gray-200 px-5 py-4 dark:border-zinc-700">
                        <div className="mb-3 flex items-center justify-between text-sm">
                            <span className="text-gray-500 dark:text-zinc-400">
                                {ticked.length} ticked
                            </span>
                            <span className="font-bold text-gray-900 dark:text-white">{naira(tickedTotal)}</span>
                        </div>

                        <div className="flex gap-2">
                            <input
                                type="number"
                                inputMode="decimal"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                                placeholder="Amount paid"
                                className="min-w-0 flex-1 rounded-xl border border-gray-300 px-4 py-3 text-lg font-semibold dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            />
                            <button
                                onClick={submit}
                                disabled={busy || ticked.length === 0 || !(Number(amount) > 0)}
                                className="rounded-xl bg-green-600 px-6 py-3 font-bold text-white disabled:opacity-40"
                            >
                                {busy ? '…' : 'Take payment'}
                            </button>
                        </div>

                        <p className="mt-2 text-[11px] text-gray-400 dark:text-zinc-500">
                            Whole units only. Anything left over waits against the next one until it is complete.
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}
