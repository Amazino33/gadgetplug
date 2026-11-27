import 'fake-indexeddb/auto';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, waitFor, cleanup, fireEvent, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const apiCalls = { get: 0, post: 0 };

vi.mock('../lib/api', () => ({
    default: {
        get: vi.fn(async () => { apiCalls.get += 1; return { data: { missing: [{ id: 9, name: 'Fanta' }, { id: 10, name: 'Sprite' }] } }; }),
        post: vi.fn(async (url, body) => {
            apiCalls.post += 1;
            if (url.endsWith('/sync')) {
                return { data: { server_time: new Date().toISOString(), results: body.ops.map((op) => ({ id: op.id, status: 'synced', reason: null })) } };
            }
            return { data: { status: 'submitted' } };
        }),
        delete: vi.fn(async () => ({ data: {} })),
    },
    errorMessage: (e) => e?.response?.data?.message ?? 'error',
}));

vi.mock('../lib/feedback', () => ({ good: vi.fn(), warn: vi.fn(), again: vi.fn(), unknown: vi.fn(), tick: vi.fn(), soundOn: () => true, setSound: vi.fn() }));

import { db } from '../offline/db';
import { buildIndex } from '../offline/lookup';
import { entriesFor, handInState, saveCount, syncNow } from '../offline/outbox';
import * as feedback from '../lib/feedback';
import CountScreen from './CountScreen';
import SubmitReview, { OFFLINE_HAND_IN } from './SubmitReview';

const TASK = 5;
const makePack = (overrides = {}) => ({
    v: 1,
    task: {
        id: TASK, type: 'count', allow_halves: false, recount: null,
        aisles: [{ id: 4, label: 'Drinks', expected: [7] }],
        ...overrides,
    },
    products: [
        [7, 'Coke 50cl', 'COKE', null, 'Piece', [[3, 'Crate', 12], [1, 'Piece', 1]], [['111', null], ['111C', 3]]],
        [8, 'Fanta 50cl', 'FANTA', null, 'Piece', [[2, 'Piece', 1]], [['222', null]]],
        [9, 'Peak Milk', 'PEAK', null, 'Piece', [[4, 'Piece', 1]], [['333', null]]],
        [11, 'Sprite 50cl', 'SPRITE', null, 'Piece', [[5, 'Piece', 1]], [['444', null]]],
        [12, 'Malta', 'MALTA', null, 'Piece', [[6, 'Piece', 1]], [['555', null]]],
    ],
    done: { 9: ['Tuesday 24 Nov', ['Aisle 2']] },
    entries: [],
});
const pack = makePack();
const aisle = { id: 4, label: 'Drinks' };

const session = (p = pack) => ({
    taskId: TASK, pack: p, index: buildIndex(p), clockOffset: 0, syncer: { nudge: vi.fn() }, version: 0,
});

const show = (p = pack) => render(<CountScreen session={session(p)} aisle={aisle} aisles={p.task.aisles} onBack={() => {}} />);

/** A barcode from the hand-held scanner, or pasted: arrives all at once. */
const scan = (code) => {
    fireEvent.change(screen.getByLabelText('Barcode'), { target: { value: code } });
    fireEvent.submit(screen.getByLabelText('Barcode').closest('form'));
};

const keys = async (...pressed) => {
    for (const k of pressed) await userEvent.click(screen.getByRole('button', { name: k }));
};

const panel = (name) => screen.findByRole('region', { name: `Count ${name}` });
const saved = async () => entriesFor(TASK);

beforeEach(async () => {
    apiCalls.get = 0;
    apiCalls.post = 0;
    vi.clearAllMocks();
    await Promise.all([db.entries.clear(), db.outbox.clear(), db.packs.clear()]);
});
afterEach(cleanup);

