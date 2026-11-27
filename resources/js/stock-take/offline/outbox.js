import { db, deviceId, getMeta, setMeta } from './db';
import { iso, offsetFrom, serverNow } from './clock';
import { settleNewAisles, updateClockOffset } from './pack';
import { newSaveId } from '../lib/saveId';

/**
 * Counting on the phone first, telling the server afterwards.
 *
 * A save or a take-back is written to the phone's store at once — the entry
 * and an operation describing it, in one transaction — and the screen moves on.
 * Nothing waits on the network.
 *
 * The uploader sends the operations in the order they were made, in batches,
 * in the background, and drops each only once the server has answered for it.
 * Every operation has its own id; a batch whose answer is lost is sent again
 * and the server applies nothing twice. A "set" carries what the entry now is
 * (not a change), so even a replay lands the same.
 *
 * Handing in (A6) is the last operation of all: it goes up only once every
 * count before it has, so it can wait for signal like any other.
 *
 * Every upload says which phone it comes from and how much it still has
 * waiting (A7). What the last upload did is kept (`sync:<task>` in meta) for
 * the sync chip.
 */

const BATCH = 25;
const EVERY_MS = 20_000;
const MAX_BACKOFF_MS = 60_000;

/** What the server should be told for one entry: the units as typed. */
function linesOf(breakdown) {
    return (breakdown ?? []).map((line) => ({ unit_id: line.unit_id ?? null, qty: String(line.qty) }));
}

/**
 * Record a count on the phone. `entry` is what the entry now is (Add has
 * already been worked out). Returns at once.
 *
 * Undo puts an earlier count back as it was: it passes that count's own
 * `countedAt` and `deviceCountedAt`, so the stock it is compared with is
 * still the stock at the moment it was really counted.
 */
export async function saveCount(taskId, clockOffset, entry) {
    const now = Date.now();
    const opId = newSaveId();
    const countedAt = entry.countedAt ?? iso(serverNow(clockOffset, now));
    const deviceCountedAt = entry.deviceCountedAt ?? iso(now);

    await db.transaction('rw', db.entries, db.outbox, async () => {
        await db.entries.put({
            taskId,
            aisleId: entry.aisleId,
            productId: entry.productId,
            counted: entry.counted,
            breakdown: entry.breakdown,
            // Damaged and expired, in base units, apart from the good count.
            damaged: Number(entry.damaged ?? 0),
            expired: Number(entry.expired ?? 0),
            countedAt,
            deviceCountedAt,
            unlisted: !!entry.unlisted,
            notOnShelf: false,
            syncStatus: 'pending',
            reason: null,
            lastOpId: opId,
            updatedAt: now,
        });

        await db.outbox.add({
            taskId,
            opId,
            op: {
                id: opId,
                type: 'set',
                aisle_id: entry.aisleId,
                product_id: entry.productId,
                lines: linesOf(entry.breakdown),
                damaged: Number(entry.damaged ?? 0),
                expired: Number(entry.expired ?? 0),
                counted_at: countedAt,
                device_counted_at: deviceCountedAt,
            },
            createdAt: now,
        });
    });

    return opId;
}

/** Take a count back on the phone. Returns at once. */
export async function removeCount(taskId, aisleId, productId) {
    const opId = newSaveId();

    await db.transaction('rw', db.entries, db.outbox, async () => {
        await db.entries.delete([taskId, aisleId, productId]);
        await db.outbox.add({
            taskId,
            opId,
            op: { id: opId, type: 'remove', aisle_id: aisleId, product_id: productId },
            createdAt: Date.now(),
        });
    });

    return opId;
}

export async function entriesFor(taskId, aisleId = null) {
    const rows = aisleId === null
        ? await db.entries.where('taskId').equals(taskId).toArray()
        : await db.entries.where('[taskId+aisleId]').equals([taskId, aisleId]).toArray();

    return rows.sort((a, b) => b.updatedAt - a.updatedAt);
}

/** Counts and take-backs not yet on the server (a queued hand-in isn't one). */
export async function waitingCount(taskId) {
    return db.outbox.where('taskId').equals(taskId).filter((row) => row.op.type !== 'submit').count();
}

