import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { db } from './db';
import {
    applyServerShift,
    backoffFor,
    clearSyncFailure,
    closeShift,
    markCloseSynced,
    markOpenSynced,
    noteSyncFailure,
    openShift,
    pendingShifts,
    rejectedShiftFor,
} from './shift';

const CASHIER = 7;

beforeEach(async () => {
    await db.shifts.clear();
});

const start = () => openShift({ cashierId: CASHIER, vendorId: 1, openingFloat: 20000 });

describe('backing off a failing send', () => {
    it('widens the wait, then holds', () => {
        expect(backoffFor(0)).toBe(30_000);
        expect(backoffFor(1)).toBe(60_000);
        expect(backoffFor(2)).toBe(120_000);

        // A shop with no signal all afternoon should not be asked about it
        // every thirty seconds for four hours.
        expect(backoffFor(20)).toBe(1_800_000);
    });

    it('holds a shift back until its next attempt is due', async () => {
        const shift = await start();
        const now = 1_000_000;

        await noteSyncFailure(shift.id, now);

        expect(await pendingShifts(now)).toHaveLength(0);
        expect(await pendingShifts(now + 29_000)).toHaveLength(0);
        expect(await pendingShifts(now + 31_000)).toHaveLength(1);
    });

    it('waits longer each time it fails', async () => {
        const shift = await start();
        const now = 1_000_000;

        await noteSyncFailure(shift.id, now);
        await noteSyncFailure(shift.id, now);

        const row = await db.shifts.get(shift.id);

        expect(row.sync_attempts).toBe(2);
        expect(row.next_attempt_at).toBe(now + 60_000);
    });

    it('starts counting again once something gets through', async () => {
        const shift = await start();
        await noteSyncFailure(shift.id, 1_000_000);
        await clearSyncFailure(shift.id);

        const row = await db.shifts.get(shift.id);

        expect(row.sync_attempts).toBe(0);
        expect(row.next_attempt_at).toBeNull();
        expect(await pendingShifts(1_000_001)).toHaveLength(1);
    });
});

describe('a refusal the till cannot fix', () => {
    it('stops being retried, and is kept where it can be seen', async () => {
        const shift = await start();

        await db.shifts.update(shift.id, {
            sync_status: 'rejected',
            sync_message: 'This till is not assigned to a branch.',
        });

        // Retrying for ever would only bury it. The cashier is the only one who
        // can go and get it sorted, so they are the one who is told.
        expect(await pendingShifts()).toHaveLength(0);

        const rejected = await rejectedShiftFor(CASHIER);
        expect(rejected.sync_message).toContain('not assigned to a branch');
    });
});

describe('the order things are sent in', () => {
    it('does not offer a close before its own open has landed', async () => {
        const shift = await start();
        await closeShift(shift.id, { countedCash: 500, countedTerminal: 0 });

        const [pending] = await pendingShifts();

        // The server has nothing to attach a count to until the day exists.
        expect(pending.open_synced).not.toBe(1);
        expect(pending.server_id ?? null).toBeNull();
    });

    it('is finished with a shift once both halves are acknowledged', async () => {
        const shift = await start();
        await markOpenSynced(shift.id, { id: 51 });
        await closeShift(shift.id, { countedCash: 500, countedTerminal: 0 });
        await markCloseSynced(shift.id);

        expect(await pendingShifts()).toHaveLength(0);
    });
});

describe('the server having the last word', () => {
    it('replaces the till own figures with the confirmed ones', async () => {
        const shift = await start();

        await closeShift(shift.id, {
            countedCash: 97000,
            countedTerminal: 0,
            expectation: { expectedCash: 100000, expectedTerminal: 0 },
        });

        // The till thought it was 3,000 short. The server saw a sale this
        // device never did, so the real figure is 1,000.
        await applyServerShift(shift.id, {
            id: 51,
            expected_cash: 98000,
            expected_terminal: 0,
            cash_variance: -1000,
            terminal_variance: 0,
        });
        await markCloseSynced(shift.id);

        const row = await db.shifts.get(shift.id);

        expect(row.cash_variance).toBe(-1000);
        expect(row.expected_cash).toBe(98000);
        // And what the cashier was shown at the time is still on the record.
        expect(row.local_expected_cash).toBe(100000);
    });

    it('leaves the counts alone, because they are the evidence', async () => {
        const shift = await start();
        await closeShift(shift.id, { countedCash: 97000, countedTerminal: 250 });

        await applyServerShift(shift.id, {
            id: 51, expected_cash: 98000, cash_variance: -1000,
        });

        const row = await db.shifts.get(shift.id);

        expect(row.counted_cash).toBe(97000);
        expect(row.counted_terminal).toBe(250);
    });
});

describe('finishing the day with no signal at all', () => {
    it('completes locally and waits, rather than refusing the cashier', async () => {
        const shift = await start();

        const closed = await closeShift(shift.id, {
            countedCash: 5000,
            countedTerminal: 0,
            notes: 'No network all day',
            expectation: { expectedCash: 5000, expectedTerminal: 0 },
        });

        // The day is finished as far as the cashier is concerned.
        expect(closed.status).toBe('pending_review');
        expect(closed.local_expected_cash).toBe(5000);

        // And the server still has both halves owing to it.
        const [pending] = await pendingShifts();
        expect(pending.open_synced).not.toBe(1);
        expect(pending.close_synced).not.toBe(1);
        expect(pending.open_key).toMatch(/^cashup-open-/);
        expect(pending.close_key).toMatch(/^cashup-close-/);
    });

    it('survives the app being reloaded mid-shift', async () => {
        const shift = await start();

        // A reload is simply a new read of the same table — nothing about the
        // day lives in component state.
        const reloaded = await db.shifts.get(shift.id);

        expect(reloaded.opening_float).toBe(20000);
        expect(reloaded.status).toBe('open');
        expect(reloaded.open_key).toBe(shift.open_key);
    });

    it('keeps one key across every retry, so a replay is recognised', async () => {
        const shift = await start();

        await noteSyncFailure(shift.id);
        await noteSyncFailure(shift.id);

        const row = await db.shifts.get(shift.id);

        // A new key per attempt would make every retry look like a new day.
        expect(row.open_key).toBe(shift.open_key);
    });
});
