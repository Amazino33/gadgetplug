import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ReturnModal from './ReturnModal';

// The return screen steps in halves for a shop that sells them and in whole
// units for everyone else. It also had no test at all, which is how a second
// `step` variable once reached a build before anything noticed.

vi.mock('../lib/api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../lib/salesHistory', () => ({ recordRefund: vi.fn() }));

const sale = {
    id: 9, reference: 'POS-HALF1', total: 15300,
    items: [{ product_id: 1, product_name: 'Hero Lager', unit_price: 10200, quantity: 1.5, returnable: 1.5, returned: 0 }],
};

beforeEach(async () => {
    const api = (await import('../lib/api')).default;
    api.get.mockReset();
    api.get.mockResolvedValue({ data: sale });
});

afterEach(() => {
    cleanup();
    localStorage.removeItem('pos_vendor_settings');
});

async function findSale() {
    render(<ReturnModal vendorId={1} cashierId={1} onClose={vi.fn()} />);
    await userEvent.type(screen.getByPlaceholderText(/POS-ABCD1234/i), 'POS-HALF1{Enter}');
    await screen.findByText('Hero Lager');
}

describe('ReturnModal', () => {
    it('steps a halves shop return by ½', async () => {
        localStorage.setItem('pos_vendor_settings', JSON.stringify({ allow_half_quantities: true }));
        await findSale();

        await userEvent.click(screen.getAllByText('+')[0]);

        expect(screen.getByText('½')).toBeDefined();
    });

    it('steps every other shop by 1, as before', async () => {
        await findSale();

        await userEvent.click(screen.getAllByText('+')[0]);

        expect(screen.getByText('1')).toBeDefined();
    });
});
