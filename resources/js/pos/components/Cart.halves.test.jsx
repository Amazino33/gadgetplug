import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Cart from './Cart';

// The cart's half controls, on the desktop row and on the phone row. The
// phone row had no quantity buttons at all before half quantities, so for a
// shop with the switch off it must stay exactly that way.

const line = { id: 1, name: 'Hero Lager', price: 10100, listPrice: 10100, qty: 1, available_stock: 20, base_unit: 'Crate' };

afterEach(() => {
    cleanup();
    localStorage.removeItem('pos_vendor_settings');
});

function renderCart(onQtyChange = vi.fn()) {
    render(<Cart items={[line]} selectedIdx={null} onSelect={vi.fn()} onQtyChange={onQtyChange} onRemove={vi.fn()} />);

    return onQtyChange;
}

describe('Cart half controls', () => {
    it('shows no half controls anywhere for a shop with halves off', () => {
        renderCart();

        expect(screen.queryAllByRole('button', { name: /add a half/i })).toHaveLength(0);
        expect(screen.queryAllByRole('button', { name: /half more/i })).toHaveLength(0);
    });

    it('shows ½ on both the desktop row and the phone row for a halves shop', () => {
        localStorage.setItem('pos_vendor_settings', JSON.stringify({ allow_half_quantities: true }));
        renderCart();

        // One in the desktop stepper, one in the phone row's − ½ + control.
        expect(screen.getAllByRole('button', { name: /add a half/i })).toHaveLength(2);
    });

    it('adds the half from the phone row', async () => {
        localStorage.setItem('pos_vendor_settings', JSON.stringify({ allow_half_quantities: true }));
        const onQtyChange = renderCart();

        // The phone row's control comes first in the markup.
        await userEvent.click(screen.getAllByRole('button', { name: /add a half/i })[0]);

        expect(onQtyChange).toHaveBeenCalledWith(0, 1.5);
    });
});
