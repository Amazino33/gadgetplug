import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import SearchBar from './SearchBar';

// The keyboard-only sell loop: type -> Enter -> quantity -> Enter, and a scan
// that skips the quantity box entirely.
//
// Kept apart from SearchBar.test.jsx deliberately. That file is the contract
// for a bar that has NOT been given these powers — the deliberate-pick rule
// that exists because two softer ones charged customers for goods nobody
// picked — and it must keep passing untouched. This one is the contract for
// the bar POS hands a hardware keyboard to.

const charger = { id: 1, name: 'Samsung 25W Charger', sku: 'SAM-25', barcode: '6001234567890', price: 5300, available_stock: 4 };
const cable   = { id: 2, name: 'Samsung Cable',       sku: 'SAM-CB', barcode: '6009876543210', price: 1500, available_stock: 9 };
// Real shape of this catalogue: imports have left products on single-digit SKUs.
const battery = { id: 3, name: 'BATTERY BL-5C',       sku: '5',      barcode: null,            price: 1695, available_stock: 655 };

let localCatalogue = [];

vi.mock('../lib/db', () => ({
    db: {
        products: {
            filter: (predicate) => ({
                limit: () => ({ toArray: async () => localCatalogue.filter(predicate) }),
            }),
        },
    },
}));

vi.mock('../lib/api', () => ({ default: { get: vi.fn() } }));

const serverAgreesWithDevice = async (url, config) => {
    const q = (config?.params?.q ?? '').toLowerCase();

    return {
        data: localCatalogue.filter((p) =>
            String(p.barcode ?? '').toLowerCase() === q ||
            String(p.sku ?? '').toLowerCase() === q ||
            p.name.toLowerCase().includes(q)
        ),
    };
};

// The counter's till: a real keyboard, so the closest match highlights itself.
const till = (props = {}) => render(
    <SearchBar vendorId={1} autoHighlight onSelect={vi.fn()} onScan={vi.fn()} {...props} />
);

const box  = () => screen.getByRole('textbox', { name: /search products/i });
const type = (text) => userEvent.type(box(), text);
const rowFor = (name) => screen.getByText(name).closest('button');

