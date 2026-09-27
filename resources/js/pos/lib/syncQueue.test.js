import { describe, expect, it } from 'vitest';
import { salesToSync } from './syncQueue';

describe('salesToSync', () => {
    it("sends the signed-in cashier's own sales", () => {
        const mine = { offline_id: 'a', cashier_id: 40 };

        expect(salesToSync([mine], 40)).toEqual([mine]);
    });

    it("holds back a sale another cashier rang on this till", () => {
        expect(salesToSync([{ offline_id: 'b', cashier_id: 7 }], 40)).toEqual([]);
    });

    it('still sends a sale queued before the till recorded who rang it', () => {
        const legacy = { offline_id: 'c' };

        expect(salesToSync([legacy], 40)).toEqual([legacy]);
    });

    it('leaves out a sale the server already refused', () => {
        expect(salesToSync([
            { offline_id: 'd', cashier_id: 40, sync_status: 'rejected' },
            { offline_id: 'e', cashier_id: 40, sync_status: 'error' },
        ], 40)).toEqual([]);
    });
});
