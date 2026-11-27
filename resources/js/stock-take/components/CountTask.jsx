import { useCallback, useEffect, useMemo, useState } from 'react';
import { liveQuery } from 'dexie';
import api, { errorMessage } from '../lib/api';
import { useSyncStatus } from '../lib/syncStatus';
import { buildIndex } from '../offline/lookup';
import { addAisleToPack, addLocalAisle, ensurePack, storedPack, whereKey } from '../offline/pack';
import { getMeta, setMeta } from '../offline/db';
import { entriesFor } from '../offline/outbox';
import CountScreen from './CountScreen';
import SubmitReview from './SubmitReview';
import SyncChip from './SyncChip';
import WhereAmI from './WhereAmI';
import { TaskSkeleton } from './Skeletons';

/** How often a Quick count asks "are you still here?". */
export const WHERE_CHECK_MS = 30 * 60 * 1000;

/**
 * A count or recount task on the phone.
 *
 * Opening it loads the task pack from the phone (downloading it the first
 * time) and builds the barcode index. From then on counting needs no network;
 * the app's uploader (`syncer`) sends the counts up in the background.
 *
 * One phone per task (A7): if another phone holds it, it says so and stops.
 *
 * Count: pick one of the task's aisles and count it; Hand in when done.
 * Recount: the products to recount, and "where are you?" (the aisle each one
 * is found in is recorded with its count).
 * Quick count: count and map in one walk. "Which aisle are you in?" at the
 * start, any time from the header, and every 30 minutes; each count is saved
 * with that aisle and puts the product on its map. "No aisle" just counts.
 */
