import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { db } from './db';
import {
    cacheReceipt,
    cachedReceipt,
    localSales,
    markSaleSynced,
    mergeSales,
    pruneOldSales,
    recordSale,
    RETENTION_DAYS,
} from './salesHistory';

const daysAgo = (n) => new Date(Date.now() - n * 24 * 60 * 60 * 1000).toISOString();

const aSale = (over = {}) => ({
    id: null,
    reference: null,
    offline_id: null,
    completed_at: new Date().toISOString(),
    total: 1000,
    payment_method: 'cash',
    items: [{ product_id: 1, quantity: 1 }],
    ...over,
});

beforeEach(async () => {
    await db.sales.clear();
});

describe('recording what the till sold', () => {
    it('records a sale that reached the server, marked synced', async () => {
        await recordSale(aSale({ id: 42, reference: 'POS-ABC' }), 7);

        const [row] = await localSales(7);

        expect(row.server_id).toBe(42);
        expect(row.reference).toBe('POS-ABC');
        expect(row.synced).toBe(1);
    });

    it('records a queued sale as unsynced, with only its offline id', async () => {
        await recordSale(aSale({ offline_id: 'off-1' }), 7);

        const [row] = await localSales(7);

        expect(row.server_id).toBeNull();
        expect(row.offline_id).toBe('off-1');
        expect(row.synced).toBe(0);
    });

    it('fills in the server identity once a queued sale uploads', async () => {
        await recordSale(aSale({ offline_id: 'off-1' }), 7);

        await markSaleSynced('off-1', { id: 99, reference: 'POS-XYZ' });

        const [row] = await localSales(7);

        expect(row.server_id).toBe(99);
        expect(row.reference).toBe('POS-XYZ');
        expect(row.synced).toBe(1);
    });

    it('ignores a sale with no cashier rather than storing it unattributed', async () => {
        await recordSale(aSale({ id: 1 }), null);

        expect(await db.sales.count()).toBe(0);
    });
});

describe('whose sales a cashier sees', () => {
    it('returns only the signed-in cashier, though the table survives logout', async () => {
        await recordSale(aSale({ id: 1 }), 7);
        await recordSale(aSale({ id: 2 }), 8);

        const mine = await localSales(7);

        expect(mine).toHaveLength(1);
        expect(mine[0].server_id).toBe(1);
    });

    it('gives a cashier their history back after logging out and in', async () => {
        await recordSale(aSale({ id: 1 }), 7);

        // Logout clears products and settings; sales are deliberately left.
        await db.products.clear();

        expect(await localSales(7)).toHaveLength(1);
    });

    it('orders newest first', async () => {
        await recordSale(aSale({ id: 1, completed_at: daysAgo(2) }), 7);
        await recordSale(aSale({ id: 2, completed_at: daysAgo(0) }), 7);

        expect((await localSales(7)).map((s) => s.server_id)).toEqual([2, 1]);
    });
});

describe('merging the device with the server', () => {
    it('lets the server win, so a voided sale stops reading as completed', () => {
        const local = [{ server_id: 5, reference: 'POS-5', status: 'completed', total: 1000, synced: 1 }];
        const server = [{ id: 5, reference: 'POS-5', status: 'voided', total: 1000 }];

        const merged = mergeSales(local, server);

        expect(merged).toHaveLength(1);
        expect(merged[0].status).toBe('voided');
    });

    it('keeps a local sale the server has not seen, and flags it as pending', () => {
        const local = [{ server_id: null, reference: null, offline_id: 'off-1', completed_at: daysAgo(0), status: 'completed', synced: 0 }];

        const merged = mergeSales(local, []);

        expect(merged).toHaveLength(1);
        expect(merged[0].pending_sync).toBe(true);
    });

    it('does not show a synced sale twice when both copies are present', () => {
        const local = [{ server_id: 9, reference: 'POS-9', completed_at: daysAgo(0), synced: 1 }];
        const server = [{ id: 9, reference: 'POS-9', completed_at: daysAgo(0) }];

        expect(mergeSales(local, server)).toHaveLength(1);
    });

    it('matches on reference when the local row never learned its server id', () => {
        const local = [{ server_id: null, reference: 'POS-7', completed_at: daysAgo(0), synced: 1 }];
        const server = [{ id: 7, reference: 'POS-7', completed_at: daysAgo(0) }];

        expect(mergeSales(local, server)).toHaveLength(1);
    });

    it('returns everything newest first regardless of which side it came from', () => {
        const local = [{ server_id: null, offline_id: 'off-1', completed_at: daysAgo(1), synced: 0 }];
        const server = [{ id: 2, reference: 'POS-2', completed_at: daysAgo(3) }];

        const merged = mergeSales(local, server);

        expect(merged[0].completed_at).toBe(local[0].completed_at);
    });
});

describe('the retention window', () => {
    it('drops sales past the window and keeps the rest', async () => {
        await recordSale(aSale({ id: 1, completed_at: daysAgo(RETENTION_DAYS + 1) }), 7);
        await recordSale(aSale({ id: 2, completed_at: daysAgo(1) }), 7);

        const removed = await pruneOldSales();

        expect(removed).toBe(1);
        expect((await localSales(7)).map((s) => s.server_id)).toEqual([2]);
    });

    it('keeps a sale sitting exactly inside the window', async () => {
        await recordSale(aSale({ id: 1, completed_at: daysAgo(RETENTION_DAYS - 1) }), 7);

        expect(await pruneOldSales()).toBe(0);
    });

    it('prunes every cashier, not only the one signed in', async () => {
        await recordSale(aSale({ id: 1, completed_at: daysAgo(RETENTION_DAYS + 2) }), 7);
        await recordSale(aSale({ id: 2, completed_at: daysAgo(RETENTION_DAYS + 2) }), 8);

        // A device shared by staff who have since left would otherwise keep
        // their rows for ever, because no prune ever runs under their id.
        expect(await pruneOldSales()).toBe(2);
        expect(await db.sales.count()).toBe(0);
    });
});

describe('the receipt kept on the device', () => {
    it('stores the fetched document against its sale', async () => {
        await recordSale(aSale({ id: 31, reference: 'POS-31' }), 7);

        await cacheReceipt(31, '<html>receipt</html>');

        expect(await cachedReceipt(31)).toBe('<html>receipt</html>');
    });

    it('returns nothing for a sale this device never printed', async () => {
        await recordSale(aSale({ id: 32, reference: 'POS-32' }), 7);

        expect(await cachedReceipt(32)).toBeNull();
    });

    it('returns nothing when there is no sale id to look up', async () => {
        expect(await cachedReceipt(null)).toBeNull();
    });

    it('refuses to store an empty document over a good one', async () => {
        await recordSale(aSale({ id: 33 }), 7);
        await cacheReceipt(33, '<html>good</html>');

        await cacheReceipt(33, '');

        expect(await cachedReceipt(33)).toBe('<html>good</html>');
    });

    it('goes when its sale ages out, so receipts cannot outlive the window', async () => {
        await recordSale(aSale({ id: 34, completed_at: daysAgo(RETENTION_DAYS + 1) }), 7);
        await cacheReceipt(34, '<html>old</html>');

        await pruneOldSales();

        expect(await cachedReceipt(34)).toBeNull();
    });

    it('survives a queued sale being reconciled with its server id', async () => {
        await recordSale(aSale({ offline_id: 'off-9' }), 7);
        await markSaleSynced('off-9', { id: 90, reference: 'POS-90' });

        await cacheReceipt(90, '<html>synced</html>');

        expect(await cachedReceipt(90)).toBe('<html>synced</html>');
    });
});
