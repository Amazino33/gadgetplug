import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { db } from '../lib/db';
import { applyServerShift, closeShift, markCloseSynced, openShift } from '../lib/shift';
import { useLiveShift } from './useLiveShift';
import ShiftClosed from '../pages/ShiftClosed';

afterEach(cleanup);

const CASHIER = 7;

beforeEach(async () => {
    await db.shifts.clear();
});

/** The shell, near enough: whatever the live hook says the day is. */
function Shell({ cashierId }) {
    const { shift, loading } = useLiveShift(cashierId);

    if (loading) return <p>Opening the till…</p>;
    if (!shift) return <p>No shift</p>;

    return <ShiftClosed shift={shift} user={{ name: 'Nkechi' }} onLogout={() => {}} />;
}

describe('the day on screen keeps up with the day in the database', () => {
    it('says there is no shift when there is none', async () => {
        render(<Shell cashierId={CASHIER} />);

        expect(await screen.findByText('No shift')).toBeTruthy();
    });

    it('picks up a shift opened after it rendered', async () => {
        render(<Shell cashierId={CASHIER} />);
        await screen.findByText('No shift');

        const shift = await openShift({ cashierId: CASHIER, vendorId: 1, openingFloat: 20000 });
        await closeShift(shift.id, {
            countedCash: 97000,
            countedTerminal: 0,
            expectation: { expectedCash: 100000, expectedTerminal: 0 },
        });

        expect(await screen.findByText(/cash-up submitted/i)).toBeTruthy();
    });

    it('swaps the provisional figure for the confirmed one when sync lands', async () => {
        const shift = await openShift({ cashierId: CASHIER, vendorId: 1, openingFloat: 20000 });
        await closeShift(shift.id, {
            countedCash: 97000,
            countedTerminal: 0,
            expectation: { expectedCash: 100000, expectedTerminal: 0 },
        });

        render(<Shell cashierId={CASHIER} />);

        // The till's own arithmetic first: 3,000 short, and labelled as not final.
        expect(await screen.findByText('₦3,000.00')).toBeTruthy();
        expect(screen.getByText(/still to reach the server/i)).toBeTruthy();

        // The server saw a sale this device never did.
        await applyServerShift(shift.id, {
            id: 51, expected_cash: 98000, expected_terminal: 0,
            cash_variance: -1000, terminal_variance: 0,
        });
        await markCloseSynced(shift.id);

        // No reload, and nobody at a counter ever reloads an app.
        await waitFor(() => expect(screen.getByText(/confirmed figures from the server/i)).toBeTruthy());
        expect(screen.getByText('₦1,000.00')).toBeTruthy();
    });

    it('shows a refusal rather than hiding it behind a retry', async () => {
        const shift = await openShift({ cashierId: CASHIER, vendorId: 1, openingFloat: 0 });
        await closeShift(shift.id, { countedCash: 0, countedTerminal: 0 });

        render(<Shell cashierId={CASHIER} />);
        await screen.findByText(/cash-up submitted/i);

        await db.shifts.update(shift.id, {
            sync_status: 'rejected',
            sync_message: 'This till is not assigned to a branch.',
        });

        await waitFor(() => expect(screen.getByText(/could not be sent/i)).toBeTruthy());
        expect(screen.getByText(/not assigned to a branch/i)).toBeTruthy();
    });
});
