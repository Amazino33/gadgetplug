import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { db } from './db';
import {
    cacheServerList, cachedServerList, holdLocally, mergedHeldSales, queueRemoval, syncHeldSales,
} from './heldSales';

vi.mock('./api', () => ({ default: { post: vi.fn(), delete: vi.fn(), get: vi.fn() } }));

let api;

const offline = () => Object.assign(new Error('Network Error'), { response: undefined });
const refused = (status) => Object.assign(new Error('refused'), { response: { status, data: {} } });

const cart = { items: [{ id: 1, name: 'Coke', price: 450, qty: 2 }], customer: null };
const serverSale = { id: 77, client_ref: null, label: 'Mama T', cart_data: cart, created_at: '2026-10-11T09:00:00Z' };

beforeEach(async () => {
    api = (await import('./api')).default;
    vi.clearAllMocks();
    await db.heldSales.clear();
    localStorage.clear();
});

describe('holding a sale with no connection', () => {
    it('keeps it on this till and lists it beside the server\'s held sales', async () => {
        await holdLocally(2, 40, { label: null, customer_id: null, cart_data: cart, client_ref: 'hold-a' });

        const list = await mergedHeldSales(2, 40, [serverSale]);

        expect(list).toHaveLength(2);
        expect(list[1]).toMatchObject({ local: true, client_ref: 'hold-a', cart_data: cart });
    });

    it('uploads it with its reference when the connection returns, then clears it', async () => {
        await holdLocally(2, 40, { cart_data: cart, client_ref: 'hold-a' });
        api.post.mockResolvedValueOnce({ data: { id: 90 } });

        await syncHeldSales(2, 40);

        expect(api.post).toHaveBeenCalledWith('/suspended', expect.objectContaining({ vendor_id: 2, client_ref: 'hold-a', cart_data: cart }));
        expect(await db.heldSales.count()).toBe(0);
    });

    it('waits, untouched, while there is still no connection', async () => {
        await holdLocally(2, 40, { cart_data: cart, client_ref: 'hold-a' });
        api.post.mockRejectedValueOnce(offline());

        await syncHeldSales(2, 40);

        expect(await db.heldSales.count()).toBe(1);
    });

    it('is not shown twice once the server has it but the local row is not yet cleared', async () => {
        await holdLocally(2, 40, { cart_data: cart, client_ref: 'hold-a' });

        const list = await mergedHeldSales(2, 40, [{ ...serverSale, id: 90, client_ref: 'hold-a' }]);

        expect(list).toHaveLength(1);
        expect(list[0].local).toBeUndefined();
    });
});

describe('resuming or discarding a server-held sale offline', () => {
    it('takes it off this till\'s list straight away and off the server\'s later', async () => {
        await queueRemoval(2, 40, 77);

        expect(await mergedHeldSales(2, 40, [serverSale])).toHaveLength(0);

        api.delete.mockResolvedValueOnce({ data: {} });
        await syncHeldSales(2, 40);

        expect(api.delete).toHaveBeenCalledWith('/suspended/77', { data: { vendor_id: 2 } });
        expect(await db.heldSales.count()).toBe(0);
    });

    it('treats a sale already gone from the server as done', async () => {
        await queueRemoval(2, 40, 77);
        api.delete.mockRejectedValueOnce(refused(404));

        await syncHeldSales(2, 40);

        expect(await db.heldSales.count()).toBe(0);
    });
});

describe('the last known list', () => {
    it('is kept per shop for when the server cannot be asked', () => {
        cacheServerList(2, 40, [serverSale]);

        expect(cachedServerList(2, 40)).toEqual([serverSale]);
        expect(cachedServerList(2, 41)).toEqual([]);
        expect(cachedServerList(3, 40)).toEqual([]);
    });
});

describe('each cashier\'s own', () => {
    it('never shows or uploads one cashier\'s offline hold for another', async () => {
        await holdLocally(2, 40, { cart_data: cart, client_ref: 'hold-cashier-1' });

        // Cashier 41 signs in on the same till.
        expect(await mergedHeldSales(2, 41, [])).toHaveLength(0);

        await syncHeldSales(2, 41);
        expect(api.post).not.toHaveBeenCalled();
        expect(await db.heldSales.count()).toBe(1);

        // Back as cashier 40: it shows, and uploads under their name.
        expect(await mergedHeldSales(2, 40, [])).toHaveLength(1);
        api.post.mockResolvedValueOnce({ data: { id: 5 } });
        await syncHeldSales(2, 40);
        expect(api.post).toHaveBeenCalledTimes(1);
        expect(await db.heldSales.count()).toBe(0);
    });
});