beforeEach(async () => {
    localCatalogue = [charger, cable];
    vi.stubGlobal('navigator', { ...window.navigator, onLine: true });

    const api = (await import('../lib/api')).default;
    api.get.mockReset();
    api.get.mockImplementation(serverAgreesWithDevice);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('the closest match highlights itself', () => {
    it('so Enter adds it with no arrow key in between', async () => {
        const onSelect = vi.fn();
        till({ onSelect });

        await type('samsung');
        expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();
        await waitFor(() => expect(rowFor('Samsung 25W Charger').getAttribute('aria-selected')).toBe('true'));

        await userEvent.keyboard('{Enter}');

        expect(onSelect).toHaveBeenCalledTimes(1);
        expect(onSelect).toHaveBeenCalledWith(charger);
    });

    it('and the arrows still move it, starting from the row on screen', async () => {
        // The bug this guards: the stored index starts at -1 while the drawn
        // highlight is on row 0, so a naive first ArrowDown moves -1 -> 0 and
        // looks like it did nothing.
        const onSelect = vi.fn();
        till({ onSelect });

        await type('samsung');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();

        await userEvent.keyboard('{ArrowDown}');
        await waitFor(() => expect(rowFor('Samsung Cable').getAttribute('aria-selected')).toBe('true'));

        await userEvent.keyboard('{Enter}');

        expect(onSelect).toHaveBeenCalledWith(cable);
    });

    it('but never on a bar without a hardware keyboard behind it', async () => {
        // The mobile bar. Its Enter is the on-screen keyboard's Go key.
        const onSelect = vi.fn();
        render(<SearchBar vendorId={1} onSelect={onSelect} onScan={vi.fn()} />);

        await type('samsung');
        expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();

        await userEvent.keyboard('{Enter}');

        expect(onSelect).not.toHaveBeenCalled();
    });

    it('and never on a single digit that happens to be a real product SKU', async () => {
        // The incident that removed the first version of this rule. Still inert.
        const onSelect = vi.fn();
        localCatalogue = [battery];
        till({ onSelect });

        await type('5');
        expect(await screen.findByText('BATTERY BL-5C')).toBeDefined();

        await userEvent.keyboard('{Enter}');

        expect(onSelect).not.toHaveBeenCalled();
    });

    it('and never on an empty box', async () => {
        const onSelect = vi.fn();
        const onScan   = vi.fn();
        till({ onSelect, onScan });

        box().focus();
        await userEvent.keyboard('{Enter}');

        expect(onSelect).not.toHaveBeenCalled();
        expect(onScan).not.toHaveBeenCalled();
    });
});

describe('a scan is one action', () => {
    it('goes straight into the sale, skipping the quantity box', async () => {
        const onSelect = vi.fn();
        const onScan   = vi.fn();
        till({ onSelect, onScan });

        await type('6009876543210');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();

        await userEvent.keyboard('{Enter}');

        expect(onScan).toHaveBeenCalledTimes(1);
        expect(onScan).toHaveBeenCalledWith(cable);
        // onSelect is what opens the quantity box. A scan must not.
        expect(onSelect).not.toHaveBeenCalled();
    });

    it('leaves the box empty and holding the keyboard, ready for the next one', async () => {
        till();

        await type('6009876543210');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();
        await userEvent.keyboard('{Enter}');

        await waitFor(() => expect(box().value).toBe(''));
        expect(document.activeElement).toBe(box());
    });

    it('works on the mobile bar too — an exact barcode cannot mean anything else', async () => {
        const onScan = vi.fn();
        render(<SearchBar vendorId={1} onSelect={vi.fn()} onScan={onScan} />);

        await type('6009876543210');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();
        await userEvent.keyboard('{Enter}');

        expect(onScan).toHaveBeenCalledWith(cable);
    });

    it('beats the search debounce, which is how a wedge actually arrives', async () => {
        // A scanner sends its payload and an Enter inside the 120ms the box
        // waits before searching at all, so nothing is on screen yet. Enter
        // has to finish the search it interrupted rather than read a list
        // that describes a half-typed barcode.
        const onScan = vi.fn();
        till({ onScan });

        // No findByText first: the Enter follows the last digit immediately.
        await type('6009876543210');
        await userEvent.keyboard('{Enter}');

        await waitFor(() => expect(onScan).toHaveBeenCalledWith(cable));
    });

    it('says so when nothing carries the barcode, and opens nothing', async () => {
        const onSelect = vi.fn();
        const onScan   = vi.fn();
        till({ onSelect, onScan });

        await type('9999999999999');
        await userEvent.keyboard('{Enter}');

        expect(await screen.findByText(/No product for barcode 9999999999999/)).toBeDefined();
        expect(onScan).not.toHaveBeenCalled();
        expect(onSelect).not.toHaveBeenCalled();
        // Cleared, or the next scan lands concatenated onto this one.
        await waitFor(() => expect(box().value).toBe(''));
        expect(document.activeElement).toBe(box());
    });

    it('does not call a product a scan just because its name contains the digits', async () => {
        const onSelect = vi.fn();
        const onScan   = vi.fn();
        localCatalogue = [{ id: 9, name: 'Powerbank 9999999999999mAh', sku: 'PB-1', barcode: '111', price: 9000, available_stock: 2 }];
        till({ onSelect, onScan });

        await type('9999999999999');
        expect(await screen.findByText(/Powerbank/)).toBeDefined();

        await userEvent.keyboard('{Enter}');

        expect(onScan).not.toHaveBeenCalled();
        // And it does not fall through to the highlight either — the cashier
        // scanned something, and being handed a quantity prompt for a product
        // that merely mentions those digits is worse than being told no.
        expect(onSelect).not.toHaveBeenCalled();
        expect(await screen.findByText(/No product for barcode/)).toBeDefined();
    });

    it('leaves a short numeric SKU to the ordinary pick rules', async () => {
        // Four digits is below the barcode floor, so this is not a scan and
        // not a "no product for barcode" either — it is just a search.
        const onSelect = vi.fn();
        const onScan   = vi.fn();
        localCatalogue = [{ id: 7, name: 'Aux Lead', sku: '4471', barcode: null, price: 700, available_stock: 5 }];
        till({ onSelect, onScan });

        await type('4471');
        expect(await screen.findByText('Aux Lead')).toBeDefined();

        await userEvent.keyboard('{Enter}');

        expect(onScan).not.toHaveBeenCalled();
        expect(screen.queryByText(/No product for barcode/)).toBeNull();
        // Four characters clears the auto-highlight floor, so this is a pick.
        expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }));
    });
});
