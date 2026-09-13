import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ExpenseModal from './ExpenseModal';

vi.mock('../lib/api', () => ({
    default: { get: vi.fn(), post: vi.fn() },
}));

afterEach(cleanup);

let api;

beforeEach(async () => {
    api = (await import('../lib/api')).default;
    vi.clearAllMocks();
    api.get.mockResolvedValue({
        data: {
            categories: { logistics_other: 'Transport / delivery', other: 'Something else' },
            expenses: [],
            total: 0,
        },
    });
    api.post.mockResolvedValue({
        data: { expense: { id: 1, category: 'logistics_other', amount: 3000, description: 'Driver' } },
    });
});

const show = (props = {}) => render(
    <ExpenseModal vendorId={1} onClose={() => {}} {...props} />,
);

const tap = async (amount) => {
    for (const key of String(amount).split('')) {
        await userEvent.click(screen.getByRole('button', { name: key }));
    }
};

describe('recording money out of the drawer', () => {
    it('opens on one tap and asks only what it needs', async () => {
        show();

        // A cashier with a customer waiting will not fill in a form, and a
        // control nobody uses protects nothing.
        expect(await screen.findByText(/money out of the drawer/i)).toBeTruthy();
        expect(screen.getByText('Transport / delivery')).toBeTruthy();
        expect(screen.getByLabelText(/what it was for/i)).toBeTruthy();
    });

    it('will not record nothing', async () => {
        show();
        await screen.findByText(/money out of the drawer/i);

        expect(screen.getByRole('button', { name: /record it/i }).disabled).toBe(true);
    });

    it('records the amount, the kind and the note', async () => {
        show();
        await screen.findByText(/money out of the drawer/i);

        await tap('3000');
        await userEvent.type(screen.getByLabelText(/what it was for/i), 'Driver');
        await userEvent.click(screen.getByRole('button', { name: /record it/i }));

        await waitFor(() => expect(api.post).toHaveBeenCalled());
        expect(api.post.mock.calls[0][1]).toMatchObject({
            vendor_id: 1,
            amount: 3000,
            category: 'logistics_other',
            description: 'Driver',
        });
    });

    it('lets the cashier pick what it was for', async () => {
        show();
        await screen.findByText(/money out of the drawer/i);

        await userEvent.click(screen.getByText('Something else'));
        await tap('500');
        await userEvent.click(screen.getByRole('button', { name: /record it/i }));

        await waitFor(() => expect(api.post).toHaveBeenCalled());
        expect(api.post.mock.calls[0][1].category).toBe('other');
    });

    it('shows what was already paid out today', async () => {
        api.get.mockResolvedValue({
            data: {
                categories: { logistics_other: 'Transport / delivery', other: 'Something else' },
                expenses: [
                    { id: 1, category: 'logistics_other', amount: 3000, description: 'Driver' },
                    { id: 2, category: 'other', amount: 1500, description: 'Airtime' },
                ],
                total: 4500,
            },
        });

        show();

        // So a cashier can see it is already written down, rather than
        // recording it twice and going short by the difference.
        expect(await screen.findByText(/paid out today/i)).toBeTruthy();
        expect(screen.getByText('₦4,500.00')).toBeTruthy();
        expect(screen.getByText('Driver')).toBeTruthy();
    });

    it('says what recording it does', async () => {
        api.get.mockResolvedValue({
            data: {
                categories: { other: 'Something else' },
                expenses: [{ id: 1, category: 'other', amount: 100, description: 'x' }],
                total: 100,
            },
        });

        show();

        expect(await screen.findByText(/comes off what your drawer should hold/i)).toBeTruthy();
    });

    it('explains itself when there is no signal', async () => {
        api.post.mockRejectedValue({ response: { status: 500 } });

        show();
        await screen.findByText(/money out of the drawer/i);

        await tap('500');
        await userEvent.click(screen.getByRole('button', { name: /record it/i }));

        // The money has to reach the books as it leaves the drawer, so this one
        // genuinely cannot be queued.
        expect(await screen.findByText(/needs a connection/i)).toBeTruthy();
    });

    it('passes the server refusal straight through', async () => {
        api.post.mockRejectedValue({
            response: { status: 422, data: { message: 'This shop has no cash account set up.' } },
        });

        show();
        await screen.findByText(/money out of the drawer/i);

        await tap('500');
        await userEvent.click(screen.getByRole('button', { name: /record it/i }));

        expect(await screen.findByText(/no cash account set up/i)).toBeTruthy();
    });
});
