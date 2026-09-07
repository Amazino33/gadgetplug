import { describe, expect, it, vi } from 'vitest';
import { createCheckoutId } from './checkoutId';

// A cashier on a slow connection presses the payment button again because
// nothing on screen changed. Every one of those presses has to reach the server
// carrying the same id, or the server cannot tell a retry from a new sale — and
// rings up the goods twice.

const sequentialIds = () => {
    let n = 0;

    return () => `id-${++n}`;
};

describe('createCheckoutId', () => {
    it('gives every attempt at one checkout the same id', () => {
        const checkout = createCheckoutId(sequentialIds());

        expect(checkout.forAttempt()).toBe('id-1');
        expect(checkout.forAttempt()).toBe('id-1');
        expect(checkout.forAttempt()).toBe('id-1');
    });

    it('generates the id once, however many attempts are made', () => {
        const generate = vi.fn(sequentialIds());
        const checkout = createCheckoutId(generate);

        checkout.forAttempt();
        checkout.forAttempt();
        checkout.forAttempt();
        checkout.forAttempt();

        expect(generate).toHaveBeenCalledTimes(1);
    });

    it('starts a fresh id once a sale has landed', () => {
        const checkout = createCheckoutId(sequentialIds());

        expect(checkout.forAttempt()).toBe('id-1');
        checkout.settled();

        expect(checkout.forAttempt()).toBe('id-2');
    });

    it('keeps the id when a sale has not landed, so a retry is still the same sale', () => {
        const checkout = createCheckoutId(sequentialIds());

        // Refused by the server, or queued offline — either way the same
        // attempt. Handing the retry a new id is what would duplicate it.
        expect(checkout.forAttempt()).toBe('id-1');
        expect(checkout.forAttempt()).toBe('id-1');

        checkout.settled();
        expect(checkout.forAttempt()).toBe('id-2');
        expect(checkout.forAttempt()).toBe('id-2');
    });

    it('has no id before the first attempt', () => {
        const checkout = createCheckoutId(sequentialIds());

        expect(checkout.peek()).toBeNull();

        checkout.forAttempt();
        expect(checkout.peek()).toBe('id-1');

        checkout.settled();
        expect(checkout.peek()).toBeNull();
    });

    it('keeps two tills apart', () => {
        const one = createCheckoutId(sequentialIds());
        const two = createCheckoutId(sequentialIds());

        expect(one.forAttempt()).toBe('id-1');
        expect(two.forAttempt()).toBe('id-1');

        one.settled();

        // One till finishing a sale must not renumber the other's open checkout.
        expect(one.forAttempt()).toBe('id-2');
        expect(two.forAttempt()).toBe('id-1');
    });
});
