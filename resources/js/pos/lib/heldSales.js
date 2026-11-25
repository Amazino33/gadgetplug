import api from './api';
import { db } from './db';

/**
 * Held (suspended) sales, offline as well as on.
 *
 * Each cashier's list lives on the server, so they can pick a held sale up
 * from any till. But the till never waits for it: holding, resuming and
 * discarding all happen on this till first and are sent to the server in the
 * background. On mobile data a round trip could take many seconds, up to the
 * 30-second timeout on a weak signal. Holding a sale meant waiting for an
 * upload and then for the list, and resuming meant waiting again, while the
 * customer stood at the counter. With no signal at all it was instant, because
 * the request failed at once and the till took the offline path. That path is
 * now the only one.
 *
 * A 'hold' row is a sale held here and not yet uploaded. A 'remove' row is a
 * held sale taken off the list here, waiting to be taken off the server's:
 * by its server id, or by this till's reference when it was held here and
 * may have reached the server before the till learned its id.
 */

// Per cashier: held sales are each cashier's own, and a till is often shared.
const CACHE_KEY = (vendorId, cashierId) => `pos_suspended_cache:${vendorId}:${cashierId}`;

/**
 * Whether a queued row is this cashier's. Rows queued by the first build of
 * this feature carry no cashier, and are treated as whoever is signed in.
 */
const ownedBy = (cashierId) => (row) => row.cashier_id == null || Number(row.cashier_id) === Number(cashierId);

/**
 * Goes up whenever this till changes a held sale, here or on the server. A
 * list the server sent from before the change may still show what was just
 * resumed, so a list asked for before it is not trusted.
 */
let version = 0;

export const heldSalesVersion = () => version;

const changed = () => { version += 1; };

