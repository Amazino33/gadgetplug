import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { db } from './db';
import { businessDate } from './shift';
import { computeExpectation, dayRows, describeVariance, provisionalFor, varianceAgainst } from './cashUp';

const CASHIER = 7;

const aSale = (over = {}) => ({
    status: 'completed',
    payment_method: 'cash',
    total: 10000,
    amount_tendered: 10000,
    change_given: 0,
    payments: null,
    synced: 1,
    completed_at: new Date().toISOString(),
    ...over,
});

const aRefund = (over = {}) => ({
    refund_amount: 5000,
    refund_method: 'cash',
    created_at: new Date().toISOString(),
    ...over,
});

const line = (lines, key) => lines.find((l) => l.key === key)?.amount;

beforeEach(async () => {
    await db.sales.clear();
    await db.refunds.clear();
    await db.shifts.clear();
});

// These mirror tests/Feature/CashUp/CashUpExpectationTest.php case for case.
// Where the till and the server must agree, they have to agree about the same
// examples, or the cashier meets one number on the screen and a different one
// in the office.

describe('the cash leg', () => {
    it('is the float plus the day takings', () => {
        const result = computeExpectation({
            openingFloat: 20000,
            sales: [aSale({ total: 30000, amount_tendered: 30000 }), aSale({ total: 50000, amount_tendered: 50000 })],
        });

        expect(result.expectedCash).toBe(100000);
    });

    it('shows the float as a line of its own', () => {
        const result = computeExpectation({ openingFloat: 20000, sales: [aSale({ total: 80000, amount_tendered: 80000 })] });

        // "Why is the drawer more than my sales?" is the first question asked.
        expect(line(result.cashLines, 'opening_float')).toBe(20000);
        expect(line(result.cashLines, 'cash_sales')).toBe(80000);
    });

    it('adds up to exactly what the cashier is measured against', () => {
        const result = computeExpectation({
            openingFloat: 20000,
            sales: [aSale({ total: 80000, amount_tendered: 90000, change_given: 10000 })],
            refunds: [aRefund({ refund_amount: 2000 })],
        });

        const total = result.cashLines.reduce((sum, l) => sum + l.amount, 0);

        expect(Math.round(total * 100) / 100).toBe(result.expectedCash);
    });

    it('does not count change as money in the drawer', () => {
        const result = computeExpectation({
            sales: [aSale({ total: 9500, amount_tendered: 10000, change_given: 500 })],
        });

        expect(result.expectedCash).toBe(9500);
    });

    it('leaves a voided sale out', () => {
        const result = computeExpectation({
            sales: [aSale({ total: 30000, amount_tendered: 30000 }), aSale({ status: 'voided', total: 50000, amount_tendered: 50000 })],
        });

        expect(result.expectedCash).toBe(30000);
        expect(result.context.sales_count).toBe(1);
    });

    it('takes cash refunds off it', () => {
        const result = computeExpectation({
            sales: [aSale({ total: 50000, amount_tendered: 50000 })],
            refunds: [aRefund({ refund_amount: 12000, refund_method: 'cash' })],
        });

        // The gap this fixes: without the local refund row the till would tell
        // the cashier they were short by exactly what they handed back.
        expect(result.expectedCash).toBe(38000);
        expect(line(result.cashLines, 'cash_refunds')).toBe(-12000);
    });

    it('ignores a store-credit refund, which moves no money', () => {
        const result = computeExpectation({
            sales: [aSale({ total: 50000, amount_tendered: 50000 })],
            refunds: [aRefund({ refund_amount: 12000, refund_method: 'store_credit' })],
        });

        expect(result.expectedCash).toBe(50000);
        expect(result.context.store_credit_refunds).toBe(12000);
    });
});

describe('the terminal leg', () => {
    it('takes card and transfer together but shows them apart', () => {
        const result = computeExpectation({
            sales: [
                aSale({ payment_method: 'card', total: 40000, amount_tendered: 0 }),
                aSale({ payment_method: 'bank_transfer', total: 25000, amount_tendered: 0 }),
            ],
        });

        expect(result.expectedTerminal).toBe(65000);
        expect(line(result.terminalLines, 'card_sales')).toBe(40000);
        expect(line(result.terminalLines, 'transfer_sales')).toBe(25000);
        // Terminal money never touches the drawer.
        expect(result.expectedCash).toBe(0);
    });

    it('takes terminal refunds off it', () => {
        const result = computeExpectation({
            sales: [aSale({ payment_method: 'card', total: 40000, amount_tendered: 0 })],
            refunds: [aRefund({ refund_amount: 15000, refund_method: 'card' })],
        });

        expect(result.expectedTerminal).toBe(25000);
    });
});

