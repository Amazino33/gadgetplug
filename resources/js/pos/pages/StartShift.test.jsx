import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { db } from '../lib/db';
import { businessDate } from '../lib/shift';
import StartShift from './StartShift';

vi.mock('../lib/api', () => ({
    default: { post: vi.fn().mockResolvedValue({ data: { id: 1 } }) },
}));

// Auto-cleanup is not on (vitest globals are off), so each render has to be
// torn down or the next test finds two of every button.
afterEach(cleanup);

const user = { id: 7, name: 'Nkechi Obi' };

beforeEach(async () => {
    await db.shifts.clear();
    localStorage.clear();
    vi.clearAllMocks();
});

const show = (props = {}) => render(
    <StartShift user={user} vendorId={1} onStarted={() => {}} onLogout={() => {}} {...props} />,
);

/** Tap through the greeting to the float keypad. */
const toFloat = async () => {
    await userEvent.click(await screen.findByRole('button', { name: /start your shift/i }));
};

describe('greeting the cashier', () => {
    it('greets them by their first name', async () => {
        show();

        expect(await screen.findByText('Nkechi')).toBeTruthy();
        expect(screen.getByText(/good (morning|afternoon|evening)/i)).toBeTruthy();
    });

    it('says nothing about days left open when there are none', async () => {
        show();

        await screen.findByText('Nkechi');
        expect(screen.queryByText(/still open/i)).toBeNull();
    });

    it('surfaces a day that was never closed', async () => {
        await db.shifts.add({
            cashier_id: 7, business_date: '2026-01-01', status: 'open',
            opening_float: 100, open_synced: 1, close_synced: 0,
        });

        show();

        // A forgotten day would otherwise hang open for ever, its float
        // unaccounted for, while the cashier simply starts another.
        expect(await screen.findByText(/a day is still open/i)).toBeTruthy();
        expect(screen.getByText(/2026-01-01/)).toBeTruthy();
    });
});

describe('counting the float in', () => {
    it('asks how much is in the drawer', async () => {
        show();
        await toFloat();

        expect(screen.getByText(/how much is in your drawer/i)).toBeTruthy();
    });

    it('will not open the till before an amount is entered', async () => {
        show();
        await toFloat();

        // The float is the whole point: a closing count measured against an
        // unknown opening proves nothing.
        expect(screen.getByRole('button', { name: /open the till/i }).disabled).toBe(true);
    });

    it('builds the amount from the keypad', async () => {
        show();
        await toFloat();

        for (const key of ['2', '0', '0', '0', '0']) {
            await userEvent.click(screen.getByRole('button', { name: key }));
        }

        expect(screen.getByText(/20,000/)).toBeTruthy();
    });

    it('deletes the last digit', async () => {
        show();
        await toFloat();

        await userEvent.click(screen.getByRole('button', { name: '5' }));
        await userEvent.click(screen.getByRole('button', { name: '0' }));
        await userEvent.click(screen.getByRole('button', { name: 'Delete' }));

        // The formatted display, not the keypad button that also reads "5".
        expect(screen.getByText('₦5.00')).toBeTruthy();
    });

    it('opens the day and hands the shift back', async () => {
        const onStarted = vi.fn();
        show({ onStarted });
        await toFloat();

        for (const key of ['5', '0', '0', '0']) {
            await userEvent.click(screen.getByRole('button', { name: key }));
        }

        await userEvent.click(screen.getByRole('button', { name: /open the till/i }));

        await waitFor(() => expect(onStarted).toHaveBeenCalled());

        const shift = onStarted.mock.calls[0][0];
        expect(shift.opening_float).toBe(5000);
        expect(shift.status).toBe('open');
        expect(shift.business_date).toBe(businessDate());

        // And it is on the device, so a reload returns to the till rather than
        // asking for a float that was already counted.
        expect(await db.shifts.count()).toBe(1);
    });

    it('accepts an empty drawer as a real answer', async () => {
        const onStarted = vi.fn();
        show({ onStarted });
        await toFloat();

        await userEvent.click(screen.getByRole('button', { name: '0' }));
        await userEvent.click(screen.getByRole('button', { name: /open the till/i }));

        await waitFor(() => expect(onStarted).toHaveBeenCalled());
        expect(onStarted.mock.calls[0][0].opening_float).toBe(0);
    });

    it('starts the day even with no signal', async () => {
        const api = (await import('../lib/api')).default;
        api.post.mockRejectedValueOnce(new Error('offline'));

        const onStarted = vi.fn();
        show({ onStarted });
        await toFloat();

        await userEvent.click(screen.getByRole('button', { name: '1' }));
        await userEvent.click(screen.getByRole('button', { name: /open the till/i }));

        // Nothing here waits on the network. A till with no signal still opens.
        await waitFor(() => expect(onStarted).toHaveBeenCalled());
        expect(await db.shifts.count()).toBe(1);
    });

    it('goes back to the greeting', async () => {
        show();
        await toFloat();

        await userEvent.click(screen.getByRole('button', { name: /^back$/i }));

        expect(await screen.findByText('Nkechi')).toBeTruthy();
    });
});
