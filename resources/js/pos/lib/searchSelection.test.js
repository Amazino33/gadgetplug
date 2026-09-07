import { describe, expect, it } from 'vitest';
import { selectionForEnter } from './searchSelection';

// The rule that decides whether a keypress is allowed to put goods in a
// customer's basket. Every permissive version of this rule has, in
// production, charged someone for a product nobody picked.

const charger = { id: 1, name: 'Samsung 25W Charger', sku: 'SAM-25', barcode: '6001234567890' };
const cable   = { id: 2, name: 'Samsung Cable',       sku: 'SAM-CB', barcode: '6009876543210' };
// Real shape of this catalogue: imports have left products on single-digit SKUs.
const battery = { id: 3, name: 'BATTERY BL-5C',       sku: '5',      barcode: null };

describe('selectionForEnter', () => {
    it('takes the row the cashier arrowed onto', () => {
        const picked = selectionForEnter({ results: [charger, cable], activeIndex: 1, query: 'sam' });

        expect(picked).toBe(cable);
    });

    it('takes nothing when a lone result was never pointed at', () => {
        // One result on screen, and the phone keyboard's Go key sends Enter.
        const picked = selectionForEnter({ results: [charger], activeIndex: -1, query: 'sam' });

        expect(picked).toBeNull();
    });

    it('takes nothing when a single digit happens to be a real product SKU', () => {
        // The one that bit us: pressing "5" produced a product and a quantity
        // prompt, because a product really is called SKU "5".
        const picked = selectionForEnter({ results: [battery], activeIndex: -1, query: '5' });

        expect(picked).toBeNull();
    });

    it('takes nothing for a full barcode either', () => {
        const picked = selectionForEnter({ results: [cable], activeIndex: -1, query: '6009876543210' });

        expect(picked).toBeNull();
    });

    it('takes nothing for a fully typed name', () => {
        const picked = selectionForEnter({ results: [charger], activeIndex: -1, query: 'Samsung 25W Charger' });

        expect(picked).toBeNull();
    });

    it('takes nothing when there is nothing to take', () => {
        expect(selectionForEnter({ results: [], activeIndex: -1, query: 'sam' })).toBeNull();
        expect(selectionForEnter()).toBeNull();
    });

    it('ignores a highlight that no longer points at a row', () => {
        // The list shrank under the cashier as a newer search landed.
        const picked = selectionForEnter({ results: [charger], activeIndex: 4, query: 'sam' });

        expect(picked).toBeNull();
    });
});
