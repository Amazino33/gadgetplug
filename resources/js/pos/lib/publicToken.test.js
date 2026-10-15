import { describe, expect, it } from 'vitest';
import { isPublicToken, newPublicToken, TOKEN_LENGTH } from './publicToken';

describe('newPublicToken', () => {
    it('is the 16 letters and digits the server accepts', () => {
        for (let i = 0; i < 50; i++) {
            const token = newPublicToken();

            expect(token).toHaveLength(TOKEN_LENGTH);
            expect(token).toMatch(/^[A-Za-z0-9]{16}$/);
            expect(isPublicToken(token)).toBe(true);
        }
    });

    it('does not repeat', () => {
        expect(new Set(Array.from({ length: 200 }, () => newPublicToken())).size).toBe(200);
    });

    it('draws from the secure random source it is given, and skips biased bytes', () => {
        // 255 is above the unbiased limit (248), so it must be discarded.
        const bytes = [255, 255, ...Array(40).fill(0)];
        const random = { getRandomValues: (a) => { a.set(bytes.slice(0, a.length)); return a; } };

        expect(newPublicToken(random)).toBe('A'.repeat(16));
    });

    it('refuses to guess when there is no secure source', () => {
        expect(() => newPublicToken({})).toThrow();
    });
});
