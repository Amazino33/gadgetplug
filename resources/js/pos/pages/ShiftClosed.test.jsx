import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ShiftClosed from './ShiftClosed';

afterEach(cleanup);

const user = { id: 7, name: 'Nkechi Obi' };

const closedShift = (over = {}) => ({
    id: 1,
    business_date: '2026-09-12',
    counted_cash: 97000,
    counted_terminal: 0,
    local_expected_cash: 100000,
    local_expected_terminal: 0,
    expected_cash: null,
    cash_variance: null,
    terminal_variance: null,
    notes: null,
    close_synced: 0,
    status: 'pending_review',
    ...over,
});

const show = (over = {}) => render(
    <ShiftClosed shift={closedShift(over)} user={user} onLogout={() => {}} />,
);

describe('after the day is finished', () => {
    it('tells the cashier where they stand rather than leaving them wondering', () => {
        show();

        expect(screen.getByText(/cash-up submitted/i)).toBeTruthy();
        expect(screen.getByText('₦97,000.00')).toBeTruthy();
        expect(screen.getByText('Short')).toBeTruthy();
        expect(screen.getByText('₦3,000.00')).toBeTruthy();
    });

    it('says the figure is not the final one until the server answers', () => {
        show();

        expect(screen.getByText(/still to reach the server/i)).toBeTruthy();
    });

    it('shows the server figure once it has arrived, and says so', () => {
        show({
            close_synced: 1,
            expected_cash: 98000,
            cash_variance: -1000,
            terminal_variance: 0,
        });

        // The server saw sales this till never did, so its answer replaces the
        // provisional one outright rather than sitting beside it.
        expect(screen.getByText(/confirmed figures from the server/i)).toBeTruthy();
        expect(screen.getByText('₦1,000.00')).toBeTruthy();
    });

    it('shows the cashier their own note back', () => {
        show({ notes: 'Gave 3,000 to the driver' });

        expect(screen.getByText(/your note/i)).toBeTruthy();
        expect(screen.getByText('Gave 3,000 to the driver')).toBeTruthy();
    });

    it('offers no way back to selling', () => {
        show();

        // One open and one close per day is what makes the count mean anything;
        // a till that kept selling would have counted a drawer still moving.
        expect(screen.queryByText(/payment/i)).toBeNull();
        expect(screen.queryByText(/new sale/i)).toBeNull();
        expect(screen.getByRole('button', { name: /sign out/i })).toBeTruthy();
    });

    it('signs out', async () => {
        const onLogout = vi.fn();
        render(<ShiftClosed shift={closedShift()} user={user} onLogout={onLogout} />);

        await userEvent.click(screen.getByRole('button', { name: /sign out/i }));

        expect(onLogout).toHaveBeenCalled();
    });

    it('says balanced when nothing is missing', () => {
        show({ counted_cash: 100000, local_expected_cash: 100000 });

        expect(screen.getAllByText('Balanced')).toHaveLength(2);
    });
});