/** A reference the server uses to save an uploaded hold once, however often it is retried. */
export function newClientRef() {
    const rand = globalThis.crypto?.randomUUID?.()
        ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`;

    return `hold-${rand}`;
}

/** True when a request failed because nothing answered, rather than because the server said no. */
export function unreachable(error) {
    return ! error?.response;
}

/** Holds a sale on this till. It is uploaded on the next sync. */
export async function holdLocally(vendorId, cashierId, payload) {
    await db.heldSales.add({
        kind:       'hold',
        vendor_id:  Number(vendorId),
        cashier_id: Number(cashierId),
        client_ref: payload.client_ref ?? newClientRef(),
        payload,
        created_at: new Date().toISOString(),
    });

    changed();
}

/** Takes a server-held sale off the list here. The server is told on the next sync. */
export async function queueRemoval(vendorId, cashierId, serverId) {
    await db.heldSales.add({
        kind:       'remove',
        vendor_id:  Number(vendorId),
        cashier_id: Number(cashierId),
        server_id:  serverId,
        created_at: new Date().toISOString(),
    });

    changed();
}

/**
 * Takes a sale held on this till off the list, when it is resumed or
 * discarded.
 *
 * If its upload has been started, the server may already have it (the answer
 * may still be on its way, or may have been lost), so the server is asked to
 * drop it by this till's reference. Without that, it would come back on the
 * list on the next refresh. The same goes for a hold whose upload has just
 * finished, and whose row is gone, while the list on screen still shows it.
 */
export async function takeLocalHold(vendorId, cashierId, sale) {
    await db.transaction('rw', db.heldSales, async () => {
        const row = await db.heldSales.get(sale.local_id);

        if (row) await db.heldSales.delete(sale.local_id);

        // Never sent: the server has never heard of it, so nothing to undo.
        if (row && ! row.sent) return;

        if (! sale.client_ref) return;

        await db.heldSales.add({
            kind:       'remove',
            vendor_id:  Number(vendorId),
            cashier_id: Number(cashierId),
            client_ref: sale.client_ref,
            created_at: new Date().toISOString(),
        });
    });

    changed();
}

/** The last list the server gave, for when it cannot be asked (or while it is being asked). */
export function cachedServerList(vendorId, cashierId) {
    try {
        return JSON.parse(localStorage.getItem(CACHE_KEY(vendorId, cashierId)) ?? '[]');
    } catch {
        return [];
    }
}

export function cacheServerList(vendorId, cashierId, list) {
    try {
        localStorage.setItem(CACHE_KEY(vendorId, cashierId), JSON.stringify(list ?? []));
    } catch { /* storage full — the live list still shows */ }
}

/** Keeps the remembered list in step with what this till has just sent. */
function updateCache(vendorId, cashierId, edit) {
    cacheServerList(vendorId, cashierId, edit(cachedServerList(vendorId, cashierId)));
}

/**
 * What the Suspended list shows: the server's list (or the last one it gave),
 * less anything taken off it here and not yet sent, plus anything held here
 * and not yet uploaded, those marked `local`.
 */
export async function mergedHeldSales(vendorId, cashierId, serverList) {
    const rows = (await db.heldSales.where('vendor_id').equals(Number(vendorId)).toArray()).filter(ownedBy(cashierId));

    const removals = rows.filter((r) => r.kind === 'remove');
    const removedIds = new Set(removals.map((r) => r.server_id).filter((id) => id != null));
    const removedRefs = new Set(removals.map((r) => r.client_ref).filter(Boolean));
    const uploadedRefs = new Set((serverList ?? []).map((s) => s.client_ref).filter(Boolean));

    const local = rows
        .filter((r) => r.kind === 'hold' && ! uploadedRefs.has(r.client_ref))
        .map((r) => ({
            id:         `local-${r.id}`,
            local_id:   r.id,
            local:      true,
            client_ref: r.client_ref,
            label:      r.payload?.label ?? null,
            cart_data:  r.payload?.cart_data ?? { items: [] },
            customer:   r.payload?.cart_data?.customer ?? null,
            created_at: r.created_at,
        }));

    const server = (serverList ?? []).filter((s) =>
        ! removedIds.has(s.id) && ! (s.client_ref && removedRefs.has(s.client_ref)));

    return [...server, ...local];
}

/**
 * Sends this till's holds and removals to the server. Each is cleared only
 * once the server has it; anything that cannot be sent waits for the next
 * sync. A removal the server no longer has (already gone) is done.
 *
 * One at a time: a sync asked for while one is running runs again once it
 * finishes, so a hold uploaded twice in parallel can never be mistaken for one
 * that was resumed.
 */
let running = null;
let again = null;

export function syncHeldSales(vendorId, cashierId) {
    if (! vendorId || ! cashierId) return Promise.resolve();

    if (running) {
        again = [vendorId, cashierId];
        return running;
    }

    running = (async () => {
        let args = [vendorId, cashierId];

        while (args) {
            again = null;
            await syncOnce(...args);
            args = again;
        }
    })().finally(() => { running = null; });

    return running;
}

async function syncOnce(vendorId, cashierId) {
    // Only this cashier's: the server files an upload under whoever sends it,
    // so another cashier's offline hold waits until they are signed in.
    const rows = (await db.heldSales.where('vendor_id').equals(Number(vendorId)).toArray()).filter(ownedBy(cashierId));

    for (const row of rows) {
        try {
            if (row.kind === 'hold') {
                // Still here? It may have been resumed since the list was read.
                // Marked as sent before it goes, so resuming it from now on
                // also asks the server to drop it.
                const stillHeld = await db.transaction('rw', db.heldSales, async () => {
                    if (! await db.heldSales.get(row.id)) return false;
                    await db.heldSales.update(row.id, { sent: true });
                    return true;
                });

                if (! stillHeld) continue;

                const { data } = await api.post('/suspended', { vendor_id: vendorId, ...row.payload, client_ref: row.client_ref });

                await db.heldSales.delete(row.id);

                if (data?.id != null) {
                    updateCache(vendorId, cashierId, (list) =>
                        list.some((s) => s.id === data.id) ? list : [...list, data]);
                }
            } else {
                await sendRemoval(vendorId, row);
                await forgetRemoval(vendorId, cashierId, row);
            }

            changed();
        } catch (error) {
            if (row.kind === 'remove' && error?.response?.status === 404) {
                await forgetRemoval(vendorId, cashierId, row);
                changed();
                continue;
            }

            if (unreachable(error)) return; // still offline — try again next sync
        }
    }
}

function sendRemoval(vendorId, row) {
    const config = { data: { vendor_id: vendorId } };

    return row.server_id != null
        ? api.delete(`/suspended/${row.server_id}`, config)
        : api.delete(`/suspended/ref/${encodeURIComponent(row.client_ref)}`, config);
}

async function forgetRemoval(vendorId, cashierId, row) {
    await db.heldSales.delete(row.id);

    updateCache(vendorId, cashierId, (list) => list.filter((s) =>
        row.server_id != null ? s.id !== row.server_id : s.client_ref !== row.client_ref));
}
