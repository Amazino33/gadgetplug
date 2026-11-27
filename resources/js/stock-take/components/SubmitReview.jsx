import { useEffect, useMemo, useState } from 'react';
import { liveQuery } from 'dexie';
import api from '../lib/api';
import { missingFor } from '../lib/missing';
import { entriesFor, queueHandIn, syncNow } from '../offline/outbox';
import { useSyncStatus } from '../lib/syncStatus';

export const OFFLINE_HAND_IN = 'Saved on this phone. It will be submitted automatically when you’re back online.';

/**
 * Hand-in (A6, B6). Every product the task should have found but has no
 * count needs an answer: Not on shelf (counted as 0) or Skip (counted later,
 * by another task). The list is worked out on the phone from the task pack,
 * so this works with no signal.
 *
 * Hand in goes up after every count before it. With no signal it waits on
 * the phone and goes by itself when the phone is back online. After it,
 * nothing on the task can be changed.
 */
export default function SubmitReview({ session, onBack, onSubmitted }) {
    const { taskId, pack, index } = session;
    const status = useSyncStatus(taskId);
    const [entries, setEntries]     = useState(null);
    const [decisions, setDecisions] = useState({});
    const [error, setError]         = useState('');
    const [busy, setBusy]           = useState(false);

    useEffect(() => {
        const sub = liveQuery(() => entriesFor(taskId)).subscribe({ next: setEntries, error: () => {} });
        return () => sub.unsubscribe();
    }, [taskId]);

    const missing = useMemo(() => (entries ? missingFor(pack, entries) : null), [pack, entries]);
    const name = (id) => index.products.get(id)?.name ?? `#${id}`;

    const decide = (id, value) => setDecisions((d) => ({ ...d, [id]: value }));
    const skipRest = () => setDecisions((d) => ({ ...Object.fromEntries((missing ?? []).map((id) => [id, 'skip'])), ...d }));
    const left = (missing ?? []).filter((id) => !decisions[id]).length;
    const answered = missing !== null && left === 0;

    const submit = async () => {
        if (!answered || busy) return;
        if (!window.confirm('Submit this count? You will not be able to change any count on it afterwards.')) return;
        setBusy(true);
        setError('');

        const answers = Object.fromEntries(missing.map((id) => [id, decisions[id]]));
        // Never "Submitting…" for ever: saving on the phone gets 8 s, sending 15 s.
        const within = (promise, ms, late) => Promise.race([promise, new Promise((done) => setTimeout(() => done(late), ms))]);
        const queued = await within(queueHandIn(taskId, answers).then(() => true), 8000, false).catch(() => false);
        if (!queued) {
            setError('This phone could not save the submission. Close other tabs of this app, then try again.');
            setBusy(false);
            return;
        }
        const result = await within(syncNow(api, taskId), 15000, { failed: true, slow: true });

        if (result.handedIn) { onSubmitted('Count submitted. Thank you.'); return; }
        if (result.blocked) { setError('This task is being counted on another phone. Ask the manager to release it.'); setBusy(false); return; }
        if (result.handInRefused) { setError('The submission was not accepted. See Needs attention.'); setBusy(false); return; }

        // No signal: it waits on the phone and goes by itself.
        onSubmitted(OFFLINE_HAND_IN);
    };

    return (
        <div className="mx-auto max-w-xl space-y-3 p-3 pb-28">
            <button type="button" onClick={onBack} className="min-h-12 px-1 text-base font-semibold text-teal-700">← Keep counting</button>
            <h1 className="px-1 text-xl font-extrabold text-gray-900 dark:text-gray-100">Submit</h1>

            {error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-base font-semibold text-red-700">{error}</p>}
            {status.rejected.length > 0 && (
                <p className="rounded-xl bg-amber-100 p-3 text-base text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                    ⚠ {status.rejected.length} count{status.rejected.length === 1 ? ' was' : 's were'} not accepted. {status.rejected.length === 1 ? 'It is' : 'They are'} in the list below as not counted.
                </p>
            )}
            {status.waiting > 0 && (
                <p className="px-1 text-base text-gray-600 dark:text-gray-300">⬆ {status.waiting} count{status.waiting === 1 ? '' : 's'} still uploading. The submission goes after them.</p>
            )}
            {missing === null && <p className="p-4 text-center text-base text-gray-500">Checking…</p>}

            {missing?.length === 0 && (
                <p className="rounded-2xl bg-teal-50 p-4 text-base font-semibold text-teal-900 dark:bg-teal-900/30 dark:text-teal-100">
                    Everything this task expected has a count.
                </p>
            )}

            {missing?.length > 0 && (
                <>
                    <p className="px-1 text-base text-gray-700 dark:text-gray-200">
                        {missing.length} product{missing.length === 1 ? ' has' : 's have'} no count. For each: is it really not on the shelf, or will it be counted later?
                    </p>
                    <button type="button" onClick={skipRest} disabled={left === 0} className="h-12 w-full rounded-xl border-2 border-gray-300 text-base font-bold text-gray-800 disabled:opacity-40 dark:border-gray-700 dark:text-gray-200">
                        Mark all remaining as Skip{left ? ` (${left})` : ''}
                    </button>
                    <ul className="space-y-2">
                        {missing.map((id) => (
                            <li key={id} className="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                                <p className="text-base font-bold text-gray-900 dark:text-gray-100">{name(id)}</p>
                                <div className="mt-2 grid grid-cols-2 gap-2">
                                    <button type="button" aria-pressed={decisions[id] === 'not_on_shelf'} onClick={() => decide(id, 'not_on_shelf')}
                                        className={`h-12 rounded-xl text-base font-bold ${decisions[id] === 'not_on_shelf' ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200'}`}>
                                        Not on shelf (0)
                                    </button>
                                    <button type="button" aria-pressed={decisions[id] === 'skip'} onClick={() => decide(id, 'skip')}
                                        className={`h-12 rounded-xl text-base font-bold ${decisions[id] === 'skip' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200'}`}>
                                        Skip, count later
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <div className="fixed inset-x-0 bottom-0 z-10 border-t border-gray-200 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-gray-800 dark:bg-gray-900">
                {left > 0 && <p className="mx-auto mb-1.5 max-w-xl text-center text-base text-gray-600 dark:text-gray-300">{left} still to answer</p>}
                <button type="button" disabled={!answered || busy} onClick={submit}
                    className="mx-auto block h-14 w-full max-w-xl rounded-2xl bg-teal-700 text-lg font-extrabold text-white disabled:opacity-40">
                    {busy ? 'Submitting…' : 'Submit count'}
                </button>
            </div>
        </div>
    );
}
