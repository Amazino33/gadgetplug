import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { db } from '../lib/db';
import api from '../lib/api';
import SalesHistoryScreen from './SalesHistoryScreen';

vi.mock('../lib/api', () => ({ default: { get: vi.fn() } }));
vi.mock('../lib/printDocument', () => ({ printDocument: vi.fn() }));

afterEach(cleanup);

const CASHIER = 7;

beforeEach(async () => {
    api.get.mockReset();
    await db.sales.clear();
});

const summary = (over = {}) => ({
    sales_count: 2,
    collected: 30000,
    credit: 0,
    tenders: { cash: 10000, card: 20000, bank_transfer: 0, debt: 0 },
    vat: 0,
    discounts: 0,
    revenue: 27500,
    voided: { count: 0, total: 0 },
    refunds: { count: 1, total: 2000, money_out: 2000 },
    net_collected: 28000,
    ...over,
});

const sale = (over = {}) => ({
    id: 1,
    reference: 'POS-1',
    completed_at: '2026-10-22T09:00:00.000Z',
    business_date: '2026-10-22',
    total: 10000,
    payment_method: 'cash',
    status: 'completed',
    items: [{ product_name: 'Charger', quantity: 1, total: 10000 }],
    ...over,
});

const show = () => render(
    <SalesHistoryScreen vendorId={3} cashierId={CASHIER} onBack={() => {}} onClose={() => {}} onReprint={() => {}} />,
);

describe('SalesHistoryScreen', () => {
    it('shows the range summary and each day with its own totals', async () => {
        api.get.mockResolvedValueOnce({ data: {
            period: { label: 'Custom range', from: '2026-10-21', to: '2026-10-22' },
            summary: summary(),
            days: {
                '2026-10-22': summary({ sales_count: 1, net_collected: 10000, revenue: 9000 }),
                '2026-10-21': summary({ sales_count: 1, net_collected: 18000, revenue: 19000 }),
            },
            data: [sale(), sale({ id: 2, reference: 'POS-2', business_date: '2026-10-21', completed_at: '2026-10-21T09:00:00.000Z', total: 20000 })],
            current_page: 1,
            last_page: 1,
        } });

        show();

        expect(await screen.findByText('₦28,000.00')).toBeTruthy();
        expect(screen.getByText(/after ₦2,000.00 refunded/)).toBeTruthy();
        // The range line and the day heading both name it.
        expect(screen.getAllByText(/22 Oct 2026/).length).toBeGreaterThanOrEqual(2);
        expect(screen.getByText('₦18,000.00')).toBeTruthy();
        expect(screen.getByText('POS-2')).toBeTruthy();
    });

    it('asks the server again with the filter when one is chosen', async () => {
        api.get.mockResolvedValue({ data: { period: { from: '2026-10-22', to: '2026-10-22' }, summary: summary(), days: {}, data: [], current_page: 1, last_page: 1 } });

        show();
        await screen.findByText('No sales match.');

        await userEvent.selectOptions(screen.getByLabelText('Payment method'), 'cash');

        await waitFor(() => expect(api.get).toHaveBeenLastCalledWith('/sales/history', expect.objectContaining({
            params: expect.objectContaining({ method: 'cash', preset: 'today' }),
        })));
    });

    it('says so plainly when the cashier is not allowed it', async () => {
        api.get.mockRejectedValueOnce({ response: { status: 403, data: { message: 'You do not have permission to view the full sales history.' } } });

        show();

        expect(await screen.findByText('You do not have permission to view the full sales history.')).toBeTruthy();
    });

    it('falls back to the device record offline for a recent window', async () => {
        await db.sales.add({
            cashier_id: CASHIER,
            reference: 'POS-LOCAL',
            completed_at: new Date().toISOString(),
            total: 4500,
            payment_method: 'cash',
            status: 'completed',
            items: [{ product_name: 'Cable', quantity: 1, total: 4500 }],
            synced: 1,
        });
        api.get.mockRejectedValueOnce(new Error('Network Error'));

        show();

        expect(await screen.findByText('POS-LOCAL')).toBeTruthy();
        expect(screen.getByText(/Offline — showing this device's own record/)).toBeTruthy();
    });

    it('will not pass off an older window as empty when offline', async () => {
        api.get.mockRejectedValue(new Error('Network Error'));

        show();
        await screen.findByText('No sales match.');

        await userEvent.click(screen.getByRole('button', { name: 'This year' }));

        expect(await screen.findByText(/Needs a connection/)).toBeTruthy();
        expect(screen.queryByText('No sales match.')).toBeNull();
    });
});
