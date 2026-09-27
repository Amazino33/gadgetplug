import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import DebtRepaymentModal from './DebtRepaymentModal';

vi.mock('../lib/api', () => ({
    default: { get: vi.fn(), post: vi.fn() },
}));

afterEach(cleanup);

let api;

const customer = { id: 7, name: 'Ada Obi', phone: '08031234567' };

beforeEach(async () => {
    api = (await import('../lib/api')).default;
    vi.clearAllMocks();
    api.get.mockImplementation((url) => {
        if (url === '/customers') return Promise.resolve({ data: [customer] });
        if (url === `/customers/${customer.id}/outstanding`) return Promise.resolve({ data: { customer_id: customer.id, outstanding: 10000 } });
        return Promise.resolve({ data: {} });
    });
    api.post.mockResolvedValue({
        data: { payment: { id: 1, method: 'cash', amount: 4000 }, outstanding: 6000 },
    });
});

const show = (props = {}) => render(
    <DebtRepaymentModal vendorId={1} onClose={() => {}} {...props} />,
);

const tap = async (amount) => {
    for (const key of String(amount).split('')) {
        await userEvent.click(screen.getByRole('button', { name: key }));
    }
};

const pickCustomer = async () => {
    await userEvent.type(screen.getByPlaceholderText(/search by name or phone/i), 'Ada');
    await userEvent.click(await screen.findByText('Ada Obi'));
    await screen.findByText(/owes/i);
};

describe('recording a debt repayment', () => {
    it('opens on a customer search', async () => {
        show();

        expect(await screen.findByText(/debt repayment/i)).toBeTruthy();
        expect(screen.getByPlaceholderText(/search by name or phone/i)).toBeTruthy();
    });

    it('finds and selects a customer, then shows what they owe', async () => {
        show();
        await pickCustomer();

        expect(screen.getByText('Ada Obi')).toBeTruthy();
        expect(screen.getByText(/owes/i).textContent).toMatch(/10,000/);
    });

    it('defaults to cash and records the amount, method and note', async () => {
        show();
        await pickCustomer();

        await tap('4000');
        await userEvent.type(screen.getByLabelText(/note/i), 'Part payment');
        await userEvent.click(screen.getByRole('button', { name: /record it/i }));

        await waitFor(() => expect(api.post).toHaveBeenCalled());
        expect(api.post.mock.calls[0][0]).toBe(`/customers/${customer.id}/repayments`);
        expect(api.post.mock.calls[0][1]).toMatchObject({
            vendor_id: 1,
            amount: 4000,
            method: 'cash',
            note: 'Part payment',
        });
    });

    it('lets the cashier switch to card or transfer', async () => {
        show();
        await pickCustomer();

        await userEvent.click(screen.getByText('Transfer'));
        await tap('4000');
        await userEvent.click(screen.getByRole('button', { name: /record it/i }));

        await waitFor(() => expect(api.post).toHaveBeenCalled());
        expect(api.post.mock.calls[0][1].method).toBe('bank_transfer');
    });

    it('refuses to submit more than the customer owes, before asking the server', async () => {
        show();
        await pickCustomer();

        await tap('10001');
        await userEvent.click(screen.getByRole('button', { name: /record it/i }));

        expect(await screen.findByText(/cannot be collected/i)).toBeTruthy();
        expect(api.post).not.toHaveBeenCalled();
    });

    it('updates the shown balance after a successful collection', async () => {
        show();
        await pickCustomer();

        await tap('4000');
        await userEvent.click(screen.getByRole('button', { name: /record it/i }));

        await screen.findByText(/4,000.*collected/i);
        expect(screen.getByText(/owes/i).textContent).toMatch(/6,000/);
    });

    it('passes the server refusal straight through', async () => {
        api.post.mockRejectedValue({
            response: { status: 422, data: { message: 'This customer owes 2,000.00, so 4,000.00 cannot be collected.' } },
        });

        show();
        await pickCustomer();

        await tap('4000');
        await userEvent.click(screen.getByRole('button', { name: /record it/i }));

        expect(await screen.findByText(/cannot be collected/i)).toBeTruthy();
    });

    it('explains itself when there is no signal', async () => {
        api.post.mockRejectedValue({ response: { status: 500 } });

        show();
        await pickCustomer();

        await tap('4000');
        await userEvent.click(screen.getByRole('button', { name: /record it/i }));

        expect(await screen.findByText(/needs a connection/i)).toBeTruthy();
    });
});