describe('Counting an aisle: the keypad', () => {
    it('scan, 3 crates and 5 pieces on the keypad, Save: saved on the phone with no call to the server', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('111');
        await panel('Coke 50cl');
        expect(screen.getByRole('button', { name: 'Count in Piece' }).getAttribute('aria-pressed')).toBe('true');

        await userEvent.click(screen.getByRole('button', { name: 'Count in Crate' }));
        await keys('3');
        await userEvent.click(screen.getByRole('button', { name: 'Count in Piece' }));
        await keys('5');
        expect(screen.getByRole('button', { name: 'Count in Crate' }).textContent).toBe('Crate ×123');
        expect(screen.getByRole('button', { name: 'Count in Piece' }).textContent).toBe('Piece5');
        expect(screen.getByTestId('in-words').textContent).toBe('3 Crates + 5 Pieces = 41 Pieces');

        await keys('Save ✓');
        expect(screen.queryByRole('region', { name: 'Count Coke 50cl' })).toBeNull();

        await waitFor(async () => expect((await saved()).length).toBe(1));
        const [entry] = await saved();
        expect(entry).toMatchObject({ aisleId: 4, productId: 7, counted: 41, syncStatus: 'pending' });
        expect(entry.breakdown).toEqual([
            { unit_id: 3, unit: 'Crate', factor: 12, qty: 3 },
            { unit_id: 1, unit: 'Piece', factor: 1, qty: 5 },
        ]);
        expect(apiCalls.post + apiCalls.get).toBe(0);
        expect(screen.getByRole('status').textContent).toMatch('Coke 50cl · 3 Crates + 5 Pieces = 41 Pieces saved');
        expect(await screen.findByText(/waiting to upload/)).toBeTruthy();
    });

    it('a crate\'s own barcode opens the product with Crate picked', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('111C');
        await panel('Coke 50cl');

        expect(screen.getByRole('button', { name: 'Count in Crate' }).getAttribute('aria-pressed')).toBe('true');
        await keys('2', 'Save ✓');
        await waitFor(async () => expect((await saved())[0]?.counted).toBe(24));
    });

    it('a half key only where the shop counts halves', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);
        scan('222');
        await panel('Fanta 50cl');
        expect(screen.queryByRole('button', { name: '½' })).toBeNull();
        cleanup();

        const halves = makePack({ allow_halves: true });
        show(halves);
        await screen.findByText(/Nothing counted yet/);
        scan('222');
        await panel('Fanta 50cl');
        await keys('2', '½');
        expect(screen.getByText('= 2½ Pieces')).toBeTruthy();
        await keys('Save ✓');
        await waitFor(async () => expect((await saved())[0]?.counted).toBe(2.5));
    });
});

describe('Counted before on this aisle: inline Add or Replace', () => {
    beforeEach(async () => {
        await saveCount(TASK, 0, { aisleId: 4, productId: 7, counted: 12, breakdown: [{ unit_id: 1, unit: 'Piece', factor: 1, qty: 12 }] });
    });

    it('says so in the panel, with no pop-up, and typing adds', async () => {
        show();
        await screen.findByText('Coke 50cl');

        scan('111');
        const card = await panel('Coke 50cl');
        expect(within(card).getByText(/Already counted/).textContent).toMatch('Already counted 12');
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(feedback.again).toHaveBeenCalled();

        await keys('5');
        expect(screen.getByTestId('before').textContent).toBe('12 + 5 = 17');
        await keys('Save ✓');

        await waitFor(async () => expect((await saved())[0].counted).toBe(17));
    });

    it('Replace puts the new number in place of the old', async () => {
        show();
        await screen.findByText('Coke 50cl');

        scan('111');
        await panel('Coke 50cl');
        await keys('Replace', '5', 'Save ✓');

        await waitFor(async () => expect((await saved())[0].counted).toBe(5));
    });

    it('Undo after Add puts the first count back, with its own time', async () => {
        const [before] = await saved();
        show();
        await screen.findByText('Coke 50cl');

        scan('111');
        await panel('Coke 50cl');
        await keys('5', 'Save ✓');
        await waitFor(async () => expect((await saved())[0].counted).toBe(17));

        await userEvent.click(screen.getByRole('button', { name: 'Undo' }));

        await waitFor(async () => expect((await saved())[0].counted).toBe(12));
        expect((await saved())[0].countedAt).toBe(before.countedAt);
    });
});

