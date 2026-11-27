import { describe, it, expect } from 'vitest';
import { baseTotal, fromBreakdown, parseQty, qtyError, toLines, unitRows, describe as describeLines, anyTyped } from './units';

const coke = {
    base_unit: 'Piece',
    units: [
        { id: 1, name: 'Piece', factor: 1, is_base: true },
        { id: 3, name: 'Crate', factor: 12, is_base: false },
    ],
};

describe('counting in units', () => {
    it('shows the biggest unit first', () => {
        expect(unitRows(coke).map((u) => u.name)).toEqual(['Crate', 'Piece']);
    });

    it('3 crates + 5 pieces is 41 pieces', () => {
        const rows = unitRows(coke);
        const values = { 3: '3', 1: '5' };
        expect(baseTotal(rows, values)).toBe(41);
        expect(toLines(rows, values)).toEqual([{ unit_id: 3, qty: '3' }, { unit_id: 1, qty: '5' }]);
    });

    it('a typed 0 is a count; nothing typed is not', () => {
        const rows = unitRows(coke);
        expect(anyTyped(rows, {})).toBe(false);
        expect(anyTyped(rows, { 1: '0' })).toBe(true);
        expect(baseTotal(rows, { 1: '0' })).toBe(0);
    });

    it('halves only when the shop sells them', () => {
        expect(qtyError('1.5', false)).toMatch(/Whole units/);
        expect(qtyError('1.5', true)).toBeNull();
        expect(qtyError('1½', true)).toBeNull();
        expect(parseQty('1½')).toBe(1.5);
        expect(qtyError('1.25', true)).toMatch(/half/);
        expect(qtyError('-2', true)).toMatch(/0 or more/);
    });

    it('a product with no units counts in its base unit', () => {
        const rows = unitRows({ base_unit: 'Bag', units: [] });
        expect(rows).toEqual([{ id: null, name: 'Bag', factor: 1, is_base: true }]);
        expect(toLines(rows, { base: '4' })).toEqual([{ unit_id: null, qty: '4' }]);
    });

    it('a saved count comes back into the boxes for editing', () => {
        const rows = unitRows(coke);
        const values = fromBreakdown(rows, [{ unit_id: 3, unit: 'Crate', factor: 12, qty: 2 }, { unit_id: 1, unit: 'Piece', factor: 1, qty: 7 }]);
        expect(values).toEqual({ 3: '2', 1: '7' });
        expect(describeLines([{ unit: 'Crate', qty: 2 }, { unit: 'Piece', qty: 7 }])).toBe('2 Crate + 7 Piece');
    });
});
