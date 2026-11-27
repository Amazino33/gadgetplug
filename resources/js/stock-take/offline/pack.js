import { db, deviceId, setMeta } from './db';
import { offsetFrom } from './clock';

/**
 * The task pack: downloaded once, kept on the phone, counted against with no
 * network. See StockTakePack on the server for what is in it.
 *
 * The download says which phone this is: the first phone to download a task
 * holds it (A7), and another is refused with code 'other_phone'.
 *
 * Downloading again (a refresh) never loses a count. What the server already
 * holds for the task comes in as "synced"; anything counted on this phone and
 * not yet uploaded is left exactly as it is, and wins.
 */
export async function downloadPack(api, taskId, onProgress = null) {
    const sentAt = Date.now();
    let pack;
    try {
        onProgress?.({ stage: 'preparing', loaded: 0 });
        ({ data: pack } = await api.get(`/tasks/${taskId}/pack`, {
            params: { device: await deviceId() },
            onDownloadProgress: (e) => onProgress?.({ stage: 'downloading', loaded: e.loaded ?? 0 }),
        }));
        onProgress?.({ stage: 'saving', loaded: 0, products: pack?.products?.length ?? 0 });
    } catch (error) {
        // One phone per task (A7): another phone holds it.
        if (error?.response?.status === 409) error.code = 'other_phone';
        throw error;
    }
    const receivedAt = Date.now();
    await setMeta(`blocked:${taskId}`, null);

    const clockOffset = offsetFrom(pack.server_time, sentAt, receivedAt) ?? 0;

    await db.transaction('rw', db.packs, db.entries, db.outbox, async () => {
        await db.packs.put({ taskId, pack, downloadedAt: receivedAt, clockOffset });

        // Anything changed here and still waiting to go up — a count, or a
        // count taken back — is newer than the server's copy of it.
        const waiting = new Set(
            (await db.outbox.where('taskId').equals(taskId).toArray())
                .map((row) => `${row.op.aisle_id}:${row.op.product_id}`),
        );

        for (const [aisleId, productId, counted, breakdown, countedAt, unlisted, notOnShelf, damaged = 0, expired = 0] of pack.entries ?? []) {
            const key = [taskId, aisleId, productId];
            if (waiting.has(`${aisleId}:${productId}`)) continue;

            const local = await db.entries.get(key);
            if (local && local.syncStatus !== 'synced') continue;

            await db.entries.put({
                taskId,
                aisleId,
                productId,
                counted,
                breakdown: breakdown ?? [],
                damaged: Number(damaged ?? 0),
                expired: Number(expired ?? 0),
                countedAt,
                deviceCountedAt: null,
                unlisted: !!unlisted,
                notOnShelf: !!notOnShelf,
                syncStatus: 'synced',
                reason: null,
                lastOpId: null,
                updatedAt: receivedAt,
            });
        }
    });

    return { pack, clockOffset, downloadedAt: receivedAt };
}

/** The pack on this phone, or null if it was never downloaded. */
export async function storedPack(taskId) {
    return (await db.packs.get(taskId)) ?? null;
}

/** The pack on the phone if there is one, otherwise download it. */
export async function ensurePack(api, taskId, onProgress = null) {
    return (await storedPack(taskId)) ?? downloadPack(api, taskId, onProgress);
}

export async function updateClockOffset(taskId, clockOffset) {
    if (clockOffset === null || clockOffset === undefined) return;
    await db.packs.update(taskId, { clockOffset });
}

/**
 * An aisle added on the phone during a Quick count (count and map in one
 * walk): kept in the task's pack, so it is still in the list after a reload
 * or with no signal. Already there (the same aisle again): left as it is.
 */
export async function addAisleToPack(taskId, aisle) {
    await db.transaction('rw', db.packs, async () => {
        const row = await db.packs.get(taskId);
        if (!row?.pack?.task) return;
        const aisles = row.pack.task.aisles ?? [];
        if (aisles.some((a) => a.id === aisle.id)) return;
        await db.packs.update(taskId, { pack: { ...row.pack, task: { ...row.pack.task, aisles: [...aisles, aisle] } } });
    });
}