export default function CountTask({ taskId, syncer, onBack, onSubmitted }) {
    const [stored, setStored] = useState(null);
    const [error, setError]   = useState(null);
    const [aisle, setAisle]   = useState(null);
    const [review, setReview] = useState(false);
    const [rows, setRows]     = useState([]);
    // Quick count: null, or why "Which aisle are you in?" is open: 'start' | 'change' | 'check'.
    const [where, setWhere]   = useState(null);
    const [confirmedAt, setConfirmedAt] = useState(0);
    // How far the first download is, for the loading screen.
    const [progress, setProgress] = useState(null);
    const [attempt, setAttempt] = useState(0);
    const status = useSyncStatus(taskId);

    // The pack, from the phone or downloaded once.
    useEffect(() => {
        let cancelled = false;
        setError(null);
        ensurePack(api, taskId, (p) => { if (!cancelled) setProgress(p); })
            .then((loaded) => { if (!cancelled) setStored(loaded); })
            .catch((e) => {
                if (cancelled) return;
                setError(e?.code === 'other_phone'
                    ? { other: true, text: errorMessage(e) }
                    : { text: errorMessage(e, 'Could not download this task. Get signal once to download it; after that it works offline.') });
            });
        return () => { cancelled = true; };
    }, [taskId, attempt]);

    // Kept current: the clock offset is refined by every upload.
    useEffect(() => {
        const subs = [
            liveQuery(() => storedPack(taskId)).subscribe({ next: (fresh) => { if (fresh) setStored(fresh); }, error: () => {} }),
            liveQuery(() => entriesFor(taskId)).subscribe({ next: setRows, error: () => {} }),
        ];
        return () => subs.forEach((s) => s.unsubscribe());
    }, [taskId]);

    const index = useMemo(() => (stored?.pack ? buildIndex(stored.pack) : null), [stored?.pack]);

    const quickTask = !!stored?.pack && stored.pack.task.type !== 'recount'
        && (!!stored.pack.task.quick || (stored.pack.task.aisles.length === 1 && !!stored.pack.task.aisles[0].quick));
    // The task's own area reads "No aisle" on the phone.
    const quickAisles = useMemo(
        () => (stored?.pack?.task.aisles ?? []).map((a) => (a.quick ? { ...a, label: 'No aisle' } : a)),
        [stored?.pack],
    );

    // A Quick count opens where the counter last said they were; otherwise it asks.
    useEffect(() => {
        if (!quickTask || aisle) return;
        let live = true;
        getMeta(whereKey(taskId)).then((last) => {
            if (!live) return;
            const found = last && quickAisles.find((a) => a.id === last.aisleId);
            if (found && !found.held_by) {
                setAisle(found);
                setConfirmedAt(last.at ?? Date.now());
            } else if (quickAisles.filter((a) => !a.quick).length === 0) {
                // No aisles in this branch at all: count, and add aisles from the header when you like.
                const none = quickAisles.find((a) => a.quick);
                if (none) setAisle(none);
                setConfirmedAt(Date.now());
            } else {
                setWhere('start');
            }
        }).catch(() => setWhere('start'));
        return () => { live = false; };
    }, [quickTask, aisle, quickAisles, taskId]);

    const pickWhere = useCallback((next) => {
        const at = Date.now();
        setAisle(next);
        setConfirmedAt(at);
        setWhere(null);
        setMeta(whereKey(taskId), { aisleId: next.id, at }).catch(() => {});
    }, [taskId]);

    // Every 30 minutes: "Are you still here?" Checked often, and as the phone
    // wakes, so a phone that slept through the half hour asks at once.
    useEffect(() => {
        if (!quickTask || !aisle || where) return undefined;
        const due = () => {
            if (Date.now() - confirmedAt >= WHERE_CHECK_MS) setWhere('check');
        };
        const timer = setInterval(due, 15000);
        const wake = () => { if (document.visibilityState === 'visible') due(); };
        document.addEventListener('visibilitychange', wake);
        due();
        return () => { clearInterval(timer); document.removeEventListener('visibilitychange', wake); };
    }, [quickTask, aisle, where, confirmedAt]);

    if (error) {
        return (
            <div className="mx-auto max-w-xl space-y-3 p-3">
                <button type="button" onClick={onBack} className="min-h-12 px-1 text-base font-semibold text-teal-700">← My tasks</button>
                <p role="alert" className={`rounded-xl p-4 text-base font-semibold ${error.other ? 'bg-amber-100 text-amber-900' : 'bg-red-50 text-red-700'}`}>
                    {error.other && <span className="mb-1 block text-lg font-extrabold">📱 On another phone</span>}
                    {error.text}
                </p>
                {!error.other && (
                    <button type="button" onClick={() => { setProgress(null); setAttempt((n) => n + 1); }} className="h-12 w-full rounded-full bg-teal-700 text-base font-extrabold text-white active:bg-teal-800">
                        Try again
                    </button>
                )}
            </div>
        );
    }
    if (!stored || !index) return <TaskSkeleton key={attempt} progress={progress} onRetry={() => { setProgress(null); setAttempt((n) => n + 1); }} />;

    const { pack } = stored;
    const session = { taskId, pack, index, clockOffset: stored.clockOffset, syncer };
    const isRecount = pack.task.type === 'recount';
    // A Quick count: no aisle list to work through; counting, with "where are you?" in the header.
    const isQuick   = quickTask;

    // Handed in on this phone; waiting for signal to send it (A6).
    if (status.queuedHandIn || stored.handedInAt) {
        return (
            <div className="mx-auto max-w-xl space-y-3 p-3">
                <button type="button" onClick={onBack} className="min-h-12 px-1 text-base font-semibold text-teal-700">← My tasks</button>
                {stored.handedInAt && !status.queuedHandIn ? (
                    <p className="rounded-2xl bg-teal-50 p-4 text-base font-semibold text-teal-900 dark:bg-teal-900/30 dark:text-teal-100">✓ This count is submitted.</p>
                ) : (
                    <div className="space-y-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <p className="flex items-center gap-2 text-base font-bold text-gray-900 dark:text-white">
                            {navigator.onLine && <span className="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-teal-600 border-t-transparent" />}
                            {navigator.onLine ? 'Sending your count to the manager…' : 'No signal. Your count is safe on this phone.'}
                        </p>
                        <p className="text-sm text-gray-600 dark:text-gray-300">
                            It goes by itself, even if you close the app{navigator.onLine ? '' : ', as soon as there is signal'}. Nothing is lost.
                        </p>
                        <button type="button" onClick={() => syncer?.flush?.()} className="h-12 w-full rounded-full border-2 border-teal-700 text-base font-extrabold text-teal-800 dark:text-teal-200">Send now</button>
                    </div>
                )}
                <SyncChip status={status} />
            </div>
        );
    }

    if (review) {
        return <SubmitReview session={session} onBack={() => setReview(false)} onSubmitted={onSubmitted} />;
    }

    if (isRecount) {
        const labels = Object.fromEntries(pack.task.aisles.map((a) => [a.id, a.label]));
        const products = (pack.task.recount ?? []).map((id) => index.products.get(id)).filter(Boolean);

        // "Where are you counting?" is the aisle in the counting screen's
        // header; each count is saved with the aisle it was found in.
        return (
            <CountScreen
                session={session}
                aisle={aisle}
                aisles={pack.task.aisles}
                onAisle={setAisle}
                recount={{ products, aisleLabel: (id) => labels[id] ?? '' }}
                onBack={onBack}
                onHandIn={() => setReview(true)}
            />
        );
    }

    if (isQuick) {
        // An aisle added offline gets its real id once signal returns: follow it.
        const current = aisle ? (quickAisles.find((a) => a.id === aisle.id || a.was === aisle.id) ?? aisle) : null;
        return (
            <>
                {current && (
                    <CountScreen
                        session={session}
                        aisle={current}
                        aisles={quickAisles}
                        onAisle={(next) => { if (next) pickWhere(next); }}
                        onWhere={() => setWhere('change')}
                        blocked={!!where}
                        onBack={onBack}
                        onHandIn={() => setReview(true)}
                    />
                )}
                {where && (
                    <WhereAmI
                        taskId={taskId}
                        aisles={quickAisles}
                        current={current}
                        check={where === 'check'}
                        onPick={pickWhere}
                        onAdded={(added) => addAisleToPack(taskId, added)}
                        onAddLocal={(name) => addLocalAisle(taskId, name)}
                        onClose={where === 'start' && !current ? onBack : () => setWhere(null)}
                    />
                )}
            </>
        );
    }

    if (aisle) {
        return (
            <CountScreen
                session={session}
                aisle={aisle}
                aisles={pack.task.aisles}
                onAisle={(next) => { if (next) setAisle(next); }}
                onBack={isQuick ? onBack : () => setAisle(null)}
                onHandIn={isQuick ? () => setReview(true) : null}
            />
        );
    }

    const counts = {};
    for (const row of rows) if (row.syncStatus !== 'rejected') counts[row.aisleId] = (counts[row.aisleId] ?? 0) + 1;

    return (
        <div className="mx-auto max-w-xl space-y-3 p-3 pb-28">
            <button type="button" onClick={onBack} className="min-h-12 px-1 text-base font-semibold text-teal-700">← My tasks</button>
            <div className="flex items-center justify-between gap-2 px-1">
                <h1 className="text-xl font-extrabold text-gray-900 dark:text-gray-100">Which aisle?</h1>
                <SyncChip status={status} onAttention={() => setReview(true)} />
            </div>
            {pack.task.stock_take && <p className="px-1 text-base text-gray-600 dark:text-gray-300">{pack.task.stock_take.reference} · {pack.task.stock_take.name}</p>}

            <ul className="space-y-3">
                {pack.task.aisles.map((a) => (
                    <li key={a.id}>
                        <button type="button" onClick={() => setAisle(a)} className="flex min-h-16 w-full items-center justify-between rounded-2xl border border-gray-200 bg-white p-4 text-left shadow-sm active:scale-[0.99] dark:border-gray-800 dark:bg-gray-900">
                            <span className="text-lg font-bold text-gray-900 dark:text-gray-100">{a.label}</span>
                            <span className={`text-base font-semibold ${counts[a.id] ? 'text-teal-700' : 'text-gray-500'}`}>
                                {`${counts[a.id] ?? 0} / ${a.expected.length} counted`}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>

            <div className="fixed inset-x-0 bottom-0 z-10 border-t border-gray-200 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-gray-800 dark:bg-gray-900">
                <button type="button" onClick={() => setReview(true)} className="mx-auto block h-14 w-full max-w-xl rounded-2xl border-2 border-teal-700 text-lg font-extrabold text-teal-800 dark:text-teal-200">
                    Submit count
                </button>
            </div>
        </div>
    );
}
