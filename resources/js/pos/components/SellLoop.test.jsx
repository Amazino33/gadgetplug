import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useCallback, useRef, useState } from 'react';
import SearchBar from './SearchBar';
import QuantityModal from './QuantityModal';
import { addToCart } from '../lib/cartAdd';

// The whole loop a cashier actually performs, driven end to end:
//
//   type -> Enter -> quantity -> Enter -> in the cart -> back in the search box
//
// and the scan that skips the middle of it. Written as an interaction test
// rather than as assertions about handlers because every bug this box has had
// in production lived in the interaction — a quantity box that opened without
// the keyboard, a highlight that pointed at a row other than the one Enter
// took, an Enter that both opened the box and confirmed it.
//
// The harness mirrors POS's wiring (same components, same addToCart, same
// pending-product rule) so the test can run without a session, a network or
// a Dexie database behind it.

const charger = { id: 1, name: 'Samsung 25W Charger', sku: 'SAM-25', barcode: '6001234567890', price: 5300, available_stock: 4 };
const cable   = { id: 2, name: 'Samsung Cable',       sku: 'SAM-CB', barcode: '6009876543210', price: 1500, available_stock: 9 };

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

function Till() {
    const [cart, setCart] = useState([]);
    const [pending, setPending] = useState(null);
    const searchRef = useRef(null);

    const addProduct = useCallback((product, qty = 1) => {
        setCart((prev) => addToCart(prev, product, qty).items);
    }, []);

    // POS owns the caret centrally: back in the search box whenever nothing
    // is covering the till. Same rule here, same reason.
    const handOverKeyboard = (adding) => {
        if (adding) searchRef.current?.blur();
        else setTimeout(() => searchRef.current?.focus(), 0);
    };

    return (
        <div>
            <SearchBar
                ref={searchRef}
                vendorId={1}
                autoHighlight
                onSelect={(p) => { setPending(p); handOverKeyboard(true); }}
                onScan={addProduct}
            />
            {pending && (
                <QuantityModal
                    item={{ ...pending, qty: 1 }}
                    title="Add to Sale"
                    onConfirm={(qty) => {
                        if (qty >= 1) addProduct(pending, qty);
                        setPending(null);
                        handOverKeyboard(false);
                    }}
                    onClose={() => { setPending(null); handOverKeyboard(false); }}
                />
            )}
            <ul aria-label="Cart">
                {cart.map((i) => <li key={i.id}>{i.name} x{i.qty}</li>)}
            </ul>
        </div>
    );
}

const box         = () => screen.getByRole('textbox', { name: /search products/i });
const quantityBox = () => screen.queryByRole('textbox', { name: /quantity/i });
const cartLines   = () => screen.getAllByRole('listitem').map((li) => li.textContent);
const type        = (text) => userEvent.type(box(), text);

beforeEach(async () => {
    localCatalogue = [charger, cable];
    vi.stubGlobal('navigator', { ...window.navigator, onLine: true });

    const api = (await import('../lib/api')).default;
    api.get.mockReset();
    api.get.mockImplementation(async (url, config) => {
        const q = (config?.params?.q ?? '').toLowerCase();

        return {
            data: localCatalogue.filter((p) =>
                String(p.barcode ?? '').toLowerCase() === q ||
                String(p.sku ?? '').toLowerCase() === q ||
                p.name.toLowerCase().includes(q)
            ),
        };
    });
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('the keyboard-only sell loop', () => {
    it('runs type, Enter, quantity, Enter — and hands the keyboard back', async () => {
        render(<Till />);

        await type('charger');
        expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();

        // No arrow key: the closest match is already highlighted.
        await userEvent.keyboard('{Enter}');
        await waitFor(() => expect(document.activeElement).toBe(quantityBox()));

        // The 1 is selected, so the digit types over it rather than after it.
        await userEvent.keyboard('3{Enter}');

        await waitFor(() => expect(cartLines()).toEqual(['Samsung 25W Charger x3']));
        expect(quantityBox()).toBeNull();
        await waitFor(() => expect(document.activeElement).toBe(box()));
        expect(box().value).toBe('');
    });

    it('takes one when the cashier just presses Enter twice', async () => {
        render(<Till />);

        await type('charger');
        expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();
        await userEvent.keyboard('{Enter}');
        await waitFor(() => expect(document.activeElement).toBe(quantityBox()));
        await userEvent.keyboard('{Enter}');

        await waitFor(() => expect(cartLines()).toEqual(['Samsung 25W Charger x1']));
    });

    it('adds nothing at all when the quantity box is escaped', async () => {
        // The regression this exists for: the line used to be seated in the
        // cart at qty 1 the moment the product was picked, so Escape left the
        // cashier with goods on the sale and nothing on screen to say so.
        render(<Till />);

        await type('charger');
        expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();
        await userEvent.keyboard('{Enter}');
        await waitFor(() => expect(document.activeElement).toBe(quantityBox()));

        await userEvent.keyboard('{Escape}');

        expect(quantityBox()).toBeNull();
        expect(screen.queryAllByRole('listitem')).toHaveLength(0);
        await waitFor(() => expect(document.activeElement).toBe(box()));
    });

    it('grows the line instead of starting a second one', async () => {
        render(<Till />);

        for (const qty of ['2', '3']) {
            await type('charger');
            expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();
            await userEvent.keyboard('{Enter}');
            await waitFor(() => expect(document.activeElement).toBe(quantityBox()));
            await userEvent.keyboard(`${qty}{Enter}`);
            await waitFor(() => expect(quantityBox()).toBeNull());
        }

        // Two then three is five, not three, and not two lines.
        expect(cartLines()).toEqual(['Samsung 25W Charger x5']);
    });

    it('rings a scan straight up without ever showing the quantity box', async () => {
        render(<Till />);

        await type('6009876543210');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();
        await userEvent.keyboard('{Enter}');

        await waitFor(() => expect(cartLines()).toEqual(['Samsung Cable x1']));
        expect(quantityBox()).toBeNull();
        expect(box().value).toBe('');
        expect(document.activeElement).toBe(box());
    });

    it('counts each scan of the same product', async () => {
        render(<Till />);

        for (let i = 0; i < 3; i++) {
            await type('6009876543210');
            expect(await screen.findByText('Samsung Cable')).toBeDefined();
            await userEvent.keyboard('{Enter}');
            await waitFor(() => expect(box().value).toBe(''));
        }

        expect(cartLines()).toEqual(['Samsung Cable x3']);
    });

    it('mixes scanned and typed helpings of one product on one line', async () => {
        render(<Till />);

        await type('6009876543210');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();
        await userEvent.keyboard('{Enter}');
        await waitFor(() => expect(cartLines()).toEqual(['Samsung Cable x1']));

        await type('cable');
        expect(await screen.findByText('Samsung Cable')).toBeDefined();
        await userEvent.keyboard('{Enter}');
        await waitFor(() => expect(document.activeElement).toBe(quantityBox()));
        await userEvent.keyboard('4{Enter}');

        await waitFor(() => expect(cartLines()).toEqual(['Samsung Cable x5']));
    });

    it('never types the quantity into the search box behind the popup', async () => {
        render(<Till />);

        await type('charger');
        expect(await screen.findByText('Samsung 25W Charger')).toBeDefined();
        await userEvent.keyboard('{Enter}');
        await waitFor(() => expect(document.activeElement).toBe(quantityBox()));

        await userEvent.keyboard('7');

        expect(box().value).toBe('');
        expect(quantityBox().value).toBe('7');
    });
});
