import { db } from './db';

/**
 * The till's side of vendor pickings.
 *
 * Deliberately thin. This file caches what the server said and queues what the
 * cashier did — it never works out what a payment settles. That decision needs
 * live prices and live outstanding, and a till that has been offline since
 * morning has neither. Duplicating the allocation here would also mean two
 * implementations of the same rule drifting apart, and the one on the till
 * would be the one nobody tests against real money.
 */

/** Keep the last list the server gave us, so an offline till can still work. */
export async function cachePickings(vendorId, payload) {
    if (!vendorId || !payload) return null;

    return db.pickingCache.put({
        vendor_id: vendorId,
        store_id: payload.store_id ?? null,
        pickers: payload.pickers ?? [],
        cached_at: new Date().toISOString(),
    });
}

/** What the server last said, or null if this till has never been online. */
export async function cachedPickings(vendorId) {
    if (!vendorId) return null;

    return (await db.pickingCache.get(vendorId)) ?? null;
}

/**
 * Record a payment the cashier has taken.
 *
 * Queued whether or not there is a connection: the caller uploads it straight
 * away when online and lets the queue carry it when not, so there is exactly
 * one path and no way for a payment taken at the counter to exist nowhere.
 */
export async function queuePickingPayment({ vendorId, pickerId, amount, itemIds, paymentMethod = 'cash' }) {
    const offlineId = `pick-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

    await db.pickingPayments.add({
        offline_id: offlineId,
        vendor_id: vendorId,
        picker_id: pickerId,
        amount,
        item_ids: itemIds,
        payment_method: paymentMethod,
        created_at: new Date().toISOString(),
        synced: 0,
    });

    return offlineId;
}

/** Payments still waiting to reach the server. */
export async function pendingPickingPayments() {
    return db.pickingPayments.where('synced').equals(0).toArray();
}

/**
 * A payment has landed. Kept rather than deleted, and marked instead, so the
 * cashier can still see it went up.
 */
export async function markPickingPaymentSynced(offlineId, result = {}) {
    if (!offlineId) return 0;

    return db.pickingPayments
        .where('offline_id')
        .equals(offlineId)
        .modify({
            synced: 1,
            settled_units: result.settled_units ?? null,
            change: result.change ?? null,
            server_reference: result.reference ?? null,
        });
}

/**
 * Money queued against a line but not yet applied.
 *
 * Shown beside the line so a cashier can see they have already taken something
 * for it, without pretending to know how many units it will settle — the server
 * decides that, and may settle fewer if the price moved or somebody else paid
 * first.
 */
export async function queuedAmountByItem() {
    const pending = await pendingPickingPayments();
    const totals = {};

    for (const payment of pending) {
        for (const itemId of payment.item_ids ?? []) {
            totals[itemId] = (totals[itemId] ?? 0) + Number(payment.amount ?? 0);
        }
    }

    return totals;
}

/** Drop synced payments older than a week, so the queue table cannot grow for ever. */
export async function prunePickingPayments() {
    const cutoff = Date.now() - 7 * 24 * 60 * 60 * 1000;
    const rows = await db.pickingPayments.where('synced').equals(1).toArray();
    const stale = rows
        .filter((row) => new Date(row.created_at ?? 0).getTime() < cutoff)
        .map((row) => row.id);

    if (stale.length === 0) return 0;

    await db.pickingPayments.bulkDelete(stale);

    return stale.length;
}