/**
 * Hand the task in once everything before it has gone up (A6). `decisions`
 * answers every product with no count: product id → 'not_on_shelf' | 'skip'.
 * Only one is ever waiting: handing in again replaces it.
 */
export async function queueHandIn(taskId, decisions) {
    const opId = newSaveId();
    await db.transaction('rw', db.outbox, db.meta, async () => {
        await db.outbox.where('taskId').equals(taskId).filter((row) => row.op.type === 'submit').delete();
        await db.outbox.add({ taskId, opId, op: { id: opId, type: 'submit', decisions }, createdAt: Date.now() });
        await db.meta.put({ key: `handIn:${taskId}`, value: { status: 'queued', at: Date.now() } });
    });
    return opId;
}

/** The hand-in: null, or {status: 'queued' | 'done' | 'refused', reason?}. */
export const handInState = (taskId) => getMeta(`handIn:${taskId}`);

/** Tasks with anything still waiting to go up. */
export async function tasksWaiting() {
    const rows = await db.outbox.toArray();
    return [...new Set(rows.map((row) => row.taskId))];
}

/**
 * Send what is waiting, oldest first, until the outbox is empty or the
 * network fails. Safe to call any time and as often as wanted.
 *
 * @returns {Promise<{sent: number, rejected: number, failed: boolean, network?: boolean, blocked?: boolean, handedIn?: boolean, handInRefused?: boolean}>}
 */
const inFlight = new Map();

export function syncNow(api, taskId) {
    // One upload per task at a time: a second caller waits for the first.
    if (!inFlight.has(taskId)) {
        inFlight.set(taskId, upload(api, taskId)
            .then(async (result) => {
                await setMeta(`sync:${taskId}`, {
                    at: Date.now(),
                    failed: !!result.failed,
                    network: !!result.network,
                    blocked: !!result.blocked,
                });
                return result;
            })
            .finally(() => inFlight.delete(taskId)));
    }
    return inFlight.get(taskId);
}

async function upload(api, taskId) {
    let sent = 0;
    let rejected = 0;
    const device = await deviceId();

    // Aisles added with no signal go up first, so their counts carry real ids.
    if (!(await settleNewAisles(api, taskId))) return { sent, rejected, failed: true, network: true };

    for (;;) {
        const rows = await db.outbox.where('taskId').equals(taskId).sortBy('seq');
        if (rows.length === 0) return { sent, rejected, failed: false };

        if (rows[0].op.type === 'submit') {
            return { sent, rejected, ...(await handIn(api, taskId, rows[0], device)) };
        }

        // Counts up to (not including) a hand-in, 25 at a time.
        const stop = rows.findIndex((row) => row.op.type === 'submit');
        // Never a count in an aisle the server does not know yet (temporary id below zero).
        const batch = rows.slice(0, stop === -1 ? rows.length : stop).filter((row) => !(row.op.aisle_id < 0)).slice(0, BATCH);
        if (batch.length === 0) return { sent, rejected, failed: true, network: true };
        const waiting = rows.filter((row) => row.op.type !== 'submit').length;

        const sentAt = Date.now();
        let data;
        try {
            ({ data } = await api.post(`/tasks/${taskId}/sync`, { device, waiting, ops: batch.map((row) => row.op) }));
        } catch (error) {
            // Another phone holds the task (A7): keep everything, say so.
            if (error?.response?.status === 409) {
                await setMeta(`blocked:${taskId}`, error.response.data?.message ?? 'This task is being counted on another phone.');
                return { sent, rejected, failed: true, blocked: true };
            }
            // Refused outright, or no connection: keep everything; try again later.
            return { sent, rejected, failed: true, network: !error?.response, status: error?.response?.status ?? null };
        }

        await updateClockOffset(taskId, offsetFrom(data.server_time, sentAt, Date.now()));

        const results = new Map((data.results ?? []).map((r) => [r.id, r]));

        await db.transaction('rw', db.entries, db.outbox, async () => {
            for (const row of batch) {
                const result = results.get(row.opId);
                if (!result) continue; // not answered: stays, goes again

                await db.outbox.delete(row.seq);
                sent += 1;
                if (result.status === 'rejected') rejected += 1;

                if (row.op.type !== 'set') continue;

                // Only the newest change to an entry decides how it shows.
                const key = [taskId, row.op.aisle_id, row.op.product_id];
                const entry = await db.entries.get(key);
                if (entry && entry.lastOpId === row.opId) {
                    await db.entries.update(key, {
                        syncStatus: result.status === 'rejected' ? 'rejected' : 'synced',
                        reason: result.reason ?? null,
                    });
                }
            }
        });

        // Nothing in the batch was answered: stop rather than spin.
        if (!batch.some((row) => results.has(row.opId))) return { sent, rejected, failed: true };
    }
}

