import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { db } from './db';
import {
    cachePickings,
    cachedPickings,
    markPickingPaymentSynced,
    pendingPickingPayments,
    prunePickingPayments,
    queuedAmountByItem,
    queuePickingPayment,
} from './pickings';

const daysAgo = (n) => new Date(Date.now() - n * 24 * 60 * 60 * 1000).toISOString();

beforeEach(async () => {
    await db.pickingPayments.clear();
    await db.pickingCache.clear();
});

describe('what the till remembers', () => {
    it('keeps the last list the server gave, so an offline till can still work', async () => {
        await cachePickings(1, { store_id: 3, pickers: [{ id: 7, name: 'Musa Bala', lines: [] }] });

        const cache = await cachedPickings(1);

        expect(cache.store_id).toBe(3);
        expect(cache.pickers[0].name).toBe('Musa Bala');
    });

    it('returns nothing for a till that has never been online', async () => {
        expect(await cachedPickings(1)).toBeNull();
    });

    it('replaces the list rather than piling copies up', async () => {
        await cachePickings(1, { pickers: [{ id: 1 }] });
        await cachePickings(1, { pickers: [{ id: 2 }] });

        expect(await db.pickingCache.count()).toBe(1);
        expect((await cachedPickings(1)).pickers[0].id).toBe(2);
    });
});

describe('money taken at the counter', () => {
    it('is queued with what the cashier did, and nothing about what it settles', async () => {
        await queuePickingPayment({ vendorId: 1, pickerId: 7, amount: 15000, itemIds: [10, 11] });

        const [payment] = await pendingPickingPayments();

        expect(payment.amount).toBe(15000);
        expect(payment.item_ids).toEqual([10, 11]);
        expect(payment.synced).toBe(0);
        // The till never decides units — that is the server's job.
        expect(payment.settled_units).toBeUndefined();
    });

    it('gives every payment its own id, so a retry cannot be mistaken for a new one', async () => {
        const first = await queuePickingPayment({ vendorId: 1, pickerId: 7, amount: 1000, itemIds: [1] });
        const second = await queuePickingPayment({ vendorId: 1, pickerId: 7, amount: 1000, itemIds: [1] });

        expect(first).not.toBe(second);
    });

    it('stops being pending once it lands, and keeps what the server said', async () => {
        const id = await queuePickingPayment({ vendorId: 1, pickerId: 7, amount: 2000, itemIds: [1] });

        await markPickingPaymentSynced(id, { settled_units: 2, change: 0, reference: 'PICK-ABC' });

        expect(await pendingPickingPayments()).toHaveLength(0);

        const row = await db.pickingPayments.where('offline_id').equals(id).first();

        expect(row.settled_units).toBe(2);
        expect(row.server_reference).toBe('PICK-ABC');
    });
});

describe('what is still waiting to go up', () => {
    it('totals queued money against each line it was ticked for', async () => {
        await queuePickingPayment({ vendorId: 1, pickerId: 7, amount: 5000, itemIds: [10, 11] });
        await queuePickingPayment({ vendorId: 1, pickerId: 7, amount: 3000, itemIds: [10] });

        expect(await queuedAmountByItem()).toEqual({ 10: 8000, 11: 5000 });
    });

    it('forgets a payment once it has gone up', async () => {
        const id = await queuePickingPayment({ vendorId: 1, pickerId: 7, amount: 5000, itemIds: [10] });

        await markPickingPaymentSynced(id);

        expect(await queuedAmountByItem()).toEqual({});
    });
});

describe('the queue does not grow for ever', () => {
    it('drops synced payments past the week, and keeps the rest', async () => {
        const old = await queuePickingPayment({ vendorId: 1, pickerId: 7, amount: 1000, itemIds: [1] });
        const recent = await queuePickingPayment({ vendorId: 1, pickerId: 7, amount: 1000, itemIds: [1] });

        await db.pickingPayments.where('offline_id').equals(old).modify({ created_at: daysAgo(9) });
        await markPickingPaymentSynced(old);
        await markPickingPaymentSynced(recent);

        expect(await prunePickingPayments()).toBe(1);
        expect(await db.pickingPayments.count()).toBe(1);
    });

    it('never drops one that has not gone up, however old', async () => {
        const id = await queuePickingPayment({ vendorId: 1, pickerId: 7, amount: 1000, itemIds: [1] });

        await db.pickingPayments.where('offline_id').equals(id).modify({ created_at: daysAgo(30) });

        // Money taken and not yet recorded anywhere else must never be binned
        // for being stale — it is the only copy.
        expect(await prunePickingPayments()).toBe(0);
        expect(await pendingPickingPayments()).toHaveLength(1);
    });
});