describe('Scan next to save', () => {
    it('with a number typed, scanning the next product saves this one and opens that one', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('111');
        await panel('Coke 50cl');
        await keys('1', '2');

        scan('222');
        await panel('Fanta 50cl');

        await waitFor(async () => expect((await saved()).find((e) => e.productId === 7)?.counted).toBe(12));
        expect(screen.getByRole('status').textContent).toMatch('Coke 50cl · 12 Pieces saved');
    });

    it('with nothing typed, the next scan just moves on', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('111');
        await panel('Coke 50cl');
        scan('222');
        await panel('Fanta 50cl');

        expect(await saved()).toEqual([]);
    });

    it('the open product seen again is ignored (the camera reading it twice)', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('111');
        await panel('Coke 50cl');
        await keys('4');
        scan('111');

        expect(screen.getByText('= 4 Pieces')).toBeTruthy();
        expect(await saved()).toEqual([]);
    });
});

describe('Tap-to-count', () => {
    it('scanning the same product 7 times counts 7', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);
        await userEvent.click(screen.getByRole('button', { name: 'Tap-count' }));

        for (let i = 0; i < 7; i++) scan('222');
        await panel('Fanta 50cl');
        expect(screen.getByTestId('tap-total').textContent).toBe('7');

        await keys('Save ✓');
        await waitFor(async () => expect((await saved())[0]?.counted).toBe(7));
    });

    it('scanning the next product saves the taps so far', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);
        await userEvent.click(screen.getByRole('button', { name: 'Tap-count' }));

        scan('222');
        scan('222');
        scan('222');
        scan('444');
        await panel('Sprite 50cl');

        await waitFor(async () => expect((await saved()).find((e) => e.productId === 8)?.counted).toBe(3));
    });
});

describe('Undo instead of "Are you sure?"', () => {
    it('Undo after a first count takes it back', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('222');
        await panel('Fanta 50cl');
        await keys('6', 'Save ✓');
        await waitFor(async () => expect((await saved()).length).toBe(1));

        await userEvent.click(screen.getByRole('button', { name: 'Undo' }));
        await waitFor(async () => expect(await saved()).toEqual([]));
    });

    it('taking back from the list asks nothing, and Undo brings it back', async () => {
        window.confirm = vi.fn(() => true);
        await saveCount(TASK, 0, { aisleId: 4, productId: 8, counted: 6, breakdown: [{ unit_id: 2, unit: 'Piece', factor: 1, qty: 6 }] });
        show();
        await screen.findByText('Fanta 50cl');

        await userEvent.click(screen.getByRole('button', { name: 'Take back Fanta 50cl' }));
        expect(window.confirm).not.toHaveBeenCalled();
        expect(screen.queryByText('Fanta 50cl')).toBeNull();
        await waitFor(async () => expect(await saved()).toEqual([]));

        await userEvent.click(screen.getByRole('button', { name: 'Undo' }));
        await waitFor(async () => expect((await saved())[0]?.counted).toBe(6));
    });
});

describe('What a scan means here', () => {
    it('refuses, on the phone, a product the pack says is already counted', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('333');

        expect((await screen.findByRole('status')).textContent).toMatch('Already counted on Tuesday 24 Nov in Aisle 2 — skip');
        expect(screen.queryByRole('region', { name: 'Count Peak Milk' })).toBeNull();
    });

    it('marks a product found here but not expected on this aisle', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('222');
        expect(await screen.findByText('Found here, not on aisle list')).toBeTruthy();
    });

    it('an unknown barcode opens the not-in-the-system form', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('999');
        expect(await screen.findByRole('dialog', { name: 'Not in the system' })).toBeTruthy();
        expect(feedback.unknown).toHaveBeenCalled();
    });

    it('name search runs on the phone', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        fireEvent.change(screen.getByLabelText('Search by name'), { target: { value: 'fan' } });
        await userEvent.click(await screen.findByRole('button', { name: 'Fanta 50cl' }));

        expect(await panel('Fanta 50cl')).toBeTruthy();
        expect(apiCalls.get).toBe(0);
    });
});

