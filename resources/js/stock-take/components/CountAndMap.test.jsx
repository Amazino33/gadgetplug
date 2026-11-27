import 'fake-indexeddb/auto';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, waitFor, cleanup, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

vi.mock('../lib/api', () => ({
    default: {
        get: vi.fn(async () => ({ data: {} })),
        post: vi.fn(async (url, body) => {
            if (url === '/tasks/5/aisles') {
                if (globalThis.noSignal) throw new Error('Network Error');
                return { data: { aisle: { id: 30, name: body.name, label: body.name, quick: false, held_by: null, expected: [] } } };
            }
            if (url.endsWith('/sync')) {
                return { data: { server_time: new Date().toISOString(), results: body.ops.map((op) => ({ id: op.id, status: 'synced', reason: null })) } };
            }
            return { data: {} };
        }),
        delete: vi.fn(async () => ({ data: {} })),
    },
    errorMessage: (e) => e?.response?.data?.message ?? 'error',
}));

vi.mock('../lib/feedback', () => ({ good: vi.fn(), warn: vi.fn(), again: vi.fn(), unknown: vi.fn(), tick: vi.fn(), soundOn: () => true, setSound: vi.fn() }));

import { db, setMeta } from '../offline/db';
import { settleNewAisles, whereKey } from '../offline/pack';
import CountTask, { WHERE_CHECK_MS } from './CountTask';
import api from '../lib/api';

const TASK = 5;
const pack = {
    v: 1,
    task: {
        id: TASK, type: 'count', status: 'assigned', allow_halves: false, recount: null, quick: true,
        aisles: [
            { id: 20, label: 'Ada · 26 Nov', quick: true, held_by: null, expected: [] },
            { id: 4, label: 'Drinks', quick: false, held_by: null, expected: [] },
            { id: 6, label: 'Fridge', quick: false, held_by: 'Bola', expected: [] },
        ],
    },
    products: [[7, 'Coke 50cl', 'COKE', null, 'Piece', [[1, 'Piece', 1]], [['111', null]]]],
    done: {},
    entries: [],
};

const open = () => render(<CountTask taskId={TASK} syncer={{ nudge: vi.fn() }} onBack={() => {}} onSubmitted={() => {}} />);
const dialog = () => screen.queryByRole('dialog', { name: 'Which aisle are you in?' });

beforeEach(async () => {
    vi.clearAllMocks();
    globalThis.noSignal = false;
    await Promise.all([db.entries.clear(), db.outbox.clear(), db.packs.clear(), db.meta.clear()]);
    await db.packs.put({ taskId: TASK, pack, downloadedAt: Date.now(), clockOffset: 0 });
});
afterEach(cleanup);

