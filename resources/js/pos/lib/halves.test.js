import { afterEach, describe, expect, it } from 'vitest';
import {
    cleanQty, floorToStep, formatQty, halfUnitPrice, halvesEnabled, hasHalf, lineFloorTotal,
    lineGross, lineNet, parseQty, qtyError, qtyStep,
} from './halves';

// The till's half of the half-quantities rules. The server's HalfPricing is
// the one that is kept; these must agree with it, and must leave every whole
// quantity exactly as the till always priced it.

const crate = { price: 10100, listPrice: 10100, price_type: 'retail', half_price: null };

afterEach(() => localStorage.removeItem('pos_vendor_settings'));

describe('the switch', () => {
    it('is off unless the vendor settings turn it on', () => {
        expect(halvesEnabled()).toBe(false);
        expect(qtyStep()).toBe(1);

        localStorage.setItem('pos_vendor_settings', JSON.stringify({ allow_half_quantities: true }));

        expect(halvesEnabled()).toBe(true);
        expect(qtyStep()).toBe(0.5);
    });
});

describe('pricing a line', () => {
    it('charges exactly half when no half price is set', () => {
        expect(lineGross({ ...crate, qty: 0.5 })).toBe(5050);
        expect(lineGross({ ...crate, qty: 1.5 })).toBe(15150);
    });

    it('charges the set half price at the list price', () => {
        const line = { ...crate, half_price: 5200 };

        expect(lineGross({ ...line, qty: 0.5 })).toBe(5200);
        expect(lineGross({ ...line, qty: 1.5 })).toBe(15300);
    });

    it('halves the wholesale price whatever half price is set', () => {
        const line = { price: 9600, listPrice: 9600, price_type: 'wholesale', half_price: 5200 };

        expect(halfUnitPrice(line)).toBe(4800);
        expect(lineGross({ ...line, qty: 2.5 })).toBe(24000);
    });

    it('halves a haggled price rather than using the list half price', () => {
        const line = { ...crate, price: 9000, half_price: 5200 };

        expect(halfUnitPrice(line)).toBe(4500);
    });

    it('prices a whole line as price × qty, exactly as before', () => {
        const line = { price: 404.17, qty: 24 };

        expect(lineGross(line)).toBe(404.17 * 24);
        expect(lineNet({ ...line, lineDiscount: 100 })).toBe(404.17 * 24 - 100);
    });

    it('holds a half to half the unit floor', () => {
        expect(lineFloorTotal({ qty: 1.5 }, 8000)).toBe(12000);
        expect(lineFloorTotal({ qty: 3 }, 8000)).toBe(24000);
    });
});

describe('reading and showing quantities', () => {
    it('shows halves as ½ for a halves shop and plainly for everyone else', () => {
        expect(formatQty(0.5, true)).toBe('½');
        expect(formatQty(1.5, true)).toBe('1½');
        expect(formatQty(12, true)).toBe('12');
        expect(formatQty(12, false)).toBe('12');
    });

    it('reads 2, 2.5, 2½ and ½', () => {
        expect(parseQty('2')).toBe(2);
        expect(parseQty('2.5')).toBe(2.5);
        expect(parseQty('2½')).toBe(2.5);
        expect(parseQty('½')).toBe(0.5);
        expect(parseQty('abc')).toBeNull();
    });

    it('refuses 0.3 and 0 in a halves shop, and any fraction elsewhere', () => {
        expect(qtyError(0.5, true)).toBeNull();
        expect(qtyError(0.3, true)).not.toBeNull();
        expect(qtyError(0, true)).not.toBeNull();
        expect(qtyError(0.5, false)).not.toBeNull();
        expect(qtyError(2, false)).toBeNull();
    });

    it('floors room on the shelf to the step, and tidies float noise', () => {
        expect(floorToStep(19.75, true)).toBe(19.5);
        expect(floorToStep(19.75, false)).toBe(19);
        expect(cleanQty(1.4999999)).toBe(1.5);
        expect(hasHalf(2)).toBe(false);
        expect(hasHalf(2.5)).toBe(true);
    });
});
