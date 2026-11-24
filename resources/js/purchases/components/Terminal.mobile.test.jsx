import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor, cleanup, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// A phone: a finger for a pointer and a narrow screen. Set before the
// terminal is imported, because it reads the pointer once at load.
vi.hoisted(() => {
    window.matchMedia = (query) => ({
        matches: query.includes('pointer: coarse'),
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
    });
});

const products = {
    111: {
        id: 1, name: 'Bar Soap', barcode: '111', sku: null, base_unit: 'Piece', price: 500, cost_price: 300,
        track_serial: false, units: [{ id: 10, name: 'Piece', factor: 1, is_base: true }], barcodes: [],
    },
};

vi.mock('../lib/api', () => ({
    default: {
        get: vi.fn(async (url, { params } = {}) => {
            if (url === '/suppliers') return { data: { suppliers: [] } };
            if (url === '/catalogue') return { data: Object.values(products) };
            if (url === '/lookup') return { data: products[params.code] ? { found: true, product: products[params.code] } : { found: false } };
            return { data: { products: [] } };
        }),
        post: vi.fn(),
    },
    errorMessage: () => 'error',
    fieldErrors: () => ({}),
}));

import Terminal from './Terminal';

describe('Purchase Terminal on a phone', () => {
    afterEach(cleanup);

    it('leaves the keyboard down, shows lines as cards, and steps the quantity with buttons', async () => {
        const user = userEvent.setup();
        render(<Terminal active resume={null} onResumed={() => {}} onSaved={() => {}} />);

        const box = screen.getByLabelText('Scan barcode or search products');
        await screen.findByText(/Tap the camera/);
        expect(document.activeElement).not.toBe(box);

        await user.click(box);
        await user.keyboard('111{Enter}');

        expect(await screen.findByText('Bar Soap')).toBeTruthy();
        // A card, not a table row.
        expect(document.querySelector('table')).toBeNull();

        const qty = screen.getByLabelText('Quantity of Bar Soap');
        expect(qty.value).toBe('1');

        await user.click(screen.getByLabelText('One more Bar Soap'));
        await user.click(screen.getByLabelText('One more Bar Soap'));
        expect(qty.value).toBe('3');

        await user.click(screen.getByLabelText('One less Bar Soap'));
        expect(qty.value).toBe('2');
    });

    it('scans with the camera, and a code can be typed when the camera cannot read', async () => {
        const user = userEvent.setup();
        render(<Terminal active resume={null} onResumed={() => {}} onSaved={() => {}} />);
        await screen.findByText(/Tap the camera/);

        await user.click(screen.getByLabelText('Scan with camera'));
        const dialog = await screen.findByRole('dialog', { name: 'Scan with camera' });

        // No BarcodeDetector in the test browser: it says so and offers typing.
        expect(dialog.textContent).toMatch(/cannot read barcodes/);

        fireEvent.change(screen.getByLabelText('Type a barcode'), { target: { value: '111' } });
        await user.click(screen.getByRole('button', { name: 'Add' }));

        await waitFor(() => expect(screen.getByRole('status').textContent).toMatch(/Bar Soap/));
        expect(dialog.textContent).toMatch(/1 line on this purchase/);

        await user.click(screen.getByRole('button', { name: 'Done' }));
        expect(screen.queryByRole('dialog', { name: 'Scan with camera' })).toBeNull();
        expect(screen.getByLabelText('Quantity of Bar Soap').value).toBe('1');
    });
});
