import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import Cart from './Cart';

afterEach(cleanup);

// The one-line cart: unit control, typed quantity, price lock and stock badges.

const pcs   = { id: 11, name: 'pcs', conversion_factor: 1, price: 250, min_price: 250, is_base: true };
const pack  = { id: 13, name: 'Pack', conversion_factor: 12, price: 2800, min_price: 2800 };
const half  = { id: 12, name: 'Half Pack', conversion_factor: 6, price: 1450, min_price: 1450 };

const line = (over = {}) => ({
    id: 1, name: 'Indomie Chicken 70g', sku: 'IND-70', price: 250, listPrice: 250, qty: 2,
    available_stock: 100, reserved: 0, is_low_stock: false,
    // Created in the order pcs, Pack, Half Pack — shown smallest first anyway.
    units: [pcs, pack, half], unit_id: 11, unit_name: 'pcs', factor: 1, default_unit_id: 11,
    price_type: 'retail', can_negotiate: true,
    ...over,
});

function renderCart(items, props = {}) {
    const handlers = {
        onSelect: vi.fn(), onQtyChange: vi.fn(), onRemove: vi.fn(), onPriceEdit: vi.fn(),
        onUnitChange: vi.fn(), onPriceTypeChange: vi.fn(), onSetDefaultUnit: vi.fn(), onQtyEditDone: vi.fn(),
    };

    render(<Cart items={items} selectedIdx={null} {...handlers} {...props} />);

    return handlers;
}

// The desktop rows (the phone cards render the same items again, hidden).
const desktop = () => document.querySelector('.hidden.md\\:block');

describe('the unit control', () => {
    it('orders units smallest to largest, one active, and switches on a tap', () => {
        const h = renderCart([line()]);
        const group = within(desktop()).getByRole('group', { name: 'Selling unit' });
        const buttons = within(group).getAllByRole('button');

        expect(buttons.map((b) => b.getAttribute('title'))).toEqual(['pcs', 'Half Pack', 'Pack']);
        expect(buttons.filter((b) => b.getAttribute('aria-pressed') === 'true')).toHaveLength(1);

        fireEvent.click(buttons[2]);
        expect(h.onUnitChange).toHaveBeenCalledWith(0, 13);
    });

    it('shows a single unit as plain text, with no control', () => {
        renderCart([line({ units: [pcs] })]);

        expect(within(desktop()).queryByRole('group', { name: 'Selling unit' })).toBeNull();
        expect(within(desktop()).getByText('pcs')).toBeTruthy();
    });
});

describe('typing a quantity', () => {
    const openField = () => {
        fireEvent.click(within(desktop()).getByTitle('Tap to type a quantity'));
        return within(desktop()).getByLabelText('Quantity');
    };

    it('commits on Enter and hands the keyboard back to the scan box', () => {
        const h = renderCart([line()]);
        const field = openField();

        fireEvent.change(field, { target: { value: '7' } });
        fireEvent.keyDown(field, { key: 'Enter' });

        expect(h.onQtyChange).toHaveBeenCalledWith(0, 7);
        expect(h.onQtyEditDone).toHaveBeenCalled();
    });

    it('puts the old number back on Esc, and on 0', () => {
        const h = renderCart([line()]);

        let field = openField();
        fireEvent.change(field, { target: { value: '9' } });
        fireEvent.keyDown(field, { key: 'Escape' });
        fireEvent.blur(field);

        field = openField();
        fireEvent.change(field, { target: { value: '0' } });
        fireEvent.keyDown(field, { key: 'Enter' });

        expect(h.onQtyChange).not.toHaveBeenCalled();
    });

    it('takes whole numbers only', () => {
        renderCart([line()]);
        const field = openField();

        fireEvent.change(field, { target: { value: '1.5' } });

        expect(field.value).toBe('15');
    });
});

describe('the price lock', () => {
    it('shows a plain price that cannot be clicked when price changes are off', () => {
        const h = renderCart([line()], { allowPriceChange: false });

        expect(within(desktop()).queryByTitle('Click to change this price')).toBeNull();
        fireEvent.click(within(desktop()).getAllByText('₦250.00')[0]);
        expect(h.onPriceEdit).not.toHaveBeenCalled();
    });

    it('offers the dotted, clickable price when they are on', () => {
        const h = renderCart([line()], { allowPriceChange: true });

        fireEvent.click(within(desktop()).getByTitle('Click to change this price'));
        expect(h.onPriceEdit).toHaveBeenCalledWith(0);
    });
});

describe('stock', () => {
    it('keeps stock in the tooltip, not on the row', () => {
        renderCart([line()]);

        expect(within(desktop()).queryByText(/In stock:/)).toBeNull();
        expect(within(desktop()).getByTitle(/In stock: 8 Packs \+ 4 pcs \(100\)/)).toBeTruthy();
    });

    it('flags low stock, and a sale that needs more than the shelf holds', () => {
        renderCart([line({ is_low_stock: true })]);
        expect(within(desktop()).getByText('Low')).toBeTruthy();

        cleanup();

        // 9 Packs = 108 pieces against 100 on the shelf.
        renderCart([line({ unit_id: 13, unit_name: 'Pack', factor: 12, qty: 9, allow_negative: true })]);
        expect(within(desktop()).getByText('Exceeds stock')).toBeTruthy();
    });

    it('shows no badge when stock is fine', () => {
        renderCart([line()]);

        expect(within(desktop()).queryByText('Low')).toBeNull();
        expect(within(desktop()).queryByText('Exceeds stock')).toBeNull();
    });
});

describe('the thumbnail', () => {
    it('falls back to the product\'s initials', () => {
        renderCart([line({ image: null })]);

        expect(within(desktop()).getByText('IC')).toBeTruthy();
    });
});
