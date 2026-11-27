import 'fake-indexeddb/auto';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { db } from './db';
import { downloadPack } from './pack';
import { entriesFor, removeCount, saveCount, syncNow, waitingCount } from './outbox';

const TASK = 1;

beforeEach(async () => {
    await Promise.all([db.packs.clear(), db.entries.clear(), db.outbox.clear()]);
});

/** A fake server: answers every op as synced unless told otherwise. */
function server({ reject = {}, failFirst = 0 } = {}) {
    let failures = failFirst;
    const calls = [];
    return {
        calls,
        post: vi.fn(async (url, body) => {
            calls.push(body.ops.map((op) => op.id));
            if (failures > 0) { failures -= 1; throw new Error('Network Error'); }
            return {
                data: {
                    server_time: new Date().toISOString(),
                    results: body.ops.map((op) => (reject[op.product_id]
                        ? { id: op.id, status: 'rejected', reason: reject[op.product_id] }
                        : { id: op.id, status: 'synced', reason: null })),
                },
            };
        }),
    };
}

const piece = (qty) => [{ unit_id: 1, unit: 'Piece', factor: 1, qty }];

describe('saving on the phone', () => {
    it('writes the entry and its operation together, stamped on the server clock', async () => {
        vi.useFakeTimers({ now: Date.parse('2026-11-24T10:00:00Z'), toFake: ['Date'] });

        await saveCount(TASK, 90_000, { aisleId: 2, productId: 7, counted: 12, breakdown: piece(12) });

        const [entry] = await entriesFor(TASK);
        const [row] = await db.outbox.toArray();

        expect(entry).toMatchObject({ counted: 12, syncStatus: 'pending', countedAt: '2026-11-24T10:01:30.000Z', deviceCountedAt: '2026-11-24T10:00:00.000Z' });
        expect(row.op).toMatchObject({ type: 'set', aisle_id: 2, product_id: 7, lines: [{ unit_id: 1, qty: '12' }], counted_at: '2026-11-24T10:01:30.000Z' });
        expect(entry.lastOpId).toBe(row.opId);

        vi.useRealTimers();
    });

    it('a take-back removes the entry here and queues its own operation', async () => {
        await saveCount(TASK, 0, { aisleId: 2, productId: 7, counted: 12, breakdown: piece(12) });
        await removeCount(TASK, 2, 7);

        expect(await entriesFor(TASK)).toEqual([]);
        expect((await db.outbox.toArray()).map((r) => r.op.type)).toEqual(['set', 'remove']);
    });
});

describe('uploading in the background', () => {
    it('sends what is waiting in order, and marks it synced', async () => {
        const api = server();
        await saveCount(TASK, 0, { aisleId: 2, productId: 7, counted: 12, breakdown: piece(12) });
        await saveCount(TASK, 0, { aisleId: 2, productId: 8, counted: 3, breakdown: piece(3) });

        const result = await syncNow(api, TASK);

        expect(result).toMatchObject({ sent: 2, rejected: 0, failed: false });
        expect(await waitingCount(TASK)).toBe(0);
        expect((await entriesFor(TASK)).map((e) => e.syncStatus)).toEqual(['synced', 'synced']);
    });

    it('a failed upload keeps everything; the retry sends the very same operations', async () => {
        const api = server({ failFirst: 1 });
        await saveCount(TASK, 0, { aisleId: 2, productId: 7, counted: 12, breakdown: piece(12) });

        expect(await syncNow(api, TASK)).toMatchObject({ failed: true });
        expect(await waitingCount(TASK)).toBe(1);
        expect((await entriesFor(TASK))[0].syncStatus).toBe('pending');

        await syncNow(api, TASK);

        expect(api.calls[1]).toEqual(api.calls[0]);
        expect(await waitingCount(TASK)).toBe(0);
    });

    it('a refused count stays on the phone, marked, with the reason', async () => {
        const api = server({ reject: { 7: 'Already counted on Tuesday 24 Nov in Aisle 2 — skip.' } });
        await saveCount(TASK, 0, { aisleId: 3, productId: 7, counted: 4, breakdown: piece(4) });

        expect(await syncNow(api, TASK)).toMatchObject({ rejected: 1 });

        expect((await entriesFor(TASK))[0]).toMatchObject({ syncStatus: 'rejected', reason: 'Already counted on Tuesday 24 Nov in Aisle 2 — skip.' });
    });

    it('two saves of one product before an upload: the newer decides how it shows', async () => {
        const api = server();
        await saveCount(TASK, 0, { aisleId: 2, productId: 7, counted: 12, breakdown: piece(12) });
        await saveCount(TASK, 0, { aisleId: 2, productId: 7, counted: 17, breakdown: piece(17) });

        await syncNow(api, TASK);

        const entries = await entriesFor(TASK);
        expect(entries).toHaveLength(1);
        expect(entries[0]).toMatchObject({ counted: 17, syncStatus: 'synced' });
        expect(api.calls[0]).toHaveLength(2);
    });

    it('two uploads started at once send each operation once', async () => {
        const api = server();
        await saveCount(TASK, 0, { aisleId: 2, productId: 7, counted: 12, breakdown: piece(12) });

        await Promise.all([syncNow(api, TASK), syncNow(api, TASK)]);

        expect(api.post).toHaveBeenCalledTimes(1);
    });
});

describe('the task pack', () => {
    const pack = (entries = []) => ({
        v: 1,
        server_time: new Date(Date.now() + 60_000).toISOString(),
        task: { id: TASK, type: 'count', aisles: [], allow_halves: false },
        products: [],
        done: {},
        entries,
    });

    it('brings in what the task already holds on the server, as synced', async () => {
        const api = { get: vi.fn(async () => ({ data: pack([[2, 7, 12, piece(12), '2026-11-24T09:00:00.000Z', false, false]]) })) };

        const { clockOffset } = await downloadPack(api, TASK);

        expect(Math.abs(clockOffset - 60_000)).toBeLessThan(2_000);
        expect((await entriesFor(TASK))[0]).toMatchObject({ aisleId: 2, productId: 7, counted: 12, syncStatus: 'synced' });
    });

    it('a refresh never overwrites a count made here, nor brings back one taken back here', async () => {
        await saveCount(TASK, 0, { aisleId: 2, productId: 7, counted: 30, breakdown: piece(30) });
        await saveCount(TASK, 0, { aisleId: 2, productId: 8, counted: 5, breakdown: piece(5) });
        await removeCount(TASK, 2, 8);

        const api = { get: vi.fn(async () => ({ data: pack([
            [2, 7, 12, piece(12), '2026-11-24T09:00:00.000Z', false, false],
            [2, 8, 5, piece(5), '2026-11-24T09:00:00.000Z', false, false],
        ]) })) };

        await downloadPack(api, TASK);

        const entries = await entriesFor(TASK);
        expect(entries).toHaveLength(1);
        expect(entries[0]).toMatchObject({ productId: 7, counted: 30, syncStatus: 'pending' });
    });
});
