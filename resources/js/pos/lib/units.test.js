import { describe, expect, it } from 'vitest';
import { addToCart, roomFor } from './cartAdd';
import { cartFloorTotal, cartSubtotal } from './cartFloor';
import { cartLinesFromSale } from './recoverSale';
import { scanFor } from './scanSignal';
import { lineFields, prepareLine, shortfallFor, stockText, unitForBarcode } from './units';

const bottle = { id: 11, name: 'Bottle', conversion_factor: 1, price: 450, wholesale_price: 420, min_price: 450, wholesale_min_price: 420, is_base: true };
const half   = { id: 12, name: 'Half Crate', conversion_factor: 12, price: 5000, wholesale_price: null, min_price: 5000, is_base: false };
const crate  = { id: 13, name: 'Crate', conversion_factor: 24, price: 9700, wholesale_price: 9400, min_price: 9700, wholesale_min_price: 9400, is_base: false };

const coke = {
    id: 1,
    name: 'Coke 50cl',
    price: 450,
    barcode: '6001',
    available_stock: 60,
    reserved: 0,
    default_unit_id: 11,
    base_unit: 'Bottle',
    allow_negative: false,
    units: [bottle, half, crate],
    barcodes: [
        { barcode: '6001', unit_id: null },
        { barcode: '6024', unit_id: 13 },
    ],
};

// A product from a catalogue cached before units existed.
const oldCharger = { id: 2, name: 'Charger', price: 5300, available_stock: 4, reserved: 0, barcode: '9001' };

describe('a line sold in a unit', () => {
    it('goes in as the default unit at that unit\'s price', () => {
        const { items } = addToCart([], coke, 1);

        expect(items[0]).toMatchObject({ unit_id: 11, unit_name: 'Bottle', factor: 1, price: 450, qty: 1 });
    });

    it('costs the crate price, not 24 bottle prices', () => {
        const { items } = addToCart([], { ...coke, scanned_unit_id: 13 }, 1);

        expect(cartSubtotal(items)).toBe(9700);
        expect(items[0].factor).toBe(24);
    });

    it('keeps a crate and a bottle of the same drink as two lines', () => {
        let { items } = addToCart([], coke, 1);
        ({ items } = addToCart(items, { ...coke, scanned_unit_id: 13 }, 1));

        expect(items).toHaveLength(2);
    });

    it('measures the room left in the line\'s own unit, against one shelf', () => {
        const { items } = addToCart([], { ...coke, scanned_unit_id: 13 }, 1); // 24 bottles out of 60

        expect(roomFor(items, items[0], 0)).toBe(2);                         // 60 / 24
        expect(roomFor(items, prepareLine({ ...coke, unit_id: 12 }))).toBe(3); // (60 - 24) / 12
        expect(roomFor(items, prepareLine(coke))).toBe(36);
    });

    it('has no limit when the product may sell below zero, and reports the shortfall', () => {
        const negative = { ...coke, allow_negative: true, scanned_unit_id: 13 };
        const { items } = addToCart([], negative, 3);

        expect(roomFor(items, items[0], 0)).toBe(Infinity);
        expect(shortfallFor(items, items[0])).toBe(12);
    });
});

describe('wholesale', () => {
    it('uses the unit\'s wholesale price and floor', () => {
        const { items } = addToCart([], { ...coke, scanned_unit_id: 13 }, 2, 'wholesale');

        expect(items[0]).toMatchObject({ price_type: 'wholesale', price: 9400, min_price: 9400 });
        expect(cartFloorTotal(items)).toBe(18800);
    });

    it('falls back to retail, and says so, when the unit has no wholesale price', () => {
        expect(lineFields(half, 'wholesale')).toMatchObject({ price_type: 'retail', price: 5000, wholesaleMissing: true });
    });
});

describe('barcodes', () => {
    it('reads a unit\'s own code as that unit and the main code as the default unit', () => {
        expect(unitForBarcode(coke, '6024')).toBe(13);
        expect(unitForBarcode(coke, '6001')).toBe(11);
        expect(unitForBarcode(coke, 'nope')).toBeUndefined();
    });

    it('treats a scanned crate code as a scan of the crate', () => {
        const scanned = scanFor({ query: '6024', results: [coke], burst: true });

        expect(scanned?.scanned_unit_id).toBe(13);
    });
});

describe('stock in words', () => {
    it('uses the biggest unit', () => {
        expect(stockText(coke)).toBe('2 Crates + 12 Bottles (60)');
        expect(stockText({ ...coke, available_stock: 24 })).toBe('1 Crate (24)');
        expect(stockText({ ...coke, available_stock: -8 })).toBe('-8 (owed)');
    });

    it('is just the number for a product with no bigger unit', () => {
        expect(stockText(oldCharger)).toBe('4');
    });
});

describe('a product with no units', () => {
    it('goes into the cart exactly as before', () => {
        const { items } = addToCart([], oldCharger, 2);

        expect(items[0].unit_id).toBeUndefined();
        expect(items[0].price).toBe(5300);
        expect(roomFor(items, items[0], 0)).toBe(4);
    });
});

describe('a refused sale handed back', () => {
    it('comes back as crates at the crate price', () => {
        const [line] = cartLinesFromSale([{
            product_id: 1, product_name: 'Coke 50cl', unit_price: 9700, quantity: 24,
            product_unit_id: 13, unit_qty: 1, unit_name: 'Crate', price_type: 'retail',
        }], [coke]);

        expect(line).toMatchObject({ unit_id: 13, qty: 1, price: 9700, factor: 24 });
    });
});
