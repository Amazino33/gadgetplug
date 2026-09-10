import { describe, expect, it } from 'vitest';
import { addToCart } from './cartAdd';

const charger = { id: 1, name: 'Samsung 25W Charger', price: 5300 };
const cable   = { id: 2, name: 'Samsung Cable',       price: 1500 };

describe('addToCart', () => {
    it('starts a line for a product that is not on the sale yet', () => {
        const { items, index } = addToCart([], charger, 1);

        expect(items).toHaveLength(1);
        expect(items[0]).toMatchObject({ id: 1, qty: 1, lineDiscount: 0 });
        expect(index).toBe(0);
    });

    it('keeps the catalogue price alongside the sale price', () => {
        // listPrice is what the receipt shows the customer they were spared
        // once `price` has been haggled down.
        const { items } = addToCart([], charger, 1);

        expect(items[0].listPrice).toBe(5300);
        expect(items[0].price).toBe(5300);
    });

    it('adds to a line that is already there rather than making a second one', () => {
        const first  = addToCart([], charger, 1);
        const second = addToCart(first.items, charger, 1);

        expect(second.items).toHaveLength(1);
        expect(second.items[0].qty).toBe(2);
        expect(second.index).toBe(0);
    });

    it('adds the quantity asked for, it does not replace what is there', () => {
        // Four on the sale, cashier asks for two more: six. This is the whole
        // difference between this and the quantity box's own rule.
        const cart = [{ ...charger, qty: 4 }];

        expect(addToCart(cart, charger, 2).items[0].qty).toBe(6);
    });

    it('defaults to one, which is what a scan means', () => {
        const cart = [{ ...charger, qty: 3 }];

        expect(addToCart(cart, charger).items[0].qty).toBe(4);
    });

    it('points at the line it touched, wherever it is', () => {
        const cart = [{ ...cable, qty: 1 }, { ...charger, qty: 1 }];

        expect(addToCart(cart, charger, 1).index).toBe(1);
        expect(addToCart(cart, { id: 3, name: 'New', price: 10 }, 1).index).toBe(2);
    });

    it('never mutates the cart it was given', () => {
        const cart = [{ ...charger, qty: 1 }];
        const snapshot = JSON.parse(JSON.stringify(cart));

        addToCart(cart, charger, 5);
        addToCart(cart, cable, 1);

        expect(cart).toEqual(snapshot);
    });

    it('leaves everything else on the line alone when it grows', () => {
        // A negotiated price and a line discount survive a second helping.
        const cart = [{ ...charger, qty: 1, price: 4800, listPrice: 5300, lineDiscount: 200 }];

        expect(addToCart(cart, charger, 1).items[0]).toMatchObject({
            qty: 2, price: 4800, listPrice: 5300, lineDiscount: 200,
        });
    });
});