describe('Count and map in one walk (Quick count)', () => {
    it('asks which aisle first; an aisle on someone else\'s count cannot be picked; then counts go to the aisle picked', async () => {
        open();
        expect(await screen.findByText('Which aisle are you in?')).toBeTruthy();
        expect(screen.getByRole('button', { name: /Fridge/ }).disabled).toBe(true);
        expect(screen.getByText('With Bola')).toBeTruthy();

        await userEvent.click(screen.getByRole('button', { name: /Drinks/ }));

        expect(dialog()).toBeNull();
        expect(await screen.findByRole('button', { name: /Aisle: Drinks/ })).toBeTruthy();
        expect((await db.meta.get(whereKey(TASK))).value.aisleId).toBe(4);

        fireEvent.change(screen.getByLabelText('Barcode'), { target: { value: '111' } });
        fireEvent.submit(screen.getByLabelText('Barcode').closest('form'));
        expect(await screen.findByRole('region', { name: 'Count Coke 50cl' })).toBeTruthy();
    });

    it('"No aisle" is there for counting without saying where', async () => {
        open();
        await screen.findByText('Which aisle are you in?');
        await userEvent.click(screen.getByRole('button', { name: /No aisle \(just count\)/ }));
        expect(await screen.findByRole('button', { name: /Aisle: No aisle/ })).toBeTruthy();
    });

    it('after 30 minutes: "Are you still here?", and scans wait until it is answered', async () => {
        await setMeta(whereKey(TASK), { aisleId: 4, at: Date.now() - WHERE_CHECK_MS - 1000 });
        open();

        expect(await screen.findByText('Are you still here?')).toBeTruthy();
        // Counting underneath is paused: a scan now does nothing.
        fireEvent.change(screen.getByLabelText('Barcode'), { target: { value: '111' } });
        fireEvent.submit(screen.getByLabelText('Barcode').closest('form'));
        expect(screen.queryByRole('region', { name: 'Count Coke 50cl' })).toBeNull();

        await userEvent.click(screen.getByRole('button', { name: 'Yes, still in Drinks' }));
        expect(dialog()).toBeNull();
        const at = (await db.meta.get(whereKey(TASK))).value.at;
        expect(Date.now() - at).toBeLessThan(5000);
    });

    it('an aisle nobody set up is added by name and counted in at once; it stays on the phone', async () => {
        open();
        await screen.findByText('Which aisle are you in?');
        await userEvent.click(screen.getByRole('button', { name: '+ An aisle not on this list' }));
        await userEvent.type(screen.getByLabelText('Name of this aisle'), 'Back store');
        await userEvent.click(screen.getByRole('button', { name: 'Add and count here' }));

        expect(api.post).toHaveBeenCalledWith('/tasks/5/aisles', { name: 'Back store' });
        expect(await screen.findByRole('button', { name: /Aisle: Back store/ })).toBeTruthy();
        await waitFor(async () => {
            const stored = await db.packs.get(TASK);
            expect(stored.pack.task.aisles.map((a) => a.id)).toContain(30);
        });
    });

    it('no signal: the new aisle is made on the phone, counted in at once, and gets its real id when signal returns', async () => {
        globalThis.noSignal = true;
        open();
        await screen.findByText('Which aisle are you in?');
        await userEvent.click(screen.getByRole('button', { name: '+ An aisle not on this list' }));
        await userEvent.type(screen.getByLabelText('Name of this aisle'), 'Back store');
        await userEvent.click(screen.getByRole('button', { name: 'Add and count here' }));

        expect(await screen.findByRole('button', { name: /Aisle: Back store/ })).toBeTruthy();
        const temp = (await db.packs.get(TASK)).pack.task.aisles.find((a) => a.pending);
        expect(temp.id).toBeLessThan(0);

        // A count in it, waiting on the phone.
        fireEvent.change(screen.getByLabelText('Barcode'), { target: { value: '111' } });
        fireEvent.submit(screen.getByLabelText('Barcode').closest('form'));
        await screen.findByRole('region', { name: 'Count Coke 50cl' });
        await userEvent.click(screen.getByRole('button', { name: '4' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save ✓' }));
        await waitFor(async () => expect(await db.entries.where({ taskId: TASK, aisleId: temp.id }).count()).toBe(1));

        // Signal back: created on the server, everything moves to the real id.
        globalThis.noSignal = false;
        expect(await settleNewAisles(api, TASK)).toBe(true);

        const aisles = (await db.packs.get(TASK)).pack.task.aisles;
        expect(aisles.find((a) => a.id === 30)).toMatchObject({ was: temp.id });
        expect(aisles.some((a) => a.id === temp.id)).toBe(false);
        expect(await db.entries.where({ taskId: TASK, aisleId: 30 }).count()).toBe(1);
        expect((await db.outbox.toArray()).map((r) => r.op.aisle_id)).not.toContain(temp.id);
        expect((await db.meta.get(whereKey(TASK))).value.aisleId).toBe(30);
    });

    it('counting in a real aisle of a Quick count: no "not on aisle list", and the header says what was counted here', async () => {
        await setMeta(whereKey(TASK), { aisleId: 4, at: Date.now() });
        open();
        fireEvent.change(await screen.findByLabelText('Barcode'), { target: { value: '111' } });
        fireEvent.submit(screen.getByLabelText('Barcode').closest('form'));
        await screen.findByRole('region', { name: 'Count Coke 50cl' });

        expect(screen.queryByText('Found here, not on aisle list')).toBeNull();
        expect(screen.getByText(/0 here · 0 in all/)).toBeTruthy();
    });

    it('a name that matches nothing says so, and offers to record it', async () => {
        await setMeta(whereKey(TASK), { aisleId: 4, at: Date.now() });
        open();
        await userEvent.type(await screen.findByLabelText('Search by name'), 'Peak');

        expect(await screen.findByText(/No product called/)).toBeTruthy();
        await userEvent.click(screen.getByRole('button', { name: 'Record it' }));
        expect(await screen.findByRole('dialog', { name: 'Not in the system' })).toBeTruthy();
    });

    it('submitting while online goes straight through: never "no signal", ends on "submitted"', async () => {
        await setMeta(whereKey(TASK), { aisleId: 4, at: Date.now() });
        window.confirm = () => true;
        open();
        fireEvent.change(await screen.findByLabelText('Barcode'), { target: { value: '111' } });
        fireEvent.submit(screen.getByLabelText('Barcode').closest('form'));
        await screen.findByRole('region', { name: 'Count Coke 50cl' });
        await userEvent.click(screen.getByRole('button', { name: '3' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save ✓' }));

        await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
        await userEvent.click(await screen.findByRole('button', { name: /Submit count/ }));

        expect(screen.queryByText(/No signal/)).toBeNull();
        expect(await screen.findByText(/This count is submitted/, {}, { timeout: 5000 })).toBeTruthy();
        expect(api.post).toHaveBeenCalledWith('/tasks/5/submit', expect.anything());
    });
});
