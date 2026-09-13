import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { db } from './db';
import {
    businessDate,
    closeShift,
    markCloseSynced,
    markOpenSynced,
    openShift,
    openShiftFor,
    pendingShifts,
    shiftFor,
    unclosedShiftsFor,
} from './shift';

const CASHIER = 7;

beforeEach(async () => {
    await db.shifts.clear();
});

afterEach(() => {
    vi.useRealTimers();
});

const start = (over = {}) => openShift({
    cashierId: CASHIER,
    vendorId: 1,
    openingFloat: 20000,
    ...over,
});

describe('the trading day', () => {
    it('runs on the shop clock, not the device one', () => {
        // 23:30 UTC is already half past midnight in Lagos, so the shop is on
        // the next day. A till left on UTC would open a second shift for a day
        // the server considers already started.
        expect(businessDate(new Date('2026-09-11T23:30:00Z'))).toBe('2026-09-12');
        expect(businessDate(new Date('2026-09-11T09:00:00Z'))).toBe('2026-09-11');
    });

    it('formats as the server expects', () => {
        expect(businessDate(new Date('2026-01-05T09:00:00Z'))).toBe('2026-01-05');
    });
});

describe('opening a shift', () => {
    it('records the counted float', async () => {
        const shift = await start({ openingFloat: 15500.5 });

        expect(shift.opening_float).toBe(15500.5);
        expect(shift.status).toBe('open');
        expect(shift.business_date).toBe(businessDate());
    });

    it('refuses a float that is not a real amount', async () => {
        await expect(start({ openingFloat: -5 })).rejects.toThrow('real amount');
        await expect(start({ openingFloat: 'plenty' })).rejects.toThrow('real amount');

        expect(await db.shifts.count()).toBe(0);
    });

    it('accepts an empty drawer', async () => {
        // Zero is a real answer and a meaningful one. Refusing it would push a
        // cashier into inventing a number.
        const shift = await start({ openingFloat: 0 });

        expect(shift.opening_float).toBe(0);
    });

    it('resumes the day rather than starting a second one', async () => {
        const first = await start();
        const second = await start({ openingFloat: 999 });

        expect(second.id).toBe(first.id);
        expect(second.opening_float).toBe(20000);
        expect(await db.shifts.count()).toBe(1);
    });

    it('gives the open its own idempotency key', async () => {
        const shift = await start();

        expect(shift.open_key).toMatch(/^cashup-open-/);
        expect(shift.open_synced).toBe(0);
    });

    it('keeps one cashier day apart from another', async () => {
        await start();
        await openShift({ cashierId: 9, vendorId: 1, openingFloat: 5000 });

        expect((await shiftFor(CASHIER)).opening_float).toBe(20000);
        expect((await shiftFor(9)).opening_float).toBe(5000);
    });

    it('reveals nothing about expected figures on open', async () => {
        const shift = await start();

        // The cashier counts blind at close. Anything the server has not said
        // yet must be absent here too, or the till could show it.
        expect(shift.expected_cash).toBeNull();
        expect(shift.expected_terminal).toBeNull();
        expect(shift.cash_variance).toBeNull();
    });
});

describe('finding the day in progress', () => {
    it('finds an open shift', async () => {
        const shift = await start();

        expect((await openShiftFor(CASHIER)).id).toBe(shift.id);
    });

    it('finds nothing once the day is closed', async () => {
        const shift = await start();
        await closeShift(shift.id, { countedCash: 1000, countedTerminal: 0 });

        expect(await openShiftFor(CASHIER)).toBeNull();
    });

    it('finds nothing for a cashier who has not started', async () => {
        expect(await openShiftFor(CASHIER)).toBeNull();
        expect(await openShiftFor(null)).toBeNull();
    });
});

describe('days left open', () => {
    it('offers back a day that was never closed', async () => {
        await db.shifts.add({
            cashier_id: CASHIER, business_date: '2026-01-01', status: 'open',
            opening_float: 100, open_synced: 1, close_synced: 0,
        });

        const stale = await unclosedShiftsFor(CASHIER);

        expect(stale).toHaveLength(1);
        expect(stale[0].business_date).toBe('2026-01-01');
    });

    it('does not count today as left behind', async () => {
        await start();

        expect(await unclosedShiftsFor(CASHIER)).toHaveLength(0);
    });

    it('does not count a day that was closed', async () => {
        await db.shifts.add({
            cashier_id: CASHIER, business_date: '2026-01-01', status: 'pending_review',
            opening_float: 100, open_synced: 1, close_synced: 1,
        });

        expect(await unclosedShiftsFor(CASHIER)).toHaveLength(0);
    });
});

describe('closing a shift', () => {
    it('records both counts and leaves it awaiting review', async () => {
        const shift = await start();
        const closed = await closeShift(shift.id, {
            countedCash: 97000, countedTerminal: 48000, notes: 'Gave 3,000 for transport',
        });

        expect(closed.counted_cash).toBe(97000);
        expect(closed.counted_terminal).toBe(48000);
        expect(closed.notes).toBe('Gave 3,000 for transport');
        expect(closed.status).toBe('pending_review');
        expect(closed.close_key).toMatch(/^cashup-close-/);
    });

    it('rounds to the kobo', async () => {
        const shift = await start();
        const closed = await closeShift(shift.id, { countedCash: 100.005, countedTerminal: 0 });

        expect(closed.counted_cash).toBe(100.01);
    });

    it('will not close a day twice', async () => {
        const shift = await start();
        await closeShift(shift.id, { countedCash: 5000, countedTerminal: 0 });
        const again = await closeShift(shift.id, { countedCash: 99999, countedTerminal: 0 });

        // The first count stands. A cashier who reopens and recounts after
        // seeing a shortage is exactly what blind entry exists to prevent.
        expect(Number(again.counted_cash)).toBe(5000);
    });
});

describe('what still has to reach the server', () => {
    it('lists a shift whose open has not gone up', async () => {
        await start();

        expect(await pendingShifts()).toHaveLength(1);
    });

    it('drops it once the open is acknowledged', async () => {
        const shift = await start();
        await markOpenSynced(shift.id, { id: 51 });

        expect(await pendingShifts()).toHaveLength(0);
        expect((await db.shifts.get(shift.id)).server_id).toBe(51);
    });

    it('lists it again once it is closed', async () => {
        const shift = await start();
        await markOpenSynced(shift.id, { id: 51 });
        await closeShift(shift.id, { countedCash: 100, countedTerminal: 0 });

        expect(await pendingShifts()).toHaveLength(1);

        await markCloseSynced(shift.id);

        expect(await pendingShifts()).toHaveLength(0);
    });
});