describe('mixed payments', () => {
    const split = (over = {}) => aSale({
        payment_method: 'split',
        total: 100000,
        amount_tendered: 100000,
        payments: [{ method: 'cash', amount: 30000 }, { method: 'card', amount: 70000 }],
        ...over,
    });

    it('lands on both legs', () => {
        const result = computeExpectation({ sales: [split()] });

        expect(result.expectedCash).toBe(30000);
        expect(result.expectedTerminal).toBe(70000);
    });

    it('takes the change out of the drawer once', () => {
        const result = computeExpectation({
            sales: [split({
                total: 95000, amount_tendered: 100000, change_given: 5000,
                payments: [{ method: 'cash', amount: 35000 }, { method: 'card', amount: 60000 }],
            })],
        });

        expect(result.expectedCash).toBe(30000);
    });
});

describe('credit', () => {
    it('puts nothing in either leg but is reported', () => {
        const result = computeExpectation({
            sales: [
                aSale({ total: 60000, amount_tendered: 60000 }),
                aSale({ payment_method: 'debt', total: 40000, amount_tendered: 0 }),
            ],
        });

        // The usual complaint: "I sold 100,000 and the drawer has 60,000."
        expect(result.expectedCash).toBe(60000);
        expect(result.expectedTerminal).toBe(0);
        expect(result.context.debt_rung).toBe(40000);
        expect(result.context.gross_sales).toBe(100000);
    });

    it('counts the credit slice of a mixed sale', () => {
        const result = computeExpectation({
            sales: [aSale({
                payment_method: 'split', total: 50000, amount_tendered: 20000,
                payments: [{ method: 'cash', amount: 20000 }, { method: 'debt', amount: 30000 }],
            })],
        });

        expect(result.expectedCash).toBe(20000);
        expect(result.context.debt_rung).toBe(30000);
    });
});

describe('what the till admits it does not know', () => {
    it('counts sales it has not managed to upload', () => {
        const result = computeExpectation({
            sales: [aSale({ synced: 1 }), aSale({ synced: 0 }), aSale({ synced: 0 })],
        });

        // The server cannot have counted these, so its figure will differ — a
        // sync problem, not a missing-money problem, and the cashier has to be
        // told which they are looking at.
        expect(result.context.unsynced_sales).toBe(2);
    });
});

describe('variance', () => {
    it('is counted minus expected', () => {
        expect(varianceAgainst(95000, 100000)).toBe(-5000);
        expect(varianceAgainst(100000, 100000)).toBe(0);
        expect(varianceAgainst(103000, 100000)).toBe(3000);
    });

    it('is read out in words, not a sign a tired cashier can misread', () => {
        expect(describeVariance(-5000)).toMatchObject({ tone: 'short', label: 'Short', amount: 5000 });
        expect(describeVariance(3000)).toMatchObject({ tone: 'over', label: 'Over', amount: 3000 });
        expect(describeVariance(0)).toMatchObject({ tone: 'balanced' });
        expect(describeVariance(0.004).tone).toBe('balanced');
    });
});

describe('reading the day off the device', () => {
    it('takes only this cashier, on this trading day', async () => {
        const today = businessDate();

        await db.sales.bulkAdd([
            { cashier_id: CASHIER, ...aSale({ total: 30000, amount_tendered: 30000 }) },
            { cashier_id: 9, ...aSale({ total: 50000, amount_tendered: 50000 }) },
            { cashier_id: CASHIER, ...aSale({ total: 70000, amount_tendered: 70000, completed_at: '2026-01-01T09:00:00Z' }) },
        ]);

        const { sales } = await dayRows(CASHIER, today);

        expect(sales).toHaveLength(1);
        expect(sales[0].total).toBe(30000);
    });

    it('dates a refund by when it was paid out, not when the sale was made', async () => {
        const today = businessDate();

        await db.refunds.bulkAdd([
            { cashier_id: CASHIER, ...aRefund() },
            { cashier_id: CASHIER, ...aRefund({ created_at: '2026-01-01T09:00:00Z' }) },
        ]);

        const { refunds } = await dayRows(CASHIER, today);

        // Money handed back today came out of today's drawer, whatever day the
        // sale was made.
        expect(refunds).toHaveLength(1);
    });

    it('works a whole shift out from what the device holds', async () => {
        await db.sales.add({ cashier_id: CASHIER, ...aSale({ total: 40000, amount_tendered: 40000 }) });
        await db.refunds.add({ cashier_id: CASHIER, ...aRefund({ refund_amount: 5000 }) });

        const shift = { cashier_id: CASHIER, business_date: businessDate(), opening_float: 15000 };
        const result = await provisionalFor(shift);

        expect(result.expectedCash).toBe(50000);
    });
});
