import { describe, expect, it } from 'vitest';
import { cartLinesFromSale } from './recoverSale';

// A refused sale's lines come back as cart lines. The factor — how many base
// units one sold unit is — comes from the unit in the cached catalogue, never
// worked back from quantity ÷ unit_qty, which is only a guess with halves.

describe('the factor of a recovered line', () => {
    it('is read from the unit when the catalogue still has it', () => {
        // The product itself is gone from this branch, but another cached
        // entry still carries the unit (the catalogue is keyed by unit id).
        const catalogue = [{ id: 99, units: [{ id: 7, name: 'Crate', conversion_factor: 24 }] }];
        const [line] = cartLinesFromSale([
            // Half a crate: 12 bottles for unit_qty 0.5. Rounding 12 ÷ 0.5
            // happens to give 24 here, but must not be what decides it.
            { product_id: 1, product_name: 'Hero Lager', product_unit_id: 7, unit_qty: 0.5, quantity: 12, unit_price: 10100 },
        ], catalogue);

        expect(line.factor).toBe(24);
        expect(line.qty).toBe(0.5);
        expect(line.unit_name).toBe('Crate');
    });

    it('falls back to quantity ÷ unit_qty only when the unit is unknown', () => {
        const [line] = cartLinesFromSale([
            { product_id: 1, product_name: 'Hero Lager', product_unit_id: 8, unit_qty: 2, quantity: 48, unit_price: 9700 },
        ], []);

        expect(line.factor).toBe(24);
    });
});
