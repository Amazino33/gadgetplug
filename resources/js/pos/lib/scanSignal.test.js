import { describe, expect, it } from 'vitest';
import { createBurstTracker, looksLikeBarcode, scanFor } from './scanSignal';

// The rule that lets a scan skip the quantity box. Its permissive ancestor —
// "any result whose barcode OR SKU was typed in full" — is in the repo's
// history for having rung up a product on a single keypress, so the tests
// that matter most here are the ones about what it REFUSES.

const cable   = { id: 2, name: 'Samsung Cable', sku: 'SAM-CB', barcode: '6009876543210' };
// Real shape of this catalogue: imports have left products on single-digit SKUs.
const battery = { id: 3, name: 'BATTERY BL-5C', sku: '5',      barcode: null };
// A Code 39 label — alphanumeric, so the shape test alone will not have it.
const lead    = { id: 4, name: 'Aux Lead',      sku: 'AUX-1',  barcode: 'GP-4471-A' };

describe('looksLikeBarcode', () => {
    it('accepts a printed symbology', () => {
        expect(looksLikeBarcode('6009876543210')).toBe(true);  // EAN-13
        expect(looksLikeBarcode('88070060')).toBe(true);       // EAN-8, the shortest we carry
    });

    it('refuses the short numeric SKUs that caused the original incident', () => {
        expect(looksLikeBarcode('5')).toBe(false);
        expect(looksLikeBarcode('4471')).toBe(false);
    });

    it('refuses anything a person would type at a till', () => {
        expect(looksLikeBarcode('samsung')).toBe(false);
        expect(looksLikeBarcode('SAM-25')).toBe(false);
        expect(looksLikeBarcode('')).toBe(false);
        expect(looksLikeBarcode('   ')).toBe(false);
    });
});

describe('scanFor', () => {
    it('resolves a barcode that exactly matches one product', () => {
        expect(scanFor({ query: '6009876543210', results: [cable] })).toBe(cable);
    });

    it('ignores surrounding whitespace, which a wedge sometimes adds', () => {
        expect(scanFor({ query: '  6009876543210 ', results: [cable] })).toBe(cable);
    });

    it('takes nothing when the text merely resembles a barcode', () => {
        // The digits appear in the name, not in a barcode.
        const named = { id: 9, name: 'Powerbank 10000mAh 6009876543210', barcode: '111' };

        expect(scanFor({ query: '6009876543210', results: [named] })).toBeNull();
    });

    it('never looks at a SKU, however exactly it was typed', () => {
        // This is the reverted rule. "5" is a real SKU and must stay inert.
        expect(scanFor({ query: '5', results: [battery] })).toBeNull();
        expect(scanFor({ query: 'SAM-CB', results: [cable] })).toBeNull();
    });

    it('takes nothing when two products somehow claim one barcode', () => {
        // The unique index forbids it; if it happens anyway the till has no
        // business guessing which one the customer is holding.
        const twin = { id: 5, name: 'Samsung Cable (dup)', barcode: '6009876543210' };

        expect(scanFor({ query: '6009876543210', results: [cable, twin] })).toBeNull();
    });

    it('takes an alphanumeric barcode only when the speed says a machine sent it', () => {
        expect(scanFor({ query: 'GP-4471-A', results: [lead], burst: false })).toBeNull();
        expect(scanFor({ query: 'GP-4471-A', results: [lead], burst: true })).toBe(lead);
    });

    it('will not let speed alone add anything', () => {
        // A fast typist is still not a barcode. Nothing carries this text.
        expect(scanFor({ query: 'samsung', results: [cable], burst: true })).toBeNull();
    });

    it('is safe with nothing to work from', () => {
        expect(scanFor({ query: '', results: [cable] })).toBeNull();
        expect(scanFor({ query: '6009876543210', results: [] })).toBeNull();
        expect(scanFor()).toBeNull();
    });
});

describe('createBurstTracker', () => {
    // Times are handed in rather than read off the clock, so this is
    // arithmetic instead of a race.
    const feed = (tracker, start, gap, count) => {
        let at = start;
        for (let i = 0; i < count; i++) { tracker.record(at); at += gap; }
        return at - gap;
    };

    it('recognises a handheld wedge', () => {
        const tracker = createBurstTracker();
        const last = feed(tracker, 1000, 15, 13);   // EAN-13 at 15ms a character

        expect(tracker.isBurst(last + 15)).toBe(true);
    });

    it('does not mistake a fast typist for one', () => {
        const tracker = createBurstTracker();
        const last = feed(tracker, 1000, 90, 13);

        expect(tracker.isBurst(last + 90)).toBe(false);
    });

    it('refuses a burst the cashier then paused after', () => {
        // Typed quickly, then stopped to read the screen before Enter. That
        // pause is a decision, and a decision is not a scan.
        const tracker = createBurstTracker();
        const last = feed(tracker, 1000, 15, 13);

        expect(tracker.isBurst(last + 800)).toBe(false);
    });

    it('breaks the run when the cashier pauses mid-word', () => {
        const tracker = createBurstTracker();
        feed(tracker, 1000, 15, 10);
        tracker.record(5000);                       // long pause
        const last = feed(tracker, 5015, 15, 3);    // then three quick ones

        expect(tracker.isBurst(last + 15)).toBe(false);
    });

    it('refuses a run too short to be a barcode', () => {
        const tracker = createBurstTracker();
        const last = feed(tracker, 1000, 15, 3);

        expect(tracker.isBurst(last + 15)).toBe(false);
    });

    it('is not a burst before anything has been typed', () => {
        expect(createBurstTracker().isBurst(1000)).toBe(false);
    });

    it('forgets everything on reset, so one scan cannot vouch for the next', () => {
        const tracker = createBurstTracker();
        const last = feed(tracker, 1000, 15, 13);
        tracker.reset();

        expect(tracker.isBurst(last + 15)).toBe(false);
    });
});
