import Dexie from 'dexie';

/**
 * Stock Take's own store on the phone. Separate from the till's (BizacPOS):
 * nothing here can touch a sale, and clearing one never clears the other.
 *
 *   packs    one per task: what was downloaded to count it with no network
 *   entries  what has been counted, one per task + aisle + product — the
 *            phone's copy is the truth while counting; syncStatus says
 *            whether the server has it yet
 *   outbox   operations not yet acknowledged by the server, in order
 *   meta     small settings (this device's id, …)
 */
export const db = new Dexie('GadgetPlugStockTake');

db.version(1).stores({
    packs:   'taskId',
    entries: '[taskId+aisleId+productId], taskId, [taskId+aisleId], syncStatus',
    outbox:  '++seq, taskId, opId',
    meta:    'key',
});

export async function deviceId() {
    const row = await db.meta.get('deviceId');
    if (row) return row.value;

    const id = `phone-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
    await db.meta.put({ key: 'deviceId', value: id });
    return id;
}

/** A small value kept on the phone (`meta`), or `fallback`. */
export async function getMeta(key, fallback = null) {
    return (await db.meta.get(key))?.value ?? fallback;
}

export async function setMeta(key, value) {
    if (value === null || value === undefined) await db.meta.delete(key);
    else await db.meta.put({ key, value });
}
