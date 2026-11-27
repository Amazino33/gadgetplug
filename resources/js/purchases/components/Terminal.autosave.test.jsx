import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, cleanup, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const products = {
    111: { id: 1, name: 'Item 111', barcode: '111', sku: null, base_unit: 'Piece', price: 500, cost_price: 300, track_serial: false, scanned_unit_id: null, units: [{ id: 101, name: 'Piece', factor: 1, is_base: true }] },
    222: { id: 2, name: 'Item 222', barcode: '222', sku: null, base_unit: 'Piece', price: 900, cost_price: 600, track_serial: false, scanned_unit_id: null, units: [{ id: 102, name: 'Piece', factor: 1, is_base: true }] },
};
let saves = 0;

vi.mock('../lib/api', () => ({
    default: {
        get: vi.fn(async (url) => {
            if (url === '/suppliers') return { data: { suppliers: [{ id: 9, name: 'Dangote', phone: null, balance: 0 }] } };
            if (url === '/catalogue') return { data: Object.values(products) };
            return { data: { products: [] } };
        }),
        post: vi.fn(async (url, body) => {
            if (url === '/purchases') {
                saves += 1;
                return { data: { purchase: { id: body.id ?? 77, reference: 'PUR-0077' } } };
            }
            return { data: {} };
        }),
    },
    errorMessage: () => 'error',
    fieldErrors: () => ({}),
}));

import Terminal from './Terminal';
import api from '../lib/api';

const open = (props = {}) => render(<Terminal active resume={null} onResumed={() => {}} onSaved={() => {}} {...props} />);
const draftPosts = () => api.post.mock.calls.filter(([url, body]) => url === '/purchases' && body.action === 'draft');

describe('A purchase is never lost', () => {
    beforeEach(() => { vi.clearAllMocks(); saves = 0; localStorage.clear(); });
    afterEach(() => { cleanup(); localStorage.clear(); });

    it('survives a reload: the lines come back as they were, kept on this device', async () => {
        const user = userEvent.setup();
        open();
        await screen.findByText(/2 products/);
        await user.keyboard('111{Enter}');
        await user.keyboard('222{Enter}');
        await waitFor(() => expect(screen.getAllByText(/^Item \d{3}$/)).toHaveLength(2));
        expect(screen.getByText(/Choose the supplier to also save it as a draft/)).toBeTruthy();

        cleanup(); // the page goes away …
        open();    // … and opens again

        expect(screen.getAllByText(/^Item \d{3}$/)).toHaveLength(2);
        expect(await screen.findByText(/Your purchase was kept: 2 lines/)).toBeTruthy();
        // Nothing went to the server without a supplier.
        expect(draftPosts()).toHaveLength(0);
    });

    it('with a supplier chosen, saves itself as a draft a moment after the last change, then keeps updating that one draft', async () => {
        const user = userEvent.setup();
        open();
        await screen.findByText(/2 products/);
        await screen.findByRole('option', { name: /Dangote/ });
        await user.keyboard('111{Enter}');
        fireEvent.change(screen.getByLabelText('Supplier'), { target: { value: '9' } });

        await waitFor(() => expect(draftPosts()).toHaveLength(1), { timeout: 6000 });
        expect(draftPosts()[0][1]).toMatchObject({ action: 'draft', id: null, supplier_id: 9 });
        await waitFor(() => expect(document.querySelector('[data-autosave="saved"]')?.textContent).toMatch(/PUR-0077 saved at .*Purchase History/));

        await user.keyboard('222{Enter}');
        await waitFor(() => expect(draftPosts()).toHaveLength(2), { timeout: 6000 });
        // The same draft, not a second one.
        expect(draftPosts()[1][1]).toMatchObject({ id: 77 });
        expect(draftPosts()[1][1].lines).toHaveLength(2);
    }, 15000);

    it('a purchase awaiting approval is never saved back to a draft on its own', async () => {
        const resume = {
            id: 5, reference: 'PUR-0005', awaiting_approval: true, recorded_by: 'Ada', supplier_id: 9,
            invoice_number: null, purchased_on: null, notes: null,
            lines: [{ product: products[111], product_unit_id: null, unit_qty: null, quantity: 3, unit_cost: 300, selling_price: 500, unit_prices: null, serials: [] }],
        };
        open({ resume });
        await screen.findAllByText(/Reviewing/);
        await new Promise((r) => setTimeout(r, 4600));

        expect(draftPosts()).toHaveLength(0);
    }, 10000);
});
