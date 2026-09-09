import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import BarcodeScanner from './BarcodeScanner';

// A scan has to carry today's price, not the price the device last heard
// about. The server records whatever price the till sends and only checks it
// against a floor — a minimum, deliberately, so a negotiated price can still
// go through — so a stale cached price is sold at the stale price, and
// nothing about that is visible to the cashier.

const cachedCopy = { id: 1, name: 'Samsung 25W Charger', barcode: '600123', price: 5300, available_stock: 4 };
const serverCopy = { id: 1, name: 'Samsung 25W Charger', barcode: '600123', price: 6500, available_stock: 9 };

let cached = [];

vi.mock('../lib/db', () => ({
    db: {
        products: {
            where: () => ({
                equals: (value) => ({
                    first: async () => cached.find((p) => p.barcode === value) ?? undefined,
                }),
            }),
        },
    },
}));

vi.mock('../lib/api', () => ({ default: { get: vi.fn() } }));

// Driven through the manual-entry box rather than the camera: the lookup it
// runs is the same one a camera detection runs, and a camera cannot be
// pointed at anything in a test.
const openAndScan = async (barcode) => {
    await userEvent.click(screen.getByRole('button', { name: /scan/i }));
    await userEvent.type(await screen.findByPlaceholderText(/8807006013816/), barcode);
    await userEvent.click(screen.getByRole('button', { name: /^use$/i }));
};

beforeEach(async () => {
    cached = [cachedCopy];
    vi.stubGlobal('navigator', { ...window.navigator, onLine: true });

    const api = (await import('../lib/api')).default;
    api.get.mockReset();
});

afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

describe('scanning a barcode', () => {
    it("takes the server's price, not the copy cached on the device", async () => {
        const api = (await import('../lib/api')).default;
        api.get.mockResolvedValueOnce({ data: [serverCopy] });

        const onProductFound = vi.fn();
        render(<BarcodeScanner vendorId={1} onProductFound={onProductFound} />);

        await openAndScan('600123');

        await waitFor(() => expect(onProductFound).toHaveBeenCalled());
        // 6500, the price it is being sold at today — not the 5300 the device
        // last downloaded.
        expect(onProductFound).toHaveBeenCalledWith(expect.objectContaining({ price: 6500 }));
    });

    it('falls back to the device when the server cannot be reached', async () => {
        const api = (await import('../lib/api')).default;
        api.get.mockRejectedValueOnce(new Error('timeout of 5000ms exceeded'));

        const onProductFound = vi.fn();
        render(<BarcodeScanner vendorId={1} onProductFound={onProductFound} />);

        await openAndScan('600123');

        // Still sells — a till with no connection is the whole point of the
        // cache.
        await waitFor(() => expect(onProductFound).toHaveBeenCalledWith(expect.objectContaining({ price: 5300 })));
    });

    it('uses the device outright when offline', async () => {
        vi.stubGlobal('navigator', { ...window.navigator, onLine: false });

        const api = (await import('../lib/api')).default;
        const onProductFound = vi.fn();
        render(<BarcodeScanner vendorId={1} onProductFound={onProductFound} />);

        await openAndScan('600123');

        await waitFor(() => expect(onProductFound).toHaveBeenCalledWith(expect.objectContaining({ price: 5300 })));
        expect(api.get).not.toHaveBeenCalled();
    });

    it('reports a barcode neither the server nor the device knows', async () => {
        const api = (await import('../lib/api')).default;
        api.get.mockResolvedValueOnce({ data: [] });
        cached = [];

        render(<BarcodeScanner vendorId={1} onProductFound={vi.fn()} />);

        await openAndScan('999999');

        expect(await screen.findByText(/No product found/i)).toBeDefined();
    });
});