/** The queued hand-in, now that every count before it is up. */
async function handIn(api, taskId, row, device) {
    try {
        await api.post(`/tasks/${taskId}/submit`, { device, decisions: row.op.decisions ?? {} });
    } catch (error) {
        if (!error?.response) return { failed: true, network: true };

        const status = error.response.status;
        if (status === 409) {
            await setMeta(`blocked:${taskId}`, error.response.data?.message ?? 'This task is being counted on another phone.');
            return { failed: true, blocked: true };
        }
        if (status === 422 || status === 403) {
            // Refused (something new to answer, or the task was closed): it
            // waits no more; the reason shows in Needs attention.
            const body = error.response.data;
            const reason = (body?.errors && Object.values(body.errors).flat()[0]) || body?.message || 'The submission was not accepted.';
            await db.transaction('rw', db.outbox, db.meta, async () => {
                await db.outbox.delete(row.seq);
                await db.meta.put({ key: `handIn:${taskId}`, value: { status: 'refused', reason, at: Date.now() } });
            });
            return { failed: false, handInRefused: true };
        }
        return { failed: true, status };
    }

    await db.transaction('rw', db.outbox, db.meta, db.packs, async () => {
        await db.outbox.delete(row.seq);
        await db.meta.put({ key: `handIn:${taskId}`, value: { status: 'done', at: Date.now() } });
        await db.packs.update(taskId, { handedInAt: Date.now() });
    });
    return { failed: false, handedIn: true };
}

/**
 * The background uploader for one task: every 20 seconds, once 25 operations
 * are waiting, when the network comes back, and when the app goes to the
 * background. Backs off after a failure. Never blocks anything.
 */
export function startSync(api, taskId, { onChange } = {}) {
    return uploader(api, async () => [taskId], { onChange });
}

/**
 * The same for every task with anything waiting (A7): counts from a task
 * left for another keep going up while the app is open.
 */
export function startAllSync(api, { onChange } = {}) {
    return uploader(api, tasksWaiting, { onChange });
}

function uploader(api, which, { onChange } = {}) {
    let timer = null;
    let running = false;
    let stopped = false;
    let backoff = 0;

    const schedule = (ms) => {
        clearTimeout(timer);
        if (!stopped) timer = setTimeout(run, ms);
    };

    async function run() {
        if (running || stopped) return;
        running = true;
        let failed = false;
        try {
            for (const taskId of await which()) {
                const result = await syncNow(api, taskId);
                failed ||= result.failed;
                onChange?.(result, taskId);
            }
            backoff = failed ? Math.min(MAX_BACKOFF_MS, Math.max(2_000, backoff * 2)) : 0;
        } finally {
            running = false;
            schedule(backoff || EVERY_MS);
        }
    }

    const onOnline = () => { backoff = 0; run(); };
    const onHidden = () => { if (document.visibilityState === 'hidden') run(); };

    window.addEventListener('online', onOnline);
    document.addEventListener('visibilitychange', onHidden);
    schedule(0);

    return {
        /** A count was just saved: send soon if a full batch is waiting. */
        async nudge(taskId) {
            const ids = taskId ? [taskId] : await which();
            for (const id of ids) {
                if ((await waitingCount(id)) >= BATCH) { run(); return; }
            }
        },
        /** Send now (hand-in, Retry). */
        async flush() {
            backoff = 0;
            for (const id of await which()) await syncNow(api, id);
        },
        stop() {
            stopped = true;
            clearTimeout(timer);
            window.removeEventListener('online', onOnline);
            document.removeEventListener('visibilitychange', onHidden);
        },
    };
}
