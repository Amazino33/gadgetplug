import { describe, expect, it } from 'vitest';
import { shouldRedirectTypingToSearch } from './typeAhead';

// The cashier types a product name without clicking anywhere first. The cost
// of getting this wrong in the greedy direction is characters disappearing
// out of whatever field someone was actually filling in.

const press = (over = {}) => shouldRedirectTypingToSearch({ key: 'a', activeTag: 'BODY', ...over });

describe('shouldRedirectTypingToSearch', () => {
    it('sends an ordinary character typed on the page into the search box', () => {
        expect(press({ key: 's' })).toBe(true);
        expect(press({ key: '5' })).toBe(true);
        expect(press({ key: '-' })).toBe(true);
    });

    it('leaves a field that already has focus alone', () => {
        // The quantity box, a customer's name, a discount amount — those
        // keystrokes belong to whoever asked for them.
        expect(press({ activeTag: 'INPUT' })).toBe(false);
        expect(press({ activeTag: 'TEXTAREA' })).toBe(false);
        expect(press({ activeTag: 'SELECT' })).toBe(false);
        expect(press({ activeTag: 'input' })).toBe(false);
    });

    it('keeps out of the way while something is covering the till', () => {
        // A modal, a receipt, an error, a sale being recorded.
        expect(press({ blocked: true })).toBe(false);
    });

    it('leaves shortcuts and named keys to their own handlers', () => {
        expect(press({ key: 'Enter' })).toBe(false);
        expect(press({ key: 'Escape' })).toBe(false);
        expect(press({ key: 'Tab' })).toBe(false);
        expect(press({ key: 'F3' })).toBe(false);
        expect(press({ key: 'ArrowDown' })).toBe(false);
        expect(press({ key: 'Backspace' })).toBe(false);
    });

    it('leaves anything held with a modifier alone', () => {
        // Ctrl+P to print, Cmd+R to reload, Alt+Tab away.
        expect(press({ key: 'p', ctrlKey: true })).toBe(false);
        expect(press({ key: 'r', metaKey: true })).toBe(false);
        expect(press({ key: 'a', altKey: true })).toBe(false);
    });

    it('still redirects a shifted character, which is just typing', () => {
        expect(press({ key: 'S', shiftKey: true })).toBe(true);
    });

    it('is safe with nothing focused at all', () => {
        expect(press({ activeTag: null })).toBe(true);
    });
});