describe('At a desk, with a hand-held scanner', () => {
    it('Enter on an empty barcode box saves', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('222');
        await panel('Fanta 50cl');
        await keys('3');
        fireEvent.submit(screen.getByLabelText('Barcode').closest('form'));

        await waitFor(async () => expect((await saved())[0]?.counted).toBe(3));
    });

    it('a number typed by hand + Enter is the quantity, not a barcode', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);

        scan('222');
        await panel('Fanta 50cl');
        const box = screen.getByLabelText('Barcode');
        fireEvent.keyDown(box, { key: '7' });
        fireEvent.change(box, { target: { value: '7' } });
        fireEvent.submit(box.closest('form'));

        await waitFor(async () => expect((await saved())[0]?.counted).toBe(7));
    });
});

describe('The camera stays on', () => {
    it('counts product after product with one getUserMedia', async () => {
        let inView = null;
        const stopped = vi.fn();
        const getUserMedia = vi.fn(async () => {
            const stream = new MediaStream();
            stream.getTracks = () => [{ stop: stopped }];
            return stream;
        });
        class FakeDetector {
            static async getSupportedFormats() { return ['ean_13']; }
            async detect() { return inView ? [{ rawValue: inView }] : []; }
        }

        const restore = [];
        const set = (obj, prop, value) => {
            const had = Object.getOwnPropertyDescriptor(obj, prop);
            restore.push(() => (had ? Object.defineProperty(obj, prop, had) : delete obj[prop]));
            Object.defineProperty(obj, prop, { value, configurable: true, writable: true });
        };
        set(navigator, 'mediaDevices', { getUserMedia });
        set(window, 'BarcodeDetector', FakeDetector);
        set(window, 'matchMedia', (q) => ({ matches: q.includes('coarse'), addEventListener() {}, removeEventListener() {} }));
        set(HTMLMediaElement.prototype, 'play', async () => {});
        Object.defineProperty(HTMLMediaElement.prototype, 'readyState', { get: () => 4, configurable: true });
        restore.push(() => delete HTMLMediaElement.prototype.readyState);

        try {
            show();
            await screen.findByRole('button', { name: 'Open the lists' });

            for (const [code, name, qty] of [['222', 'Fanta 50cl', '2'], ['444', 'Sprite 50cl', '3'], ['555', 'Malta', '4']]) {
                inView = code;
                await screen.findByRole('region', { name: `Count ${name}` }, { timeout: 3000 });
                inView = null;
                await keys(qty);
            }
            inView = '222'; // back to the first one: saves Malta, then Fanta asks Add or Replace
            await waitFor(async () => expect((await saved()).length).toBe(3), { timeout: 3000 });

            expect(getUserMedia).toHaveBeenCalledTimes(1);
            expect(stopped).not.toHaveBeenCalled();
        } finally {
            inView = null;
            cleanup();
            restore.reverse().forEach((undo) => undo());
        }
    }, 15000);
});

/** A phone-width screen (no desk layout): the lists are in a sheet. */
const phoneWidth = () => {
    const had = window.matchMedia;
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {} });
    return () => { window.matchMedia = had; };
};

