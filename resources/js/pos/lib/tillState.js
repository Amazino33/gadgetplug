/**
 * What this till still holds unsynced, told to the server so a stock take's
 * approval screen can warn while sales rung here have not arrived.
 *
 * One device id per browser, kept in localStorage. The report groups unsynced
 * sales by the branch they were rung in, with the oldest of each.
 */
const DEVICE_KEY = 'pos_device_id';
const RESEND_MS  = 5 * 60 * 1000;

let last = { fingerprint: null, at: 0 };

export function deviceId() {
    try {
        let id = localStorage.getItem(DEVICE_KEY);
        if (!id) {
            id = `till-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
            localStorage.setItem(DEVICE_KEY, id);
        }
        return id;
    } catch {
        return 'till-unknown';
    }
}

/** [{ store_id, count, oldest_at }] for the sales not yet on the server. */
export function summarise(unsyncedSales) {
    const byStore = new Map();

    for (const sale of unsyncedSales) {
        const key = sale.store_id ?? null;
        const row = byStore.get(key) ?? { store_id: key, count: 0, oldest_at: null };
        row.count += 1;
        const at = sale.completed_at ?? null;
        if (at && (!row.oldest_at || at < row.oldest_at)) row.oldest_at = at;
        byStore.set(key, row);
    }

    return [...byStore.values()];
}

/**
 * Send the report when it has changed, or every few minutes to say the till
 * is still here. Returns whether it sent.
 */
export async function reportTillState(api, vendorId, unsyncedSales, now = Date.now()) {
    const pending = summarise(unsyncedSales);
    const fingerprint = JSON.stringify(pending);

    if (fingerprint === last.fingerprint && now - last.at < RESEND_MS) return false;

    try {
        await api.post('/till-state', { vendor_id: vendorId, device_id: deviceId(), pending });
        last = { fingerprint, at: now };
        return true;
    } catch {
        return false; // tried again next tick
    }
}

/** For tests. */
export function resetTillState() {
    last = { fingerprint: null, at: 0 };
}
