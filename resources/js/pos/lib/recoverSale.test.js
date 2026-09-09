import { describe, expect, it } from 'vitest';
import { cartLinesFromSale } from './recoverSale';

// A refused sale sent back to the till came back as bare sale lines — name,
// price, quantity — with none of the product behind them. The price limits live
// on the product, so the till had nothing to measure a new price against and
// the cashier could not do the one thing they had come to do.

const product = (over = {}) => ({
    id: 7, name: 'BATTERY BL-5C', sku: 'BL5C', barcode: '600123',
    price: 3000, min_price: 2500, available_stock: 4, ...over,
});

const soldLine = (over = {}) => ({
    product_id: 7, product_name: 'BATTERY BL-5C', product_sku: 'BL5C',
    unit_price: 2000, quantity: 2, discount_amount: 0, ...over,
});

describe('cartLinesFromSale', () => {
    it('brings the product back with it, so the line can be repriced', () => {
        const [line] = cartLinesFromSale([soldLine()], [product()]);

        // These are what PriceModal measures a new price against. Without them
        // it has no floor and no list price, and refuses every change.
        expect(line.min_price).toBe(2500);
        expect(line.listPrice).toBe(3000);
        expect(line.barcode).toBe('600123');
        expect(line.available_stock).toBe(4);
    });

    it('lifts a price that could never have been accepted up to the floor', () => {
        // Sold at 2,000 against a 2,500 floor — the refusal itself.
        const [line] = cartLinesFromSale([soldLine({ unit_price: 2000 })], [product()]);

        expect(line.price).toBe(2500);
    });

    it('leaves a legitimately negotiated price alone', () => {
        // 2,800 is above the floor, so it was never the problem.
        const [line] = cartLinesFromSale([soldLine({ unit_price: 2800 })], [product()]);

        expect(line.price).toBe(2800);
    });

    it('keeps the quantity and any line discount that was given', () => {
        const [line] = cartLinesFromSale(
            [soldLine({ quantity: 3, discount_amount: 150 })],
            [product()],
        );

        expect(line.qty).toBe(3);
        expect(line.lineDiscount).toBe(150);
    });

    it('still returns a product that has left the catalogue, so it can be removed', () => {
        // Unpublished or moved to another branch since it was sold. Dropping it
        // silently would leave the cashier with a sale they cannot reconcile.
        const [line] = cartLinesFromSale([soldLine()], []);

        expect(line.id).toBe(7);
        expect(line.name).toBe('BATTERY BL-5C');
        expect(line.qty).toBe(2);
    });

    it('handles a mixed sale, matching each line to its own product', () => {
        const lines = cartLinesFromSale(
            [soldLine(), soldLine({ product_id: 9, product_name: 'Charger', unit_price: 500 })],
            [product(), product({ id: 9, name: 'Charger', price: 900, min_price: 700 })],
        );

        expect(lines).toHaveLength(2);
        expect(lines[0].price).toBe(2500);
        expect(lines[1].price).toBe(700);
    });

    it('falls back to the list price when a product carries no floor', () => {
        const [line] = cartLinesFromSale(
            [soldLine({ unit_price: 1000 })],
            [product({ min_price: undefined })],
        );

        expect(line.price).toBe(3000);
    });

    it('copes with a sale that somehow has no lines', () => {
        expect(cartLinesFromSale(undefined, [])).toEqual([]);
        expect(cartLinesFromSale([], [])).toEqual([]);
    });
});