describe('The lists (B6)', () => {
    it('on a phone they are a sheet: swipe up or tap; Still to count is in shelf order and opens a product', async () => {
        const restore = phoneWidth();
        try {
            const shelf = makePack({ aisles: [{ id: 4, label: 'Drinks', expected: [11, 7, 12] }] });
            await saveCount(TASK, 0, { aisleId: 4, productId: 7, counted: 2, breakdown: [{ unit_id: 1, unit: 'Piece', factor: 1, qty: 2 }] });
            show(shelf);

            const handle = await screen.findByRole('button', { name: 'Open the lists' });
            await waitFor(() => expect(handle.textContent).toMatch('Counted 1 · Still to count 2'));
            expect(screen.queryByRole('tab', { name: /Counted/ })).toBeNull();

            await userEvent.click(handle);
            const sheet = await screen.findByRole('dialog', { name: 'Drinks' });
            expect(within(sheet).getByText('Coke 50cl')).toBeTruthy();

            await userEvent.click(within(sheet).getByRole('tab', { name: 'Still to count (2)' }));
            const todo = within(sheet).getAllByRole('listitem').map((li) => li.textContent);
            expect(todo[0]).toMatch('Sprite 50cl');
            expect(todo[1]).toMatch('Malta');

            await userEvent.click(within(sheet).getByRole('button', { name: /Malta/ }));
            expect(await panel('Malta')).toBeTruthy();
            expect(screen.queryByRole('dialog', { name: 'Drinks' })).toBeNull();
        } finally {
            restore();
        }
    });

    it('at a desk they sit beside the counting, always showing', async () => {
        show();
        expect(await screen.findByRole('complementary', { name: 'Lists' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Open the lists' })).toBeNull();
    });
});

describe('At a desk: digits go straight into the quantity (B8)', () => {
    it('typed by hand, they show as the quantity at once; Enter saves', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);
        scan('222');
        await panel('Fanta 50cl');

        const box = screen.getByLabelText('Barcode');
        fireEvent.keyDown(box, { key: '1' });
        fireEvent.change(box, { target: { value: '1' } });
        await new Promise((r) => setTimeout(r, 60)); // a person, not a scanner
        fireEvent.keyDown(box, { key: '2' });
        fireEvent.change(box, { target: { value: '12' } });
        expect(screen.getByText('= 12 Pieces')).toBeTruthy();

        fireEvent.submit(box.closest('form'));
        await waitFor(async () => expect((await saved())[0]?.counted).toBe(12));
    });

    it('a code from a scanner that passed through the quantity leaves it as it was', async () => {
        show();
        await screen.findByText(/Nothing counted yet/);
        scan('222');
        await panel('Fanta 50cl');
        await keys('3');

        const box = screen.getByLabelText('Barcode');
        fireEvent.change(box, { target: { value: '444' } });
        fireEvent.submit(box.closest('form'));

        await panel('Sprite 50cl');
        await waitFor(async () => expect((await saved()).find((e) => e.productId === 8)?.counted).toBe(3));
    });
});

describe('A quick count (an area with no aisle list)', () => {
    it('nothing is "not on aisle list", and the header counts what was found, not "x / 0"', async () => {
        const quickPack = makePack({ aisles: [{ id: 4, label: 'Ngozi · 26 Nov', expected: [], quick: true }] });
        render(<CountScreen session={session(quickPack)} aisle={quickPack.task.aisles[0]} aisles={quickPack.task.aisles} onBack={() => {}} />);

        scan('222');
        await panel('Fanta 50cl');
        expect(screen.queryByText('Found here, not on aisle list')).toBeNull();

        await keys('4', 'Save ✓');
        await waitFor(async () => expect((await saved())[0]).toMatchObject({ productId: 8, counted: 4, unlisted: false }));
        expect(await screen.findByText('1 here · 1 in all')).toBeTruthy();
    });
});

describe('Needs attention (A5)', () => {
    it('a refused count shows in the chip and the list, with what to do; Take back clears it', async () => {
        await saveCount(TASK, 0, { aisleId: 4, productId: 8, counted: 6, breakdown: [{ unit_id: 2, unit: 'Piece', factor: 1, qty: 6 }] });
        await db.entries.update([TASK, 4, 8], { syncStatus: 'rejected', reason: 'Already counted on Tuesday 24 Nov in Aisle 2 — skip' });
        await db.outbox.clear();
        show();

        const chip = await screen.findByRole('button', { name: 'Sync: ⚠ 1 needs attention' });
        await userEvent.click(chip);
        const list = await screen.findByRole('dialog', { name: 'Needs attention' });
        expect(within(list).getByText('Fanta 50cl')).toBeTruthy();
        expect(within(list).getByText(/Another task has already counted it/)).toBeTruthy();

        await userEvent.click(within(list).getByRole('button', { name: 'Take back' }));
        await waitFor(async () => expect(await saved()).toEqual([]));
        expect(await screen.findByRole('button', { name: 'Sync: ⬆ 1 waiting' })).toBeTruthy();
    });
});

describe('Submitting the count (A6)', () => {
    const handInSession = () => session(makePack({ aisles: [{ id: 4, label: 'Drinks', expected: [7, 8, 11] }] }));

    it('works out what is missing on the phone; stays off until each is answered; hands in with the answers', async () => {
        window.confirm = vi.fn(() => true);
        const api = (await import('../lib/api')).default;
        await saveCount(TASK, 0, { aisleId: 4, productId: 7, counted: 2, breakdown: [{ unit_id: 1, unit: 'Piece', factor: 1, qty: 2 }] });
        const done = vi.fn();
        render(<SubmitReview session={handInSession()} onBack={() => {}} onSubmitted={done} />);

        await screen.findByText('Fanta 50cl');
        expect(screen.getByText('Sprite 50cl')).toBeTruthy();
        expect(screen.queryByText('Coke 50cl')).toBeNull();
        expect(apiCalls.get).toBe(0);

        const handIn = screen.getByRole('button', { name: 'Submit count' });
        expect(handIn.disabled).toBe(true);

        const [fantaZero] = screen.getAllByRole('button', { name: 'Not on shelf (0)' });
        await userEvent.click(fantaZero);
        await userEvent.click(screen.getByRole('button', { name: /Mark all remaining as Skip/ }));
        expect(handIn.disabled).toBe(false);

        await userEvent.click(handIn);
        await waitFor(() => expect(done).toHaveBeenCalledWith('Count submitted. Thank you.'));

        const urls = api.post.mock.calls.map(([url]) => url);
        expect(urls.indexOf('/tasks/5/sync')).toBeLessThan(urls.indexOf('/tasks/5/submit'));
        const submitBody = api.post.mock.calls.find(([url]) => url === '/tasks/5/submit')[1];
        expect(submitBody.decisions).toEqual({ 8: 'not_on_shelf', 11: 'skip' });
        expect(submitBody.device).toMatch(/^phone-/);
    });

    it('with no signal: saved on the phone, hands in by itself later', async () => {
        window.confirm = vi.fn(() => true);
        const api = (await import('../lib/api')).default;
        api.post.mockImplementationOnce(async () => { throw new Error('Network Error'); });
        await saveCount(TASK, 0, { aisleId: 4, productId: 7, counted: 2, breakdown: [{ unit_id: 1, unit: 'Piece', factor: 1, qty: 2 }] });
        const done = vi.fn();
        render(<SubmitReview session={handInSession()} onBack={() => {}} onSubmitted={done} />);

        await screen.findByText('Fanta 50cl');
        await userEvent.click(screen.getByRole('button', { name: /Mark all remaining as Skip/ }));
        await userEvent.click(screen.getByRole('button', { name: 'Submit count' }));

        await waitFor(() => expect(done).toHaveBeenCalledWith(OFFLINE_HAND_IN));
        const queued = await db.outbox.toArray();
        expect(queued.map((row) => row.op.type)).toEqual(['set', 'submit']);
        expect(await handInState(TASK)).toMatchObject({ status: 'queued' });

        // Back online: the count goes first, then the hand-in.
        expect(await syncNow(api, TASK)).toMatchObject({ handedIn: true });
        expect(await db.outbox.count()).toBe(0);
        expect(await handInState(TASK)).toMatchObject({ status: 'done' });
    });

    it('a count the server refused is treated as not counted, and needs an answer', async () => {
        await saveCount(TASK, 0, { aisleId: 4, productId: 7, counted: 2, breakdown: [{ unit_id: 1, unit: 'Piece', factor: 1, qty: 2 }] });
        await db.entries.update([TASK, 4, 7], { syncStatus: 'rejected', reason: 'No.' });
        render(<SubmitReview session={handInSession()} onBack={() => {}} onSubmitted={() => {}} />);

        expect(await screen.findByText('Coke 50cl')).toBeTruthy();
        expect(await screen.findByText(/1 count was not accepted/)).toBeTruthy();
    });
});

describe('Catching a slip before it is saved', () => {
    // Fanta's ceiling: above 50, the phone asks first (blind: no stock shown).
    const guarded = { ...pack, products: pack.products.map((r) => (r[0] === 8 ? [...r, 50] : r)) };

    it('a number far above normal asks "Save 500 Pieces?"; Fix goes back, Yes saves', async () => {
        show(guarded);
        scan('222');
        await panel('Fanta 50cl');
        await keys('5', '0', '0', 'Save ✓');

        const ask = await screen.findByRole('alertdialog', { name: 'Check this number' });
        expect(ask.textContent).toMatch(/Save 500 Pieces\?/);
        expect(ask.textContent).not.toMatch(/stock/i);
        expect(await saved()).toHaveLength(0);

        await userEvent.click(screen.getByRole('button', { name: 'Fix the number' }));
        expect(screen.queryByRole('alertdialog')).toBeNull();

        await keys('Save ✓');
        await userEvent.click(await screen.findByRole('button', { name: 'Yes, 500 is right' }));
        await waitFor(async () => expect((await saved())[0]?.counted).toBe(500));
    });

    it('a normal number saves straight away', async () => {
        show(guarded);
        scan('222');
        await panel('Fanta 50cl');
        await keys('4', '0', 'Save ✓');

        expect(screen.queryByRole('alertdialog')).toBeNull();
        await waitFor(async () => expect((await saved())[0]?.counted).toBe(40));
    });

    it('packs are said in words: "3 Crates = 36 Pieces"', async () => {
        show();
        scan('111');
        await panel('Coke 50cl');
        await userEvent.click(screen.getByRole('button', { name: 'Count in Crate' }));
        await keys('3');

        expect(screen.getByTestId('in-words').textContent).toBe('3 Crates = 36 Pieces');
        await keys('Save ✓');
        expect(await screen.findByText(/Coke 50cl · 3 Crates = 36 Pieces saved/)).toBeTruthy();
    });
});

describe('Damaged and expired, apart from the good count', () => {
    it('20 good and 3 damaged: saved apart, and sent up apart', async () => {
        show();
        scan('222');
        await panel('Fanta 50cl');
        await keys('2', '0');
        await userEvent.click(screen.getByRole('button', { name: 'Type damaged' }));
        await keys('3');
        expect(screen.getByRole('button', { name: 'Type damaged' }).textContent).toBe('Damaged 3');
        await keys('Save ✓');

        await waitFor(async () => expect((await saved())[0]).toMatchObject({ productId: 8, counted: 20, damaged: 3, expired: 0 }));
        const op = (await db.outbox.toArray()).at(-1).op;
        expect(op).toMatchObject({ damaged: 3, expired: 0 });
        expect(await screen.findByText(/Fanta 50cl · 20 Pieces \+ 3 damaged saved/)).toBeTruthy();
    });

    it('all of it expired: nothing good, still saved', async () => {
        show();
        scan('222');
        await panel('Fanta 50cl');
        await userEvent.click(screen.getByRole('button', { name: 'Type damaged' }));
        await userEvent.click(screen.getByRole('button', { name: 'Type expired' }));
        await keys('6', 'Save ✓');

        await waitFor(async () => expect((await saved())[0]).toMatchObject({ counted: 0, damaged: 0, expired: 6 }));
    });
});
