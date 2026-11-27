import 'fake-indexeddb/auto';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor, cleanup, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const milk = { id: 7, name: 'Peak Milk 400g', sku: 'PEAK-400', barcode: '111', image: null, units: [] };
let mapped = [];
// The shop's products on the phone, as the mapping download sends them.
let shop = [];
let mapFails = false;

const rice = { id: 8, name: 'Rice 5kg', sku: 'RICE-5' };
const RICE_ROW = [8, 'Rice 5kg', 'RICE-5', null, 'Bag', [], [['222', null]]];

vi.mock('../lib/api', () => ({
    default: {
        get: vi.fn(async (url) => {
            if (url === '/aisles/3') return { data: { aisle: { id: 3, label: 'Aisle 3' }, products: mapped } };
            if (url === '/mapping-catalogue') return { data: { v: 'v1', products: shop } };
            return { data: { products: [] } };
        }),
        post: vi.fn(async (url, body) => {
            if (url === '/aisles/3/scan') {
                if (body.code !== '111') return { data: { status: 'unknown', code: body.code } };
                const already = mapped.some((p) => p.id === milk.id);
                if (!already) mapped = [milk];
                return { data: { status: already ? 'already' : 'added', product: milk } };
            }
            if (url === '/aisles/3/map') {
                if (mapFails) throw new Error('Network Error');
                body.product_ids.forEach((id) => { if (id === rice.id) mapped = [rice, ...mapped]; });
                return { data: { added: body.product_ids, already: [], missing: [] } };
            }
            return { data: {} };
        }),
        delete: vi.fn(async () => ({ data: { status: 'removed' } })),
    },
    errorMessage: () => 'error',
}));

vi.mock('../lib/feedback', () => ({ good: vi.fn(), warn: vi.fn(), soundOn: () => true, setSound: vi.fn() }));

import MapAisle from './MapAisle';
import api from '../lib/api';
import { good } from '../lib/feedback';
import { db } from '../offline/db';
import { resetCatalogue, resetMapQueue } from '../offline/mapping';

const typeCode = (code) => {
    fireEvent.change(screen.getByLabelText('Barcode'), { target: { value: code } });
    fireEvent.submit(screen.getByLabelText('Barcode').closest('form'));
};

describe('Mapping an aisle', () => {
    afterEach(async () => {
        cleanup();
        mapped = []; shop = []; mapFails = false;
        resetCatalogue(); resetMapQueue();
        await db.meta.clear();
        vi.clearAllMocks();
    });

    it('adds a scanned product, then says it is already there', async () => {
        render(<MapAisle aisle={{ id: 3, label: 'Aisle 3' }} task={null} onBack={() => {}} />);
        await screen.findByText(/Nothing mapped here yet/);

        typeCode('111');
        await waitFor(() => expect(screen.getByRole('status').textContent).toMatch(/Added: Peak Milk 400g/));
        expect(screen.getByText('Peak Milk 400g')).toBeTruthy();

        typeCode('111');
        await waitFor(() => expect(screen.getByRole('status').textContent).toMatch(/Already in this aisle/));
    });

    it('asks for a name and photo when the barcode is unknown', async () => {
        render(<MapAisle aisle={{ id: 3, label: 'Aisle 3' }} task={null} onBack={() => {}} />);
        await screen.findByText(/Nothing mapped here yet/);

        typeCode('999');

        const dialog = await screen.findByRole('dialog', { name: 'Not in the system' });
        expect(dialog.textContent).toMatch(/Nothing in this shop has the barcode 999/);
        expect(screen.getByDisplayValue('999')).toBeTruthy();

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('dialog', { name: 'Not in the system' })).toBeNull();
    });

    it('opens the camera with this aisle named, and offers typing where it cannot read', async () => {
        render(<MapAisle aisle={{ id: 3, label: 'Aisle 3' }} task={null} onBack={() => {}} />);
        await screen.findByText(/Nothing mapped here yet/);

        await userEvent.click(screen.getByLabelText('Scan with camera'));
        const dialog = await screen.findByRole('dialog', { name: 'Scan with camera' });

        expect(dialog.textContent).toMatch(/Mapping Aisle 3/);
        expect(dialog.textContent).toMatch(/cannot read barcodes/);
    });

    it('a product the phone knows: added and beeped at once, saved to the server in a batch', async () => {
        shop = [RICE_ROW];
        render(<MapAisle aisle={{ id: 3, label: 'Aisle 3' }} task={null} onBack={() => {}} />);
        await screen.findByText(/Nothing mapped here yet/);
        await waitFor(() => expect(api.get).toHaveBeenCalledWith('/mapping-catalogue', expect.anything()));
        await new Promise((r) => setTimeout(r, 20));

        typeCode('222');

        // On screen and beeped before anything was sent.
        expect(screen.getByRole('status').textContent).toMatch(/Added: Rice 5kg/);
        expect(good).toHaveBeenCalled();
        expect(api.post).not.toHaveBeenCalledWith('/aisles/3/scan', expect.anything());

        typeCode('222');
        expect(screen.getByRole('status').textContent).toMatch(/Already in this aisle: Rice 5kg/);

        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/aisles/3/map', { product_ids: [8], task_id: null }), { timeout: 3000 });
        expect(api.post.mock.calls.filter(([url]) => url === '/aisles/3/map')).toHaveLength(1);
    });

    it('no signal: what was scanned waits on the phone and says so', async () => {
        shop = [RICE_ROW];
        mapFails = true;
        render(<MapAisle aisle={{ id: 3, label: 'Aisle 3' }} task={null} onBack={() => {}} />);
        await screen.findByText(/Nothing mapped here yet/);
        await waitFor(() => expect(api.get).toHaveBeenCalledWith('/mapping-catalogue', expect.anything()));
        await new Promise((r) => setTimeout(r, 20));

        typeCode('222');

        expect(await screen.findByText(/1 waiting on this phone/, {}, { timeout: 3000 })).toBeTruthy();
        expect(screen.getByText('Rice 5kg')).toBeTruthy();
        expect((await db.meta.get('mapQueue')).value).toEqual([{ aisleId: 3, taskId: null, productId: 8 }]);
    });

    it('name search finds products on the phone, with no request', async () => {
        shop = [RICE_ROW];
        render(<MapAisle aisle={{ id: 3, label: 'Aisle 3' }} task={null} onBack={() => {}} />);
        await screen.findByText(/Nothing mapped here yet/);
        await waitFor(() => expect(api.get).toHaveBeenCalledWith('/mapping-catalogue', expect.anything()));
        await new Promise((r) => setTimeout(r, 20));

        fireEvent.change(screen.getByLabelText('Search by name'), { target: { value: 'rice' } });

        expect(await screen.findByRole('button', { name: 'Add here' })).toBeTruthy();
        expect(api.get).not.toHaveBeenCalledWith('/products', expect.anything());
    });
});
