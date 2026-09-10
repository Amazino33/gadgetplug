import { describe, expect, it } from 'vitest';
import { highlightIndexFor, selectionForEnter } from './searchSelection';

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

// The desktop till only. A phone keyboard's Go key sends Enter, which is what
// made every one of the tests above necessary; a hardware keyboard has no
// gesture that sends Enter meaning something other than Enter.
describe('selectionForEnter with autoHighlight', () => {
    const on = (over) => selectionForEnter({ autoHighlight: true, ...over });

    it('takes the first match without anyone arrowing onto it', () => {
        expect(on({ results: [charger, cable], query: 'sam' })).toBe(charger);
    });

    it('still prefers the row the cashier arrowed onto', () => {
        expect(on({ results: [charger, cable], activeIndex: 1, query: 'sam' })).toBe(cable);
    });

    it('still takes nothing when a single digit happens to be a real product SKU', () => {
        // The incident this whole file exists for. Auto-highlight does not
        // reopen it: one character is below the floor, so there is no
        // highlight to commit.
        expect(on({ results: [battery], query: '5' })).toBeNull();
    });

    it('takes nothing until there is enough text to have meant something', () => {
        expect(on({ results: [charger], query: 'sa' })).toBeNull();
        expect(on({ results: [charger], query: 'sam' })).toBe(charger);
    });

    it('takes nothing when nothing matched', () => {
        expect(on({ results: [], query: 'samsung' })).toBeNull();
    });
});

describe('highlightIndexFor', () => {
    // What the list draws and what Enter takes read the same function, so
    // that a result set replaced mid-keystroke cannot make them disagree.
    it('reports no highlight for a bar that does not auto-highlight', () => {
        expect(highlightIndexFor({ results: [charger, cable], query: 'sam' })).toBe(-1);
    });

    it('reports the first row for one that does', () => {
        expect(highlightIndexFor({ results: [charger, cable], query: 'sam', autoHighlight: true })).toBe(0);
    });

    it('drops a stale arrow highlight back onto the first row of the new list', () => {
        // The server's answer landed and the list shrank underneath. The
        // cashier sees row 0 highlighted, so row 0 is what Enter must take —
        // not row 3 of a list that no longer exists.
        const state = { results: [charger], activeIndex: 3, query: 'sam', autoHighlight: true };

        expect(highlightIndexFor(state)).toBe(0);
        expect(selectionForEnter(state)).toBe(charger);
    });
});
