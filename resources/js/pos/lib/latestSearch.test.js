import { describe, expect, it } from 'vitest';
import { createLatestSearch } from './latestSearch';

// A cashier types faster than either the local lookup or the network fallback
// can answer. Two attempts end up in flight at once, and they don't finish in
// the order they started — this is what decides which one is allowed to touch
// the screen.

describe('createLatestSearch', () => {
    it('says the only attempt started is current', () => {
        const search = createLatestSearch();

        const token = search.start();

        expect(search.isCurrent(token)).toBe(true);
    });

    it('demotes an earlier attempt the moment a newer one starts', () => {
        const search = createLatestSearch();

        const first = search.start();
        const second = search.start();

        expect(search.isCurrent(first)).toBe(false);
        expect(search.isCurrent(second)).toBe(true);
    });

    it('is unaffected by the order the attempts actually finish in', () => {
        // "bat" is typed after "b", but its local lookup — much shorter —
        // resolves first. That must not make "b" current again once "bat"
        // has already started.
        const search = createLatestSearch();

        const forB   = search.start();
        const forBat = search.start();

        // "bat"'s local answer lands first.
        expect(search.isCurrent(forBat)).toBe(true);

        // "b"'s slower network fallback finally lands after it.
        expect(search.isCurrent(forB)).toBe(false);
    });

    it('never lets a stale token become current again', () => {
        const search = createLatestSearch();

        const first = search.start();
        search.start();
        search.start();

        expect(search.isCurrent(first)).toBe(false);
    });
});
