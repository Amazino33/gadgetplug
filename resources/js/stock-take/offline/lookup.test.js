import { describe, it, expect } from 'vitest';
import { buildIndex, lookupCode, search } from './lookup';

const pack = {
    products: [
        [5, 'Coke 50cl', 'COKE-50', null, 'Piece', [[6, 'Crate', 12], [5, 'Piece', 1]], [['544900', null], ['544912', 6]]],
        [7, 'Peak Milk 400g', 'PEAK-400', 'http://x/7.webp', 'Piece', [[7, 'Piece', 1]], [['600100', null]]],
        [8, 'Milo 400g', 'MILO', null, 'Piece', [[8, 'Piece', 1]], []],
    ],
};

describe('looking products up from the pack', () => {
    const index = buildIndex(pack);

    it('finds a product by its barcode, and by a crate\'s own barcode with the crate chosen', () => {
        expect(lookupCode(index, '544900')).toMatchObject({ product: { id: 5 }, unitId: null });
        expect(lookupCode(index, ' 544912 ')).toMatchObject({ product: { id: 5 }, unitId: 6 });
        expect(lookupCode(index, '544912').product.units[0]).toEqual({ id: 6, name: 'Crate', factor: 12, is_base: false });
    });

    it('falls back to the SKU, whatever its case; an unknown code is null', () => {
        expect(lookupCode(index, 'peak-400').product.id).toBe(7);
        expect(lookupCode(index, '999')).toBeNull();
        expect(lookupCode(index, '')).toBeNull();
    });

    it('searches names: those starting with the words first', () => {
        expect(search(index, 'mil').map((p) => p.id)).toEqual([8, 7]);
        expect(search(index, 'm')).toEqual([]);
    });

    it('looks up in well under 5 ms with 2,000 products', () => {
        const big = { products: Array.from({ length: 2000 }, (_, i) => [i + 1, `Product ${i}`, `SKU-${i}`, null, 'Piece', [[i + 1, 'Piece', 1]], [[`6000${i}`, null]]]) };
        const idx = buildIndex(big);

        const started = performance.now();
        for (let i = 0; i < 1000; i++) lookupCode(idx, `6000${(i * 7) % 2000}`);
        const each = (performance.now() - started) / 1000;

        expect(each).toBeLessThan(5);
        expect(lookupCode(idx, '60001999').product.id).toBe(2000);
    });
});
