import { describe, it, expect, vi, beforeEach } from 'vitest';
import { reportTillState, resetTillState, summarise } from './tillState';

describe('till state report', () => {
    beforeEach(() => { resetTillState(); localStorage.clear(); });

    it('groups unsynced sales by branch with the oldest of each', () => {
        expect(summarise([
            { store_id: 2, completed_at: '2026-11-24T10:05:00Z' },
            { store_id: 2, completed_at: '2026-11-24T09:55:00Z' },
            { store_id: null, completed_at: '2026-11-24T11:00:00Z' },
        ])).toEqual([
            { store_id: 2, count: 2, oldest_at: '2026-11-24T09:55:00Z' },
            { store_id: null, count: 1, oldest_at: '2026-11-24T11:00:00Z' },
        ]);
    });

    it('sends when it changes, and again after five minutes, with a lasting device id', async () => {
        const api = { post: vi.fn(async () => ({ data: {} })) };

        expect(await reportTillState(api, 1, [], 0)).toBe(true);
        expect(await reportTillState(api, 1, [], 1000)).toBe(false);
        expect(await reportTillState(api, 1, [{ store_id: 2, completed_at: 'x' }], 2000)).toBe(true);
        expect(await reportTillState(api, 1, [{ store_id: 2, completed_at: 'x' }], 2000 + 5 * 60 * 1000)).toBe(true);

        const ids = api.post.mock.calls.map(([, body]) => body.device_id);
        expect(new Set(ids).size).toBe(1);
        expect(api.post.mock.calls[0][0]).toBe('/till-state');
    });
});
