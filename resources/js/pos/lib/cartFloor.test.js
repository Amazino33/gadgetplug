import { describe, expect, it } from 'vitest';
import { cartFloorTotal, cartSubtotal, discountBreachesFloor, lineFloor } from './cartFloor';

// A cashier applied a discount to the whole cart, the till accepted it, and the
// server refused it — which offline means the sale can never sync and the goods
// have already gone. Every line was on its own floor; the cart discount is what
// dragged the total under.

const item = (over = {}) => ({ price: 1000, min_price: 800, qty: 1, lineDiscount: 0, ...over });

describe('lineFloor', () => {
    it('uses the negotiated minimum when there is one', () => {
        expect(lineFloor(item())).toBe(800);
    });

    it('falls back to the list price when no minimum was sent', () => {
        expect(lineFloor({ price: 1000, listPrice: 1200, qty: 1 })).toBe(1200);
        expect(lineFloor({ price: 1000, qty: 1 })).toBe(1000);
    });
});

describe('cartFloorTotal', () => {
    it('counts every unit, not every line', () => {
        expect(cartFloorTotal([item({ qty: 3 })])).toBe(2400);
    });

    it('adds the floors of a mixed cart', () => {
        expect(cartFloorTotal([
            item({ min_price: 800, qty: 2 }),
            item({ min_price: 500, qty: 1 }),
        ])).toBe(2100);
    });

    it('is nothing for an empty cart', () => {
        expect(cartFloorTotal([])).toBe(0);
    });
});

describe('discountBreachesFloor', () => {
    it('allows a discount that stops above the floor', () => {
        // 1,000 list, 800 floor: 150 off leaves 850.
        expect(discountBreachesFloor([item()], 150)).toBe(false);
    });

    it('allows a discount landing exactly on the floor', () => {
        // Exactly 800 left. Refusing this would be the arithmetic being fussy,
        // not the rule being enforced.
        expect(discountBreachesFloor([item()], 200)).toBe(false);
    });

    it('refuses a discount that goes under the floor', () => {
        expect(discountBreachesFloor([item()], 201)).toBe(true);
    });

    it('refuses the case the server was catching: every line on its floor, then a cart discount', () => {
        // Both lines already sit exactly on their own minimum, so each passes
        // the per-line check. The cart discount is what breaches it.
        const cart = [
            item({ price: 800, min_price: 800 }),
            item({ price: 500, min_price: 500 }),
        ];

        expect(cartSubtotal(cart)).toBe(1300);
        expect(cartFloorTotal(cart)).toBe(1300);
        expect(discountBreachesFloor(cart, 100)).toBe(true);
    });

    it('counts a line discount already given, so the two together cannot slip under', () => {
        // 1,000 list with 150 already off the line leaves 850; another 100 off
        // the cart would land at 750, under the 800 floor.
        const cart = [item({ lineDiscount: 150 })];

        expect(cartSubtotal(cart)).toBe(850);
        expect(discountBreachesFloor(cart, 100)).toBe(true);
    });

    it('treats no discount as no breach', () => {
        expect(discountBreachesFloor([item()], 0)).toBe(false);
        expect(discountBreachesFloor([item()], undefined)).toBe(false);
    });

    it('lets a product with no floor of its own be discounted to its list price only', () => {
        const cart = [{ price: 1000, qty: 1 }];

        expect(discountBreachesFloor(cart, 1)).toBe(true);
    });
});
