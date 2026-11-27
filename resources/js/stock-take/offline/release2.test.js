import 'fake-indexeddb/auto';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { db, getMeta } from './db';
import { downloadPack } from './pack';
import { handInState, queueHandIn, saveCount, syncNow, waitingCount } from './outbox';
import { statusOf } from '../lib/syncStatus';
import { missingFor, stillToCount } from '../lib/missing';
import { adviceFor } from '../components/NeedsAttention';

const TASK = 3;
const piece = (qty) => [{ unit_id: 1, unit: 'Piece', factor: 1, qty }];

beforeEach(async () => {
    await Promise.all([db.packs.clear(), db.entries.clear(), db.outbox.clear(), db.meta.where('key').notEqual('deviceId').delete()]);
});

const ok = (body) => ({ data: { server_time: new Date().toISOString(), results: body.ops.map((op) => ({ id: op.id, status: 'synced', reason: null })) } });

describe('one phone per task (A7)', () => {
    it('every upload says which phone it is and how much it still has waiting', async () => {
        await saveCount(TASK, 0, { aisleId: 1, productId: 1, counted: 1, breakdown: piece(1) });
        await saveCount(TASK, 0, { aisleId: 1, productId: 2, counted: 2, breakdown: piece(2) });
        const api = { post: vi.fn(async (url, body) => ok(body)) };

        await syncNow(api, TASK);

        const [, body] = api.post.mock.calls[0];
        expect(body.device).toMatch(/^phone-/);
        expect(body.waiting).toBe(2);
    });

    it('the pack download says which phone; another phone holding it comes back as other_phone', async () => {
        const api = { get: vi.fn(async () => { const e = new Error('409'); e.response = { status: 409, data: { message: 'On another phone.' } }; throw e; }) };

        await expect(downloadPack(api, TASK)).rejects.toMatchObject({ code: 'other_phone' });
        expect(api.get.mock.calls[0][1].params.device).toMatch(/^phone-/);
    });

    it('an upload refused because another phone holds the task keeps everything and says so', async () => {
        await saveCount(TASK, 0, { aisleId: 1, productId: 1, counted: 1, breakdown: piece(1) });
        const api = { post: vi.fn(async () => { const e = new Error('409'); e.response = { status: 409, data: { message: 'On another phone.' } }; throw e; }) };

        expect(await syncNow(api, TASK)).toMatchObject({ failed: true, blocked: true });
        expect(await waitingCount(TASK)).toBe(1);
        expect(await getMeta(`blocked:${TASK}`)).toBe('On another phone.');
    });
});

describe('handing in offline (A6)', () => {
    it('the hand-in goes last, after every count before it', async () => {
        await saveCount(TASK, 0, { aisleId: 1, productId: 1, counted: 1, breakdown: piece(1) });
        await queueHandIn(TASK, { 5: 'skip' });
        await saveCount(TASK, 0, { aisleId: 1, productId: 2, counted: 2, breakdown: piece(2) });
        const order = [];
        const api = {
            post: vi.fn(async (url, body) => {
                order.push(url.endsWith('/submit') ? 'submit' : body.ops.map((op) => op.product_id).join(','));
                return url.endsWith('/submit') ? { data: { status: 'submitted' } } : ok(body);
            }),
        };

        expect(await syncNow(api, TASK)).toMatchObject({ handedIn: true });
        // The hand-in was queued between the two counts: what came before it
        // goes first, then the hand-in. (A count made after hand-in is refused
        // by the server like any other on a handed-in task.)
        expect(order).toEqual(['1', 'submit']);
    });

    it('handing in again replaces the one waiting', async () => {
        await queueHandIn(TASK, { 5: 'skip' });
        await queueHandIn(TASK, { 5: 'not_on_shelf' });
        const rows = (await db.outbox.toArray()).filter((r) => r.op.type === 'submit');
        expect(rows).toHaveLength(1);
        expect(rows[0].op.decisions).toEqual({ 5: 'not_on_shelf' });
    });

    it('a refused hand-in stops waiting and says why', async () => {
        await queueHandIn(TASK, {});
        const api = { post: vi.fn(async () => { const e = new Error('422'); e.response = { status: 422, data: { errors: { decisions: ['Say for each one: Fanta'] } } }; throw e; }) };

        expect(await syncNow(api, TASK)).toMatchObject({ handInRefused: true });
        expect(await db.outbox.count()).toBe(0);
        expect(await handInState(TASK)).toMatchObject({ status: 'refused', reason: 'Say for each one: Fanta' });
    });

    it('no signal: the hand-in waits', async () => {
        await queueHandIn(TASK, {});
        const api = { post: vi.fn(async () => { throw new Error('Network Error'); }) };

        expect(await syncNow(api, TASK)).toMatchObject({ failed: true, network: true });
        expect(await handInState(TASK)).toMatchObject({ status: 'queued' });
    });
});

describe('the sync chip (A9)', () => {
    const base = { waiting: 0, rejected: [], handIn: null, blocked: null, last: null };

    it('all saved, waiting, offline, needs attention', () => {
        expect(statusOf(base).kind).toBe('saved');
        expect(statusOf({ ...base, waiting: 14 }).kind).toBe('waiting');
        expect(statusOf({ ...base, waiting: 14 }, false).kind).toBe('offline');
        expect(statusOf({ ...base, waiting: 3, last: { network: true } }).kind).toBe('offline');
        expect(statusOf({ ...base, waiting: 3, rejected: [{}, {}] })).toMatchObject({ kind: 'attention', attention: 2 });
        expect(statusOf({ ...base, handIn: { status: 'refused' } }).kind).toBe('attention');
        expect(statusOf({ ...base, handIn: { status: 'queued' } }).kind).toBe('waiting');
    });
});

describe('what is missing, on the phone', () => {
    const pack = {
        task: { type: 'count', aisles: [{ id: 1, expected: [10, 11, 12] }, { id: 2, expected: [12, 13] }] },
        done: { 13: ['Tue', ['A2']] },
    };

    it('expected on the task\'s aisles, less done elsewhere, less counted; a refused count is not counted', () => {
        const entries = [
            { productId: 10, aisleId: 1, syncStatus: 'synced' },
            { productId: 11, aisleId: 2, syncStatus: 'rejected' },
        ];
        expect(missingFor(pack, entries)).toEqual([11, 12]);
        expect(stillToCount(pack, 1, entries)).toEqual([11, 12]);
    });

    it('a recount: the products to recount', () => {
        expect(missingFor({ task: { type: 'recount', aisles: [], recount: [4, 5] } }, [{ productId: 5 }])).toEqual([4]);
    });
});

describe('what to do about a refused count', () => {
    it('reads the reason', () => {
        expect(adviceFor('Already counted on Tuesday in Aisle 2 — skip')).toMatch('Take this count back');
        expect(adviceFor('This task is no longer open for you. Ask the manager.')).toMatch('Ask the manager');
        expect(adviceFor('Whole units only.')).toMatch('whole units');
        expect(adviceFor('Something else')).toMatch('Count it again, or take it back');
    });
});
