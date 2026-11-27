import { useEffect, useState } from 'react';
import { liveQuery } from 'dexie';
import { db, getMeta } from '../offline/db';
import { waitingCount } from '../offline/outbox';

/**
 * Where a task's counts are, for the sync chip (A9), kept up to date as the
 * phone's store changes:
 *
 *   saved      everything is on the server          "✓ All saved"
 *   waiting    uploading in the background          "⬆ 14 waiting"
 *   offline    no signal; all kept on the phone     "📴 Offline — saved on phone"
 *   attention  something the server refused        "⚠ 2 need attention"
 */
export function useSyncStatus(taskId) {
    const [store, setStore] = useState({ waiting: 0, rejected: [], handIn: null, blocked: null, last: null });
    const [online, setOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine !== false));

    useEffect(() => {
        const up = () => setOnline(true);
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);
        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, []);

    useEffect(() => {
        if (!taskId) return undefined;
        const subscription = liveQuery(async () => ({
            waiting: await waitingCount(taskId),
            rejected: await db.entries.where('taskId').equals(taskId).filter((e) => e.syncStatus === 'rejected').toArray(),
            handIn: await getMeta(`handIn:${taskId}`),
            blocked: await getMeta(`blocked:${taskId}`),
            last: await getMeta(`sync:${taskId}`),
        })).subscribe({ next: setStore, error: () => {} });
        return () => subscription.unsubscribe();
    }, [taskId]);

    return statusOf(store, online);
}

/** The chip's state from what the store holds (plain, for testing). */
export function statusOf({ waiting, rejected, handIn, blocked, last }, online = true) {
    const refused = handIn?.status === 'refused' ? 1 : 0;
    const attention = (rejected?.length ?? 0) + refused + (blocked ? 1 : 0);
    const queuedHandIn = handIn?.status === 'queued';
    const pending = waiting > 0 || queuedHandIn;
    const offline = !online || (pending && !!last?.network);

    let kind = 'saved';
    if (attention > 0) kind = 'attention';
    else if (offline && pending) kind = 'offline';
    else if (pending) kind = 'waiting';
    else if (!online) kind = 'offline';

    return { kind, waiting, attention, rejected: rejected ?? [], handIn, blocked, queuedHandIn, offline };
}
