import { db } from './db';

// How long a till keeps its own sales. Seven days is a working week: long
// enough to answer "what did I sell on Monday" and reprint for a customer who
// comes back, short enough that a busy counter on a cheap Android device does
// not grow without bound.
export const RETENTION_DAYS = 7;

const cutoff = () => Date.now() - RETENTION_DAYS * 24 * 60 * 60 * 1000;

const toMillis = (value) => {
    const ms = new Date(value ?? 0).getTime();

    return Number.isNaN(ms) ? 0 : ms;
};

/**
 * Record a completed sale on the device.
 *
 * Called for EVERY sale, not only the ones that failed to reach the server.
 * The queue in offlineSales exists to get a sale uploaded; this exists so the
 * cashier can still see it afterwards, which is a different job.
 */
export async function recordSale(sale, cashierId) {
    if (!cashierId) return null;

    // A sale that went straight up already carries its server identity; one
    // that was queued has only its offline_id until sync fills the rest in.
    const row = {
        cashier_id:      cashierId,
        server_id:       sale.id ?? null,
        reference:       sale.reference ?? null,
        offline_id:      sale.offline_id ?? null,
        completed_at:    sale.completed_at ?? new Date().toISOString(),
        total:           sale.total ?? 0,
        subtotal:        sale.subtotal ?? null,
        discount_amount: sale.discount_amount ?? 0,
        vat_amount:      sale.vat_amount ?? 0,
        payment_method:  sale.payment_method ?? null,
        items:           sale.items ?? [],
        // Local rows are always "completed": a void or a refund happens on the
        // server, and the device cannot know about one it never saw. The server
        // copy overrides this whenever the till is online, which is why status
        // must never be trusted from here alone.
        status:          'completed',
        synced:          sale.id ? 1 : 0,
    };

    return db.sales.add(row);
}

/**
 * Fill in a queued sale's server identity once it has uploaded.
 *
 * Without this a synced sale keeps only its offline_id, so its receipt can
 * never be fetched and it would appear twice the moment the server copy
 * arrived alongside it.
 */
export async function markSaleSynced(offlineId, { id, reference } = {}) {
    if (!offlineId) return 0;

    return db.sales
        .where('offline_id')
        .equals(offlineId)
        .modify({ server_id: id ?? null, reference: reference ?? null, synced: 1 });
}

/** This cashier's sales held on the device, newest first. */
export async function localSales(cashierId) {
    if (!cashierId) return [];

    const rows = await db.sales.where('cashier_id').equals(cashierId).toArray();

    return rows.sort((a, b) => toMillis(b.completed_at) - toMillis(a.completed_at));
}

/**
 * Merge what the device knows with what the server says.
 *
 * The server wins on anything it has an opinion about: a sale voided or
 * refunded after the fact is still "completed" locally, and showing that to a
 * cashier would be worse than showing nothing. Local rows survive only when the
 * server has no copy — either the sale has not synced yet, or it is older than
 * the page the server returned.
 */
export function mergeSales(local, server) {
    const merged = [...server];
    const seen = new Set();

    for (const sale of server) {
        if (sale.id) seen.add(`id:${sale.id}`);
        if (sale.reference) seen.add(`ref:${sale.reference}`);
    }

    for (const sale of local) {
        const byId = sale.server_id ? seen.has(`id:${sale.server_id}`) : false;
        const byRef = sale.reference ? seen.has(`ref:${sale.reference}`) : false;

        if (byId || byRef) continue;

        merged.push({
            id:              sale.server_id,
            reference:       sale.reference,
            completed_at:    sale.completed_at,
            total:           sale.total,
            payment_method:  sale.payment_method,
            items:           sale.items,
            status:          sale.status,
            // Lets the screen say so, rather than presenting an unsynced sale
            // as though the server had confirmed it.
            pending_sync:    sale.synced !== 1,
        });
    }

    return merged.sort((a, b) => toMillis(b.completed_at) - toMillis(a.completed_at));
}

/**
 * Drop anything past the retention window.
 *
 * Deliberately not scoped to one cashier: a device shared by staff who have
 * since left would otherwise keep their rows for ever, since nothing would
 * ever run a prune under their id.
 */
export async function pruneOldSales() {
    const rows = await db.sales.toArray();
    const stale = rows.filter((row) => toMillis(row.completed_at) < cutoff()).map((row) => row.id);

    if (stale.length === 0) return 0;

    await db.sales.bulkDelete(stale);

    return stale.length;
}

/**
 * Keep the rendered receipt on the device.
 *
 * The receipt is a server-rendered document — it carries the QR, the store
 * address and the vendor's own settings, none of which the till can rebuild
 * from a cart. Storing the HTML the first time it is fetched is what lets a
 * cashier hand a customer the same receipt later with no connection. It is a
 * few kilobytes per sale, inside a seven-day window, so it prunes itself along
 * with the sale it belongs to.
 */
export async function cacheReceipt(serverId, html) {
    if (!serverId || !html) return 0;

    return db.sales.where('server_id').equals(serverId).modify({ receipt_html: html });
}

/** The stored receipt for a sale, or null if this device never fetched one. */
export async function cachedReceipt(serverId) {
    if (!serverId) return null;

    const row = await db.sales.where('server_id').equals(serverId).first();

    return row?.receipt_html ?? null;
}
