import api from './api';
import { db } from './db';

/**
 * Held (suspended) sales, offline as well as on.
 *
 * The shared list lives on the server, so every till in the branch sees every
 * held sale. When the server cannot be reached, a sale is held on this till
 * instead and uploaded when the connection returns; and a sale already on the
 * server's list can still be resumed or discarded here, with its removal from
 * the server's list sent later.
 */

// Per cashier: held sales are each cashier's own, and a till is often shared.
const CACHE_KEY = (vendorId, cashierId) => `pos_suspended_cache:${vendorId}:${cashierId}`;

/**
 * Whether a queued row is this cashier's. Rows queued by the first build of
 * this feature carry no cashier, and are treated as whoever is signed in.
 */
const ownedBy = (cashierId) => (row) => row.cashier_id == null || Number(row.cashier_id) === Number(cashierId);

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

/** Holds a sale on this till, to be uploaded when the connection returns. */
export async function holdLocally(vendorId, cashierId, payload) {
    await db.heldSales.add({
        kind:       'hold',
        vendor_id:  Number(vendorId),
        cashier_id: Number(cashierId),
        client_ref: payload.client_ref ?? newClientRef(),
        payload,
        created_at: new Date().toISOString(),
    });
}

/** Remembers that a server-held sale was resumed or discarded here while offline. */
export async function queueRemoval(vendorId, cashierId, serverId) {
    await db.heldSales.add({
        kind:       'remove',
        vendor_id:  Number(vendorId),
        cashier_id: Number(cashierId),
        server_id:  serverId,
        created_at: new Date().toISOString(),
    });
}

export async function dropLocalHold(localId) {
    await db.heldSales.delete(localId);
}

/** The last list the server gave, for when it cannot be asked. */
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

/**
 * What the Suspended list shows: the server's list (or the last one it gave),
 * less anything taken off it here while offline, plus anything held here and
 * not yet uploaded — those marked `local`.
 */
export async function mergedHeldSales(vendorId, cashierId, serverList) {
    const rows = (await db.heldSales.where('vendor_id').equals(Number(vendorId)).toArray()).filter(ownedBy(cashierId));

    const removed = new Set(rows.filter((r) => r.kind === 'remove').map((r) => r.server_id));
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

    return [...(serverList ?? []).filter((s) => ! removed.has(s.id)), ...local];
}

/**
 * Sends this till's offline holds and removals to the server. Each is cleared
 * only once the server has it; anything that cannot be sent waits for the next
 * sync. A removal the server no longer has (resumed elsewhere) is done.
 */
export async function syncHeldSales(vendorId, cashierId) {
    if (! vendorId || ! cashierId) return;

    // Only this cashier's: the server files an upload under whoever sends it,
    // so another cashier's offline hold waits until they are signed in.
    const rows = (await db.heldSales.where('vendor_id').equals(Number(vendorId)).toArray()).filter(ownedBy(cashierId));

    for (const row of rows) {
        try {
            if (row.kind === 'hold') {
                await api.post('/suspended', { vendor_id: vendorId, ...row.payload, client_ref: row.client_ref });
            } else {
                await api.delete(`/suspended/${row.server_id}`, { data: { vendor_id: vendorId } });
            }

            await db.heldSales.delete(row.id);
        } catch (error) {
            if (row.kind === 'remove' && error?.response?.status === 404) {
                await db.heldSales.delete(row.id);
                continue;
            }

            if (unreachable(error)) return; // still offline — try again next sync
        }
    }
}
