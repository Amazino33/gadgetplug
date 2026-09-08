import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import SearchBar from './SearchBar';
import QuantityModal from './QuantityModal';

// Reported from the counter: "the only time the quantity comes up is when we
// use mouse to tap". Picking with the mouse asks for a quantity; picking with
// arrow-down + Enter does not — the product goes straight into the sale.
//
// Both are supposed to be the same path (pick -> onSelect -> open the box), so
// this drives the real SearchBar and the real QuantityModal both ways and
// compares them, rather than reasoning about which handler runs when.

const charger = { id: 1, name: 'Samsung 25W Charger', sku: 'SAM-25', barcode: '600123', price: 5300, available_stock: 4 };
const cable   = { id: 2, name: 'Samsung Cable',       sku: 'SAM-CB', barcode: '600987', price: 1500, available_stock: 9 };

let localCatalogue = [];

vi.mock('../lib/db', () => ({
    db: {
        products: {
            filter: (predicate) => ({
                limit: () => ({ toArray: async () => localCatalogue.filter(predicate) }),
            }),
        },
    },
}));

vi.mock('../lib/api', () => ({ default: { get: vi.fn(async () => ({ data: [] })) } }));

// The shape POS uses: picking from search always opens the quantity box.
function Till({ onConfirm }) {
    const [picked, setPicked] = useState(null);

    return (
        <div>
            <SearchBar vendorId={1} onSelect={(p) => setPicked({ ...p, qty: 1 })} />
            {picked && (
                <QuantityModal
                    item={picked}
                    onConfirm={(qty) => { onConfirm(picked, qty); setPicked(null); }}
                    onClose={() => setPicked(null)}
                />
            )}
        </div>
    );
}

const quantityBox = () => screen.queryByRole('textbox', { name: /quantity/i });

beforeEach(() => { localCatalogue = [charger, cable]; });
afterEach(cleanup);

describe('picking a product asks for a quantity', () => {
    it('when the row is clicked', async () => {
        render(<Till onConfirm={vi.fn()} />);

        await userEvent.type(screen.getByRole('textbox', { name: /search products/i }), 'samsung');
        await userEvent.click(await screen.findByText('Samsung 25W Charger'));

        expect(await screen.findByRole('textbox', { name: /quantity/i })).toBeDefined();
    });

    it('when the row is reached with arrow-down and Enter', async () => {
        // The reported difference. Same pick, no mouse.
        render(<Till onConfirm={vi.fn()} />);

        await userEvent.type(screen.getByRole('textbox', { name: /search products/i }), 'samsung');
        expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();

        await userEvent.keyboard('{ArrowDown}{Enter}');

        expect(await screen.findByRole('textbox', { name: /quantity/i })).toBeDefined();
    });

    it('and the box that opens from the keyboard is ready to be typed into', async () => {
        const onConfirm = vi.fn();
        render(<Till onConfirm={onConfirm} />);

        await userEvent.type(screen.getByRole('textbox', { name: /search products/i }), 'samsung');
        expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();

        await userEvent.keyboard('{ArrowDown}{Enter}');
        await waitFor(() => expect(document.activeElement).toBe(quantityBox()));

        // Highlighted, so the typed digit replaces the 1 rather than appending.
        await userEvent.keyboard('3{Enter}');

        expect(onConfirm).toHaveBeenCalledWith(expect.objectContaining({ id: charger.id }), 3);
    });
});
