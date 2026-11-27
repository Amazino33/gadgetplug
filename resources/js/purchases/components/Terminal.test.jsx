import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, act, cleanup } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const products = Object.fromEntries(
    ['111', '222', '333', '444', '555'].map((code, i) => [code, {
        id: i + 1, name: `Item ${code}`, barcode: code, sku: null, base_unit: 'Piece',
        price: 500, cost_price: 300, track_serial: false, scanned_unit_id: null,
        units: [{ id: 100 + i, name: 'Piece', factor: 1, is_base: true }],
    }]),
);

vi.mock('../lib/api', () => ({
    default: {
        get: vi.fn(async (url, { params } = {}) => {
            if (url === '/suppliers') return { data: { suppliers: [] } };
            // The whole catalogue, one short page: scans of these are answered
            // from memory and never reach /lookup.
            if (url === '/catalogue') return { data: Object.values(products) };
            if (url === '/lookup') {
                const p = products[params.code];
                return { data: p ? { found: true, product: p } : { found: false, code: params.code } };
            }
            if (url === '/products') return { data: { products: [] } };
            return { data: {} };
        }),
        post: vi.fn(),
    },
    errorMessage: () => 'error',
    fieldErrors: () => ({}),
}));

import Terminal from './Terminal';

const scan = async (user, code) => {
    // A scanner is a keyboard: the code, then Enter, into whatever has focus.
    await user.keyboard(`${code}{Enter}`);
};

describe('Purchase Terminal scanning', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(() => { cleanup(); localStorage.clear(); });

    it('takes five different scans in a row without the input being clicked', async () => {
        const user = userEvent.setup();
        render(<Terminal active resume={null} onResumed={() => {}} onSaved={() => {}} />);

        const box = screen.getByLabelText('Scan barcode or search products');
        await waitFor(() => expect(document.activeElement).toBe(box));
        await screen.findByText(/5 products/);

        for (const code of ['111', '222', '333', '444', '555']) {
            await scan(user, code);
        }

        await waitFor(() => expect(screen.getAllByText(/^Item \d{3}$/)).toHaveLength(5));
        expect(box.value).toBe('');
        // Instant: every one was answered from the in-memory catalogue.
        const api = (await import('../lib/api')).default;
        expect(api.get.mock.calls.filter(([url]) => url === '/lookup')).toHaveLength(0);
        await waitFor(() => expect(document.activeElement).toBe(box));
    });

    it('a second scan of the same item increases its quantity', async () => {
        const user = userEvent.setup();
        render(<Terminal active resume={null} onResumed={() => {}} onSaved={() => {}} />);
        await waitFor(() => expect(document.activeElement).toBe(screen.getByLabelText('Scan barcode or search products')));

        await scan(user, '111');
        await waitFor(() => expect(screen.getByLabelText('Quantity of Item 111')).toHaveProperty('value', '1'));
        await scan(user, '111');

        await waitFor(() => expect(screen.getByLabelText('Quantity of Item 111')).toHaveProperty('value', '2'));
        expect(screen.getAllByText('Item 111')).toHaveLength(1);
    });

    it('an unknown barcode opens the create-product modal with it filled in, and closing it refocuses the scan box', async () => {
        const user = userEvent.setup();
        render(<Terminal active resume={null} onResumed={() => {}} onSaved={() => {}} />);
        const box = screen.getByLabelText('Scan barcode or search products');
        await waitFor(() => expect(document.activeElement).toBe(box));

        await scan(user, '9990001');

        expect(await screen.findByText('New product')).toBeTruthy();
        expect(screen.getByDisplayValue('9990001')).toBeTruthy();

        await act(async () => { await user.keyboard('{Escape}'); });

        await waitFor(() => expect(screen.queryByText('New product')).toBeNull());
        await waitFor(() => expect(document.activeElement).toBe(box));
    });
});

describe('Purchase Terminal search list', () => {
    afterEach(() => { cleanup(); localStorage.clear(); });

    it('scrolls to keep the row the arrow keys reach in view', async () => {
        const scrolled = [];
        const original = Element.prototype.scrollIntoView;
        Element.prototype.scrollIntoView = function () { scrolled.push(this); };

        try {
            const user = userEvent.setup();
            render(<Terminal active resume={null} onResumed={() => {}} onSaved={() => {}} />);
            await screen.findByText(/5 products/);

            await user.keyboard('item');
            await screen.findAllByRole('option');
            await user.keyboard('{ArrowDown}{ArrowDown}{ArrowDown}');

            const options = screen.getAllByRole('option');
            expect(options[2].getAttribute('aria-selected')).toBe('true');
            expect(scrolled.at(-1)).toBe(options[2]);
        } finally {
            Element.prototype.scrollIntoView = original;
        }
    });
});
