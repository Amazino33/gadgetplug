import { describe, expect, it } from 'vitest';
import { selectionForEnter } from './searchSelection';

// The rule that decides whether a keypress is allowed to put goods in a
// customer's basket. Getting it wrong in the permissive direction charges
// someone for a product nobody picked.

const charger = { id: 1, name: 'Samsung 25W Charger', sku: 'SAM-25', barcode: '6001234567890' };
const cable   = { id: 2, name: 'Samsung Cable',       sku: 'SAM-CB', barcode: '6009876543210' };

describe('selectionForEnter', () => {
    it('takes the row the cashier arrowed onto', () => {
        const picked = selectionForEnter({ results: [charger, cable], activeIndex: 1, query: 'sam' });

        expect(picked).toBe(cable);
    });

    it('takes nothing when a lone result was never pointed at', () => {
        // The exact case behind "it picks on its own": one result on screen,
        // and the phone keyboard's Go key sends Enter.
        const picked = selectionForEnter({ results: [charger], activeIndex: -1, query: 'sam' });

        expect(picked).toBeNull();
    });

    it('takes the product whose full barcode was entered, which is what a scanner sends', () => {
        const picked = selectionForEnter({ results: [charger, cable], activeIndex: -1, query: '6009876543210' });

        expect(picked).toBe(cable);
    });

    it('takes the product whose full SKU was entered, whatever the casing', () => {
        const picked = selectionForEnter({ results: [charger, cable], activeIndex: -1, query: 'sam-cb' });

        expect(picked).toBe(cable);
    });

    it('takes nothing for a partial identifier', () => {
        // Half a scanned barcode, or a cashier still typing.
        expect(selectionForEnter({ results: [charger], activeIndex: -1, query: '600123' })).toBeNull();
        expect(selectionForEnter({ results: [charger], activeIndex: -1, query: 'SAM' })).toBeNull();
    });

    it('takes nothing for a name, however exactly it is typed', () => {
        const picked = selectionForEnter({ results: [charger], activeIndex: -1, query: 'Samsung 25W Charger' });

        expect(picked).toBeNull();
    });

    it('takes nothing on an empty or blank query', () => {
        expect(selectionForEnter({ results: [charger], activeIndex: -1, query: '' })).toBeNull();
        expect(selectionForEnter({ results: [charger], activeIndex: -1, query: '   ' })).toBeNull();
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
