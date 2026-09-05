import { useEffect, useMemo, useState } from 'react';
import api from '../lib/api';

const naira = (value) => `₦${Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * Handing the day's takings over.
 *
 * Shows what the system says the cashier is holding, and records what actually
 * changed hands. A difference is allowed but has to be explained — refusing it
 * would send the money out of the drawer with no record at all, which is the
 * leak this exists to close.
 *
 * Online only: what a cashier should be holding is derived from sales the
 * server knows about, and a till offline since morning does not know them. A
 * handover against a stale figure would invent a shortfall out of sales that
 * simply had not synced.
 */
export default function CashSubmitModal({ vendorId, isOnline, onClose }) {
    const [expected, setExpected] = useState(0);
    const [receivers, setReceivers] = useState({});
    const [recent, setRecent] = useState([]);
    const [receivedBy, setReceivedBy] = useState('');
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!isOnline) { setLoading(false); return; }

        let alive = true;

        api.get('/cash', { params: { vendor_id: vendorId } })
            .then(({ data }) => {
                if (!alive) return;

                setExpected(Number(data.expected ?? 0));
                setReceivers(data.receivers ?? {});
                setRecent(data.recent ?? []);
                // Pre-filled with what is expected, which is the answer nearly
                // every time. The cashier changes it only when it is wrong.
                setAmount(String(data.expected ?? ''));
            })
            .catch(() => setError('Could not read your takings. Try again.'))
            .finally(() => alive && setLoading(false));

        return () => { alive = false; };
    }, [vendorId, isOnline]);

    const variance = useMemo(
        () => Number((Number(amount || 0) - expected).toFixed(2)),
        [amount, expected],
    );

    const mismatched = Math.abs(variance) >= 0.01;

    const submit = async () => {
        if (!receivedBy || !(Number(amount) > 0) || (mismatched && !reason.trim())) return;

        setBusy(true);
        setError(null);

        try {
            const { data } = await api.post('/cash/submit', {
                vendor_id: vendorId,
                received_by: Number(receivedBy),
                amount: Number(amount),
                reason: reason.trim() || null,
            });

            setDone(data);
        } catch (e) {
            setError(e?.response?.data?.message ?? 'Could not record it.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 sm:items-center" onClick={onClose}>
            <div
                className="flex max-h-[92vh] w-full max-w-lg flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl dark:bg-zinc-900"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-zinc-700">
                    <div>
                        <h2 className="text-lg font-bold text-gray-900 dark:text-white">Submit cash</h2>
                        <p className="text-xs text-gray-500 dark:text-zinc-400">Hand your takings to someone</p>
                    </div>
                    <button onClick={onClose} className="rounded-lg px-3 py-1.5 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-zinc-800">
                        Close
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto px-5 py-4">
                    {!isOnline && (
                        <p className="rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            Submitting cash needs a connection. What you are holding is worked out from sales
                            the server has, and this till has some that have not gone up yet — recording now
                            would show you short for money you never took.
                        </p>
                    )}

                    {isOnline && loading && (
                        <p className="py-8 text-center text-sm text-gray-500 dark:text-zinc-400">Reading your takings…</p>
                    )}

                    {isOnline && !loading && done && (
                        <div className="rounded-xl bg-green-50 p-4 text-sm text-green-800 dark:bg-green-500/10 dark:text-green-300">
                            <p className="font-semibold">{done.reference} recorded</p>
                            <p className="mt-1">
                                {naira(done.amount)} handed to {done.receiver}. Waiting for them to confirm they got it.
                            </p>
                        </div>
                    )}

                    {isOnline && !loading && !done && (
                        <div className="space-y-4">
                            <div className="rounded-xl bg-gray-50 p-4 text-center dark:bg-zinc-800">
                                <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-zinc-400">You are holding</p>
                                <p className="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{naira(expected)}</p>
                                <p className="mt-1 text-[11px] text-gray-400 dark:text-zinc-500">
                                    Cash you have taken here, less what you have already handed over.
                                </p>
                            </div>

                            <select
                                value={receivedBy}
                                onChange={(e) => setReceivedBy(e.target.value)}
                                className="w-full rounded-xl border border-gray-300 px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            >
                                <option value="">Handing it to…</option>
                                {Object.entries(receivers).map(([id, name]) => (
                                    <option key={id} value={id}>{name}</option>
                                ))}
                            </select>

                            {Object.keys(receivers).length === 0 && (
                                <p className="text-xs text-amber-700 dark:text-amber-400">
                                    Nobody on this team can receive cash yet. Someone needs the Receive Cash permission.
                                </p>
                            )}

                            <input
                                type="number"
                                inputMode="decimal"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                                placeholder="Amount handed over"
                                className="w-full rounded-xl border border-gray-300 px-4 py-3 text-lg font-semibold dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            />

                            {mismatched && (
                                <div className="space-y-2">
                                    <p className={`text-sm font-semibold ${variance < 0 ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'}`}>
                                        {variance < 0 ? 'Short by' : 'Over by'} {naira(Math.abs(variance))}
                                    </p>
                                    <textarea
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        rows={2}
                                        placeholder="Why the difference?"
                                        className="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    />
                                </div>
                            )}

                            {error && (
                                <p className="rounded-xl bg-red-50 p-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{error}</p>
                            )}

                            <button
                                onClick={submit}
                                disabled={busy || !receivedBy || !(Number(amount) > 0) || (mismatched && !reason.trim())}
                                className="w-full rounded-xl bg-green-600 px-6 py-3 font-bold text-white disabled:opacity-40"
                            >
                                {busy ? '…' : 'Record handover'}
                            </button>

                            {recent.length > 0 && (
                                <div className="pt-2">
                                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-zinc-400">
                                        Your recent handovers
                                    </p>
                                    <div className="space-y-1">
                                        {recent.map((row) => (
                                            <div key={row.reference} className="flex items-center justify-between text-xs">
                                                <span className="font-mono text-gray-500 dark:text-zinc-400">{row.reference}</span>
                                                <span className="text-gray-900 dark:text-white">{naira(row.amount)}</span>
                                                <span className={
                                                    row.status === 'confirmed' ? 'text-green-600 dark:text-green-400'
                                                        : row.status === 'disputed' ? 'text-red-600 dark:text-red-400'
                                                        : 'text-amber-600 dark:text-amber-400'
                                                }>
                                                    {row.status}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
