import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, cleanup, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import SearchBar from './SearchBar';

// The rules this screen has to obey, written down as tests because reasoning
// about them was not enough — this box has broken three separate ways in
// production (results for the wrong query, products added that nobody chose,
// a list that would not go away), and every one of them lived in the
// interaction rather than in any single function.
//
// The cashier's contract:
//   - start typing, the list drops down
//   - NOTHING is added until they say which one, by tap or by arrow + Enter
//   - it works with no network at all

const charger = { id: 1, name: 'Samsung 25W Charger', sku: 'SAM-25', barcode: '6001234567890', price: 5300, available_stock: 4 };
const cable   = { id: 2, name: 'Samsung Cable',       sku: 'SAM-CB', barcode: '6009876543210', price: 1500, available_stock: 9 };

let localCatalogue = [];

vi.mock('../lib/db', () => ({
    db: {
        products: {
            filter: (predicate) => ({
                limit: () => ({
                    toArray: async () => localCatalogue.filter(predicate),
                }),
            }),
        },
    },
}));

vi.mock('../lib/api', () => ({
    default: { get: vi.fn(async () => ({ data: [] })) },
}));

const type = (text) => userEvent.type(screen.getByRole('textbox'), text);

beforeEach(() => {
    localCatalogue = [charger, cable];
    vi.stubGlobal('navigator', { ...window.navigator, onLine: true });
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('the list', () => {
    it('drops down once there is something to search for', async () => {
        render(<SearchBar vendorId={1} onSelect={vi.fn()} />);

        await type('samsung');

        expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();
        expect(screen.getByText('Samsung Cable')).toBeDefined();
    });

    it('says so when nothing matches, instead of showing nothing at all', async () => {
        // The regression that made it "worse": the panel was tied to having
        // results, so a search that found none rendered silence.
        render(<SearchBar vendorId={1} onSelect={vi.fn()} />);

        await type('nothing like this');

        expect(await screen.findByText(/No product matches/)).toBeDefined();
    });

    it('goes away once a product has been chosen', async () => {
        render(<SearchBar vendorId={1} onSelect={vi.fn()} />);

        await type('samsung');
        await userEvent.click(await screen.findByText('Samsung Cable'));

        await waitFor(() => expect(screen.queryByText('Samsung Cable')).toBeNull());
    });

    it('goes away when the cashier taps somewhere else', async () => {
        render(
            <div>
                <SearchBar vendorId={1} onSelect={vi.fn()} />
                <button type="button">Somewhere else</button>
            </div>
        );

        await type('samsung');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();

        fireEvent.pointerDown(screen.getByText('Somewhere else'));

        await waitFor(() => expect(screen.queryByText('Samsung Cable')).toBeNull());
    });

    it('goes away on Escape', async () => {
        render(<SearchBar vendorId={1} onSelect={vi.fn()} />);

        await type('samsung');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();

        await userEvent.keyboard('{Escape}');

        await waitFor(() => expect(screen.queryByText('Samsung Cable')).toBeNull());
    });
});

describe('nothing is chosen until the cashier says which one', () => {
    it('adds nothing while typing, however specific the text gets', async () => {
        const onSelect = vi.fn();
        render(<SearchBar vendorId={1} onSelect={onSelect} />);

        await type('Samsung 25W Charger');

        expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();
        expect(onSelect).not.toHaveBeenCalled();
    });

    it('adds nothing on Enter when a single result is showing but nobody pointed at it', async () => {
        // A phone keyboard's Go key sends Enter. This is the exact keypress
        // that used to ring up a product on its own.
        const onSelect = vi.fn();
        localCatalogue = [cable];
        render(<SearchBar vendorId={1} onSelect={onSelect} />);

        await type('cable');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();

        await userEvent.keyboard('{Enter}');

        expect(onSelect).not.toHaveBeenCalled();
    });

    it('adds the row the cashier arrowed onto and pressed Enter on', async () => {
        const onSelect = vi.fn();
        render(<SearchBar vendorId={1} onSelect={onSelect} />);

        await type('samsung');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();

        await userEvent.keyboard('{ArrowDown}{ArrowDown}{Enter}');

        expect(onSelect).toHaveBeenCalledTimes(1);
        expect(onSelect).toHaveBeenCalledWith(cable);
    });

    it('adds the row the cashier clicked', async () => {
        const onSelect = vi.fn();
        render(<SearchBar vendorId={1} onSelect={onSelect} />);

        await type('samsung');
        await userEvent.click(await screen.findByText('Samsung 25W Charger'));

        expect(onSelect).toHaveBeenCalledTimes(1);
        expect(onSelect).toHaveBeenCalledWith(charger);
    });

    it('adds the product whose whole barcode was entered, which is what a scanner sends', async () => {
        const onSelect = vi.fn();
        render(<SearchBar vendorId={1} onSelect={onSelect} />);

        await type('6009876543210');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();

        await userEvent.keyboard('{Enter}');

        expect(onSelect).toHaveBeenCalledWith(cable);
    });
});

describe('with no network', () => {
    it('still finds and adds a product from the local catalogue', async () => {
        vi.stubGlobal('navigator', { ...window.navigator, onLine: false });

        const onSelect = vi.fn();
        render(<SearchBar vendorId={1} onSelect={onSelect} />);

        await type('samsung');
        await userEvent.click(await screen.findByText('Samsung Cable'));

        expect(onSelect).toHaveBeenCalledWith(cable);
    });

    it('never reaches for the network when the local catalogue already answered', async () => {
        const api = (await import('../lib/api')).default;
        api.get.mockClear();

        render(<SearchBar vendorId={1} onSelect={vi.fn()} />);

        await type('samsung');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();

        expect(api.get).not.toHaveBeenCalled();
    });

    it('says nothing matched rather than hanging when the network is unreachable', async () => {
        const api = (await import('../lib/api')).default;
        api.get.mockRejectedValueOnce(new Error('timeout of 5000ms exceeded'));

        render(<SearchBar vendorId={1} onSelect={vi.fn()} />);

        await type('nothing like this');

        expect(await screen.findByText(/No product matches/)).toBeDefined();
    });
});
