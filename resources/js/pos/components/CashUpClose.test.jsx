import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { db } from '../lib/db';
import { businessDate, closeShift, openShift, pendingShifts } from '../lib/shift';
import CashUpModal from './CashUpModal';

afterEach(cleanup);

const CASHIER = 7;

beforeEach(async () => {
    await db.sales.clear();
    await db.refunds.clear();
    await db.shifts.clear();
});

const sell = (over = {}) => db.sales.add({
    cashier_id: CASHIER,
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

const aShift = () => ({ id: 1, cashier_id: CASHIER, business_date: businessDate(), opening_float: 20000 });

const show = (props = {}) => render(
    <CashUpModal shift={aShift()} onClose={() => {}} onComplete={() => {}} {...props} />,
);

const type = async (amount) => {
    for (const key of String(amount).split('')) {
        await userEvent.click(screen.getByRole('button', { name: key }));
    }
};

/** Both counts, through the reveal, to the note step. */
const toNote = async (cash, terminal) => {
    await type(cash);
    await userEvent.click(screen.getByRole('button', { name: /next/i }));
    await type(terminal);
    await userEvent.click(screen.getByRole('button', { name: /see how you did/i }));
    await screen.findByRole('button', { name: /continue/i });
    await userEvent.click(screen.getByRole('button', { name: /continue/i }));
};

describe('the cashier speaks, but does not rectify', () => {
    it('offers a note rather than a way to write the difference off', async () => {
        await sell({ total: 80000, amount_tendered: 80000 });
        show();

        await toNote('97000', '0');

        expect(await screen.findByText(/what happened/i)).toBeTruthy();
        expect(screen.getByLabelText(/note for your manager/i)).toBeTruthy();

        // A cashier who could write off their own shortage would make the whole
        // count pointless. There is no such control anywhere in this flow.
        expect(screen.queryByText(/expense/i)).toBeNull();
        expect(screen.queryByText(/rectif/i)).toBeNull();
        expect(screen.queryByText(/wrong tender/i)).toBeNull();
    });

    it('says plainly that the manager decides', async () => {
        await sell({ total: 80000, amount_tendered: 80000 });
        show();

        await toNote('97000', '0');

        expect(await screen.findByText(/your manager decides/i)).toBeTruthy();
    });

    it('will not send an empty explanation for a day that is out', async () => {
        await sell({ total: 80000, amount_tendered: 80000 });
        show();

        await toNote('97000', '0');

        expect(screen.getByRole('button', { name: /send this and finish/i }).disabled).toBe(true);
    });

    it('still lets them finish when they cannot explain it', async () => {
        const onComplete = vi.fn();
        await sell({ total: 80000, amount_tendered: 80000 });
        show({ onComplete });

        await toNote('97000', '0');

        // Refusing to let them go home would only teach them to type anything
        // at all, and an invented explanation is worse than an honest blank.
        await userEvent.click(screen.getByRole('button', { name: /cannot explain it/i }));

        await waitFor(() => expect(onComplete).toHaveBeenCalled());
        expect(onComplete.mock.calls[0][0].notes).toBeNull();
    });

    it('carries the note and both counts out', async () => {
        const onComplete = vi.fn();
        await sell({ total: 80000, amount_tendered: 80000 });
        show({ onComplete });

        await toNote('97000', '2500');
        await userEvent.type(
            screen.getByLabelText(/note for your manager/i),
            'Gave 3000 to the driver',
        );
        await userEvent.click(screen.getByRole('button', { name: /send this and finish/i }));

        await waitFor(() => expect(onComplete).toHaveBeenCalled());
        expect(onComplete.mock.calls[0][0]).toMatchObject({
            countedCash: 97000,
            countedTerminal: 2500,
            notes: 'Gave 3000 to the driver',
        });
        // Frozen with the counts, so a later sale cannot change what they saw.
        expect(onComplete.mock.calls[0][0].expectation.expectedCash).toBe(100000);
    });

    it('asks for nothing when the day balances', async () => {
        const onComplete = vi.fn();
        await sell({ total: 80000, amount_tendered: 80000 });
        show({ onComplete });

        await toNote('100000', '0');

        expect(await screen.findByText(/anything to add/i)).toBeTruthy();
        // One way out, and no pressure to justify a day with nothing wrong.
        expect(screen.queryByRole('button', { name: /cannot explain it/i })).toBeNull();

        await userEvent.click(screen.getByRole('button', { name: /finish cash-up/i }));
        await waitFor(() => expect(onComplete).toHaveBeenCalled());
    });

    it('goes back to the figures', async () => {
        await sell({ total: 80000, amount_tendered: 80000 });
        show();

        await toNote('97000', '0');
        await userEvent.click(screen.getByRole('button', { name: /back to the figures/i }));

        expect(await screen.findByText('Short')).toBeTruthy();
    });
});

describe('finishing the day on the device', () => {
    it('records the counts, the note and the till own working', async () => {
        const shift = await openShift({ cashierId: CASHIER, vendorId: 1, openingFloat: 20000 });

        const closed = await closeShift(shift.id, {
            countedCash: 97000,
            countedTerminal: 0,
            notes: 'Transport',
            expectation: { expectedCash: 100000, expectedTerminal: 0 },
        });

        expect(closed.status).toBe('pending_review');
        expect(closed.local_expected_cash).toBe(100000);
        expect(closed.notes).toBe('Transport');

        // The server's own fields stay empty until the server fills them. One
        // screen must never show the till's guess as a confirmed figure.
        expect(closed.expected_cash ?? null).toBeNull();
        expect(closed.cash_variance ?? null).toBeNull();
    });

    it('queues the close for the server', async () => {
        const shift = await openShift({ cashierId: CASHIER, vendorId: 1, openingFloat: 0 });
        await db.shifts.update(shift.id, { open_synced: 1, server_id: 42 });

        await closeShift(shift.id, { countedCash: 500, countedTerminal: 0 });

        const pending = await pendingShifts();

        expect(pending).toHaveLength(1);
        expect(pending[0].close_key).toMatch(/^cashup-close-/);
    });
});