/** Where a Quick count's counter last said they were, and when (the 30-minute check). */
export const whereKey = (taskId) => `where:${taskId}`;

const sameName = (a, b) => String(a ?? '').trim().toLowerCase() === String(b ?? '').trim().toLowerCase();

/**
 * An aisle added with no signal (Quick count): made on the phone at once, with
 * a temporary id below zero, and counted in straight away. Before any count
 * goes up, settleNewAisles() creates it on the server and swaps the temporary
 * id for the real one everywhere on the phone. The same name as an aisle the
 * phone already has is that aisle, not a new one.
 */
export async function addLocalAisle(taskId, name) {
    const clean = String(name).trim().replace(/\s+/g, ' ');
    let made = null;

    await db.transaction('rw', db.packs, async () => {
        const row = await db.packs.get(taskId);
        if (!row?.pack?.task) return;
        const aisles = row.pack.task.aisles ?? [];

        made = aisles.find((a) => !a.quick && (sameName(a.name, clean) || sameName(a.label, clean)));
        if (made) return;

        made = { id: -Date.now(), name: clean, label: clean, quick: false, held_by: null, expected: [], pending: true };
        await db.packs.update(taskId, { pack: { ...row.pack, task: { ...row.pack.task, aisles: [...aisles, made] } } });
    });

    return made;
}

/**
 * Aisles added offline are created on the server, then every count, waiting
 * upload and "where am I" on the phone moves from the temporary id to the real
 * one. False while there is no signal (nothing that uses them goes up yet).
 */
export async function settleNewAisles(api, taskId) {
    const row = await db.packs.get(taskId);
    const pending = (row?.pack?.task?.aisles ?? []).filter((a) => a.pending);

    for (const temp of pending) {
        let real;
        try {
            ({ data: { aisle: real } } = await api.post(`/tasks/${taskId}/aisles`, { name: temp.name }));
        } catch (error) {
            if (!error?.response) return false; // no signal: try again later
            continue; // refused: its counts stay on the phone and say why when sent
        }

        await db.transaction('rw', db.packs, async () => {
            const fresh = await db.packs.get(taskId);
            const aisles = (fresh?.pack?.task?.aisles ?? [])
                .filter((a) => a.id !== temp.id && a.id !== real.id)
                .concat({ ...real, was: temp.id });
            await db.packs.update(taskId, { pack: { ...fresh.pack, task: { ...fresh.pack.task, aisles } } });
        });
    }

    await moveToRealIds(taskId);
    return true;
}

/**
 * Every count, waiting upload and "where am I" still on a temporary aisle id
 * whose aisle now has a real one: moved over. Run each time, so a count saved
 * in the moment the swap happened is never left behind.
 */
async function moveToRealIds(taskId) {
    const row = await db.packs.get(taskId);
    const swaps = (row?.pack?.task?.aisles ?? []).filter((a) => a.was).map((a) => [a.was, a.id]);
    if (swaps.length === 0) return;

    await db.transaction('rw', db.entries, db.outbox, db.meta, async () => {
        for (const [temp, real] of swaps) {
            const moved = await db.entries.where({ taskId, aisleId: temp }).toArray();
            for (const entry of moved) {
                await db.entries.delete([taskId, temp, entry.productId]);
                await db.entries.put({ ...entry, aisleId: real });
            }

            const ops = await db.outbox.where('taskId').equals(taskId).toArray();
            for (const op of ops) {
                if (op.op?.aisle_id === temp) await db.outbox.update(op.seq, { op: { ...op.op, aisle_id: real } });
            }

            const where = await db.meta.get(whereKey(taskId));
            if (where?.value?.aisleId === temp) await db.meta.put({ key: whereKey(taskId), value: { ...where.value, aisleId: real } });
        }
    });
}
