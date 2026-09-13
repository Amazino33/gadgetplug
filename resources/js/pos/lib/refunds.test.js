import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { db } from './db';
import { localRefunds, pruneOldSales, recordRefund, RETENTION_DAYS } from './salesHistory';

const CASHIER = 7;
const daysAgo = (n) => new Date(Date.now() - n * 24 * 60 * 60 * 1000).toISOString();

const aRefund = (over = {}) => ({
    id: 11,
    reference: 'RET-ABC',
    original_sale_id: 3,
    refund_amount: 12000,
    refund_method: 'cash',
    ...over,
});

beforeEach(async () => {
    await db.refunds.clear();
    await db.sales.clear();
});

describe('money handed back out of the drawer', () => {
    it('is recorded on the device, not only on the server', async () => {
        // The whole reason this table exists: without a local row, the day's
        // cash figure counts a refund as money still in the drawer and tells
        // the cashier they are short by exactly what they handed back.
        await recordRefund(aRefund(), CASHIER);

        const rows = await localRefunds(CASHIER);

        expect(rows).toHaveLength(1);
        expect(rows[0].refund_amount).toBe(12000);
        expect(rows[0].refund_method).toBe('cash');
    });

    it('keeps the tender, so each leg can be reduced separately', async () => {
        await recordRefund(aRefund({ refund_method: 'card' }), CASHIER);
        await recordRefund(aRefund({ refund_method: 'store_credit' }), CASHIER);

        const methods = (await localRefunds(CASHIER)).map((r) => r.refund_method);

        // Store credit moves no money at all — the drawer figure has to be able
        // to tell it apart from cash actually handed over the counter.
        expect(methods).toContain('card');
        expect(methods).toContain('store_credit');
    });

    it('belongs to whoever paid it out, not whoever made the sale', async () => {
        await recordRefund(aRefund(), CASHIER);
        await recordRefund(aRefund(), 9);

        expect(await localRefunds(CASHIER)).toHaveLength(1);
        expect(await localRefunds(9)).toHaveLength(1);
    });

    it('ignores a refund with no cashier or no money', async () => {
        expect(await recordRefund(aRefund(), null)).toBeNull();
        expect(await recordRefund(aRefund({ refund_amount: 0 }), CASHIER)).toBeNull();
        expect(await recordRefund(aRefund({ refund_amount: -5 }), CASHIER)).toBeNull();

        expect(await db.refunds.count()).toBe(0);
    });

    it('survives a refund the server never answered', async () => {
        // No server id yet. The drawer still lost the notes, so the figure has
        // to know about it.
        await recordRefund(aRefund({ id: null, reference: null }), CASHIER);

        expect((await localRefunds(CASHIER))[0].refund_amount).toBe(12000);
    });

    it('is pruned with the sales it belongs beside', async () => {
        await db.refunds.add({
            cashier_id: CASHIER, refund_amount: 100, refund_method: 'cash',
            created_at: daysAgo(RETENTION_DAYS + 1),
        });
        await recordRefund(aRefund(), CASHIER);

        await pruneOldSales();

        expect(await localRefunds(CASHIER)).toHaveLength(1);
    });
});
