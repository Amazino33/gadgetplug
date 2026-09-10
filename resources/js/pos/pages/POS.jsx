import { useState, useRef, useEffect, useCallback } from 'react';
import { useKeyboard } from '../hooks/useKeyboard';
import { useSync } from '../hooks/useSync';
import { fmt, generateOfflineId } from '../lib/format';
import { createCheckoutId } from '../lib/checkoutId';
import { cartFloorTotal } from '../lib/cartFloor';
import { addToCart } from '../lib/cartAdd';
import { shouldRedirectTypingToSearch } from '../lib/typeAhead';
import { db } from '../lib/db';
import { pruneOldSales, recordSale } from '../lib/salesHistory';
import api from '../lib/api';
import Cart from '../components/Cart';
import SearchBar from '../components/SearchBar';
import SuspendedSalesModal from '../components/SuspendedSalesModal';
import BarcodeScanner from '../components/BarcodeScanner';
import ActionGrid from '../components/ActionGrid';
import PaymentModal from '../components/PaymentModal';
import CustomerModal from '../components/CustomerModal';
import DiscountModal from '../components/DiscountModal';
import QuantityModal from '../components/QuantityModal';
import PriceModal from '../components/PriceModal';
import ReturnModal from '../components/ReturnModal';
import ZReportModal from '../components/ZReportModal';
import ReceiptModal from '../components/ReceiptModal';
import StuckSalesModal from '../components/StuckSalesModal';
import SalesHistoryModal from '../components/SalesHistoryModal';
import PickingsModal from '../components/PickingsModal';
import CashSubmitModal from '../components/CashSubmitModal';

const CONFIG = window.POS_CONFIG ?? {};

// Small button used inside the mobile "More" sheet
const SheetBtn = ({ label, onClick, disabled = false, color = 'gray' }) => {
    const colors = {
        gray:   'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300',
        green:  'bg-green-50 dark:bg-green-950 text-green-700 dark:text-green-400',
        orange: 'bg-orange-50 dark:bg-orange-950 text-orange-600 dark:text-orange-400',
        red:    'bg-red-50 dark:bg-red-950 text-red-600 dark:text-red-400',
        blue:   'bg-blue-50 dark:bg-blue-950 text-blue-600 dark:text-blue-400',
    };
    return (
        <button
            onClick={onClick}
            disabled={disabled}
            className={`py-3 rounded-xl text-xs font-bold transition-all active:scale-95
                ${colors[color]} ${disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'}`}
        >
            {label}
        </button>
    );
};

export default function POS({ user, vendorId, onLogout }) {
    const vendorSettings = JSON.parse(localStorage.getItem('pos_vendor_settings') ?? '{}');
    const VAT_ENABLED = vendorSettings.vat_enabled ?? true;
    const VAT_RATE    = vendorSettings.vat_rate    ?? 7.5;

    const [cart, setCart]                 = useState([]);
    const [selectedIdx, setSelectedIdx]   = useState(null);
    // Picked from the search box, not yet in the sale. Held here rather than
    // added so that Escape at the quantity box means nothing happened.
    const [pendingProduct, setPendingProduct] = useState(null);
    const [customer, setCustomer]         = useState(null);
    const [cartDiscount, setCartDiscount] = useState({ amount: 0, type: 'fixed', approvedBy: null });
    // Set when a refused sale is pulled back in, so the corrected sale is
    // recorded on the day the goods actually left rather than the day it was
    // put right. Null for an ordinary sale.
    const [recoveredAt, setRecoveredAt]   = useState(null);
    const [session, setSession]           = useState(() => {
        const s = localStorage.getItem('pos_session');
        return s ? JSON.parse(s) : null;
    });
    const [isOnline, setIsOnline]         = useState(navigator.onLine);
    const [modal, setModal]               = useState(null);
    const [lastSale, setLastSale]         = useState(null);
    const [saleError, setSaleError]       = useState(null);
    const [stuckSales, setStuckSales]     = useState([]);
    const [pendingSales, setPendingSales] = useState([]);
    const [pendingError, setPendingError] = useState(null);
    const [isReprintView, setIsReprintView] = useState(false);
    const [showMobileMore, setShowMobileMore] = useState(false);
    const [isDark, setIsDark] = useState(() => localStorage.getItem('darkMode') === 'true');

    const toggleDark = () => {
        const next = !isDark;
        setIsDark(next);
        localStorage.setItem('darkMode', String(next));
        document.documentElement.classList.toggle('dark', next);
    };

    const searchRef = useRef(null);

    const { syncNow } = useSync(vendorId, setStuckSales);

    // Older than the retention window is not this till's business to hold.
    useEffect(() => {
        pruneOldSales().catch(() => {});
    }, []);

    useEffect(() => {
        const on  = () => setIsOnline(true);
        const off = () => setIsOnline(false);
        window.addEventListener('online', on);
        window.addEventListener('offline', off);
        return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off); };
    }, []);

    useEffect(() => {
        if (!session) openSession();
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const openSession = async () => {
        try {
            const { data } = await api.post('/sessions/open', { vendor_id: vendorId, opening_float: 0 });
            setSession(data);
            localStorage.setItem('pos_session', JSON.stringify(data));
        } catch { /* offline — continue */ }
    };

    // ── Pending (suspended) sales ────────────────────────────────────
    // No slots, no popup: any held sale just shows up in the sidebar for
    // whichever cashier gets to it. Polled so a sale suspended on one
    // terminal shows up here without needing a manual refresh.

    const loadPendingSales = useCallback(async () => {
        try {
            const { data } = await api.get('/suspended', { params: { vendor_id: vendorId } });
            setPendingSales(data);
        } catch { /* offline — keep showing the last known list */ }
    }, [vendorId]);

    useEffect(() => {
        loadPendingSales();
        const interval = setInterval(loadPendingSales, 20000);
        return () => clearInterval(interval);
    }, [loadPendingSales]);

    const suspendCurrentSale = async () => {
        if (cartEmpty) return;
        setPendingError(null);
        try {
            await api.post('/suspended', {
                vendor_id:   vendorId,
                customer_id: customer?.id ?? null,
                label:       customer?.name ?? null,
                cart_data:   { items: cart, customer },
            });
            clearCart();
            loadPendingSales();
        } catch {
            setPendingError("Couldn't hold this sale — it has NOT been saved. Check your connection and try again.");
        }
    };

    const resumePendingSale = async (sale) => {
        if (!cartEmpty) return;
        setPendingError(null);
        try {
            const { data } = await api.post(`/suspended/${sale.id}/resume`, { vendor_id: vendorId });
            setCart(data.cart_data.items || []);
            setCustomer(data.cart_data.customer || null);
            loadPendingSales();
        } catch {
            setPendingError("Couldn't resume this sale — check your connection and try again.");
        }
    };

    const clearPendingSale = async (id) => {
        setPendingError(null);
        try {
            await api.delete(`/suspended/${id}`, { data: { vendor_id: vendorId } });
            loadPendingSales();
        } catch {
            setPendingError("Couldn't discard this held sale — check your connection and try again.");
        }
    };

    // ── Cart operations ──────────────────────────────────────────────

    /**
     * Puts goods in the cart. The only function that does.
     *
     * Adding, never setting: a product already on the sale gains the quantity
     * rather than being replaced by it. Scanning the same charger three times
     * means three chargers, and asking for two of something already sitting
     * at four means six — which is what a cashier reaching for a second
     * handful means, and the opposite of what the quantity box does when it
     * is opened on an existing cart line to correct it.
     *
     * It used to seat the line here and open the quantity box afterwards, so
     * a cashier who picked the wrong product and pressed Escape was left with
     * it in the sale at qty 1 with nothing on screen to say so. Nothing enters
     * the cart now until a quantity is confirmed.
     */
    const addProduct = useCallback((product, qty = 1) => {
        setCart((prev) => {
            const { items, index } = addToCart(prev, product, qty);
            setSelectedIdx(index);
            return items;
        });

        // No focus call here on purpose. The effect below owns the caret and
        // already runs on every cart change — doing it here as well meant a
        // timer racing whatever modal was opening next for the keyboard.
    }, []);

    const askQuantityFor = useCallback((product) => {
        setPendingProduct(product);
        setModal('addQuantity');
    }, []);

    const cancelPending = useCallback(() => {
        setPendingProduct(null);
        setModal(null);
    }, []);

    const confirmPending = useCallback((qty) => {
        // Zero is how the quantity box removes a cart line. Nothing to remove
        // here — this product was never added — so it just means "never mind".
        if (pendingProduct && qty >= 1) addProduct(pendingProduct, qty);
        cancelPending();
    }, [pendingProduct, addProduct, cancelPending]);

    // Haggling still starts from the quantity box rather than costing the
    // cashier a trip through the cart: the line goes in at the quantity
    // typed, and the price modal opens on it. addProduct has already pointed
    // selectedIdx at it by the time this renders.
    const negotiatePending = useCallback((qty) => {
        if (! pendingProduct) return;

        addProduct(pendingProduct, qty);
        setPendingProduct(null);
        setModal('price');
    }, [pendingProduct, addProduct]);

    const updateQty = (idx, qty) => {
        if (qty < 1) { removeItem(idx); return; }
        setCart((prev) => { const u = [...prev]; u[idx] = { ...u[idx], qty }; return u; });
    };

    const updatePrice = (idx, price) => {
        setCart((prev) => {
            const u = [...prev];
            const floor = u[idx].min_price ?? u[idx].listPrice ?? u[idx].price;
            // Belt and braces — PriceModal already blocks this, and the server
            // refuses it outright, but nothing here should be able to seat a
            // below-floor price in the cart in the first place.
            u[idx] = { ...u[idx], price: Math.max(price, floor) };
            return u;
        });
    };

    const removeItem = (idx) => {
        setCart((prev) => prev.filter((_, i) => i !== idx));
        setSelectedIdx(null);
    };

    const handleCartSelect = (idx) => {
        setSelectedIdx(idx);
        if (window.innerWidth < 768) setModal('quantity');
    };

    const clearCart = () => {
        setCart([]);
        setRecoveredAt(null);
        setSelectedIdx(null);
        setCustomer(null);
        setCartDiscount({ amount: 0, type: 'fixed', approvedBy: null });
    };

    // ── Totals ───────────────────────────────────────────────────────

    const subtotal       = cart.reduce((s, i) => s + i.price * i.qty - (i.lineDiscount || 0), 0);

    // The least this cart may sell for, the same sum the server works out in
    // PosPriceFloor::guard(). Every line can sit exactly on its own floor and
    // still be dragged under it by a discount applied to the whole cart — which
    // the till used to allow and the server then refused. Offline that refusal
    // arrives long after the customer has gone, as a sale that can never sync.
    const cartFloor      = cartFloorTotal(cart);
    const discountAmount = cartDiscount.type === 'percentage'
        ? subtotal * (cartDiscount.amount / 100)
        : cartDiscount.amount;
    const vatAmount = VAT_ENABLED ? (subtotal - discountAmount) * (VAT_RATE / 100) : 0;
    const total     = subtotal - discountAmount + vatAmount;
    const cartEmpty = cart.length === 0;

    // ── Complete sale ────────────────────────────────────────────────

    // Guards one checkout from being rung up twice.
    //
    // A ref and not state: two taps a few milliseconds apart both run before
    // React has re-rendered, so both would read the same stale `false` from
    // state and both would submit. A ref updates synchronously, so the second
    // tap sees the first one's flag and stops.
    const submittingRef = useRef(false);
    const [submitting, setSubmitting] = useState(false);

    // The id that identifies THIS checkout to the server, held still across
    // repeated attempts at it. See lib/checkoutId for why that matters and what
    // it used to do instead.
    const checkoutRef = useRef(null);
    checkoutRef.current ??= createCheckoutId(generateOfflineId);

    const completeSale = async ({ paymentMethod, amountTendered, bankRef, payments }) => {
        if (submittingRef.current) return;

        submittingRef.current = true;
        setSubmitting(true);

        try {
            await submitSale({ paymentMethod, amountTendered, bankRef, payments });
        } finally {
            submittingRef.current = false;
            setSubmitting(false);
        }
    };

    const submitSale = async ({ paymentMethod, amountTendered, bankRef, payments }) => {
        const isSplit = paymentMethod === 'split';

        const payload = {
            offline_id:              checkoutRef.current.forAttempt(),
            vendor_id:               vendorId,
            pos_session_id:          session?.id ?? null,
            customer_id:             customer?.id ?? null,
            items: cart.map((item) => ({
                product_id:      item.id,
                product_name:    item.name,
                product_sku:     item.sku ?? null,
                unit_price:      item.price,
                quantity:        item.qty,
                discount_amount: item.lineDiscount || 0,
                total:           item.price * item.qty - (item.lineDiscount || 0),
            })),
            discount_amount:         discountAmount,
            discount_type:           cartDiscount.type,
            discount_scope:          'cart',
            discount_approved_by:    cartDiscount.approvedBy,
            vat_rate:                VAT_RATE,
            subtotal,
            vat_amount:              vatAmount,
            total,
            payment_method:          paymentMethod,
            amount_tendered:         amountTendered,
            change_given:            Math.max(0, amountTendered - total),
            bank_transfer_reference: isSplit ? null : (bankRef ?? null),
            payments:                isSplit ? payments : null,
            completed_at:            recoveredAt ?? new Date().toISOString(),
        };

        let savedSale = { ...payload };

        // A recovered sale goes back through the offline queue even when the
        // till is online, because that is the only path that keeps the date it
        // carries: the live endpoint stamps the moment the sale reaches it, so
        // yesterday's goods would land in today's takings. The queue is drained
        // immediately below, so it still goes up straight away.
        if (isOnline && ! recoveredAt) {
            try {
                const { data } = await api.post('/sales', payload);
                savedSale = { ...payload, id: data.id, reference: data.reference };
                // Kept on the device too. offlineSales below is a queue for
                // getting a sale uploaded; this is so the cashier can still
                // look it up afterwards, which the server cannot answer when
                // the connection is gone.
                await recordSale(savedSale, user.id);
            } catch (err) {
                if (err.response) {
                    // The server was reached and refused the sale (insufficient
                    // stock, a price below floor, etc.) — this is NOT a
                    // connectivity problem. Queuing it "for later" would just
                    // fail identically forever while the till shows a fake
                    // success receipt. Stop here so the cashier can fix it now.
                    setSaleError(err.response.data?.message || 'This sale was rejected by the server.');
                    return;
                }
                // No response at all reached us — genuine network failure, safe to queue.
                await db.offlineSales.add({ ...payload, synced: 0 });
                await recordSale(payload, user.id);
            }
        } else {
            await db.offlineSales.add({ ...payload, synced: 0 });
            await recordSale(payload, user.id);
        }

        const receiptSale = {
            // Carried through so the receipt can be printed from its own
            // server-rendered document. Without it ReceiptModal falls back to
            // printing this modal, which has no QR and no vendor settings on it.
            // Null while offline — the sale has no server id until it syncs.
            id:                      savedSale.id ?? null,
            reference:               savedSale.reference ?? payload.offline_id,
            items:                   payload.items,
            subtotal,
            discount_amount:         discountAmount,
            vat_amount:              vatAmount,
            total,
            payment_method:          paymentMethod,
            amount_tendered:         amountTendered,
            change_given:            Math.max(0, amountTendered - total),
            bank_transfer_reference: isSplit ? null : (bankRef ?? null),
            payments:                isSplit ? payments : null,
            customer,
        };

        // This checkout is done, so the next customer starts a new one.
        checkoutRef.current.settled();

        // Queued rather than posted, so nudge the sync instead of waiting for
        // its next cycle — the cashier should not be left wondering.
        if (recoveredAt) {
            setRecoveredAt(null);
            syncNow();
        }

        clearCart();
        setModal(null);
        setIsReprintView(false);
        setLastSale(receiptSale);
    };

    // ── Focus (desktop) ──────────────────────────────────────────────

    // Anything covering the till owns the keyboard while it is up.
    const tillIsCovered = Boolean(modal || lastSale || saleError || submitting || showMobileMore);

    // Never taken off a field someone is actually filling in — including the
    // search box itself, where this is a no-op anyway.
    const focusSearch = useCallback(() => {
        const tag = document.activeElement?.tagName;

        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;

        searchRef.current?.focus();
    }, []);

    // The caret belongs in the search box whenever nothing is covering the
    // till, so the next scan or product name just types. Owned here rather
    // than at each of the twenty-odd places that close something: a modal
    // added later cannot forget to hand focus back, because it never had to
    // remember. Runs on cart changes too — clicking a row or a bin icon puts
    // focus on that button, and the next thing the cashier does is type.
    //
    // Only the desktop search bar carries searchRef, and it is display:none
    // below md, so this is inert on a phone — where forcing focus would mean
    // an on-screen keyboard shoving the till off the screen after every tap.
    useEffect(() => {
        if (tillIsCovered) {
            // Hand the keyboard away the moment something covers the till.
            // A popup owns its own focus, but if it ever fails to take it,
            // the caret must not still be sitting in the search box behind —
            // that is how a typed quantity ends up as invisible text in the
            // product search instead. Only ever the search box, never
            // whatever the popup has just focused: a child's effect runs
            // before its parent's, so by here the popup already holds it.
            searchRef.current?.blur();

            return;
        }

        const t = setTimeout(focusSearch, 60);

        return () => clearTimeout(t);
    }, [tillIsCovered, cart, focusSearch]);

    // And if a keystroke does land on the page at large — focus lost to a
    // button, or a handheld scanner firing at whatever happens to be focused
    // — it is redirected into the search box rather than dropped. Focusing
    // during keydown lets the character itself land in the box.
    useEffect(() => {
        const onKeyDown = (e) => {
            if (! shouldRedirectTypingToSearch({
                key: e.key,
                ctrlKey: e.ctrlKey,
                metaKey: e.metaKey,
                altKey: e.altKey,
                activeTag: document.activeElement?.tagName,
                blocked: tillIsCovered,
            })) return;

            searchRef.current?.focus();
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [tillIsCovered]);

    // ── Keyboard shortcuts (desktop) ─────────────────────────────────

    // Function keys and Escape keep working while the cashier is typing —
    // they have to, now that the search box holds focus almost all the time.
    // Left on the default (ignored while a field has focus) they would have
    // gone quiet exactly when the till became keyboard-first.
    useKeyboard({
        F2:     () => { if (!lastSale && !cartEmpty) setModal('discount'); },
        F3:     () => { if (!lastSale) searchRef.current?.focus(); },
        F4:     () => { if (!lastSale && selectedIdx !== null) setModal('quantity'); },
        // Was 'c'. A bare letter cannot be a shortcut on a till that puts
        // every letter into the search box — it would open this instead of
        // typing the first character of "cable".
        F6:     () => { if (!lastSale) setModal('customer'); },
        F8:     () => { if (!lastSale) clearCart(); },
        // F7 and F11 were printed on the buttons but never bound to anything,
        // so the two fastest ways to take a card or transfer payment did
        // nothing at all when pressed.
        F7:     () => { if (!lastSale && !cartEmpty) completeSale({ paymentMethod: 'bank_transfer', amountTendered: total }); },
        F9:     () => { if (!lastSale && !cartEmpty) suspendCurrentSale(); },
        F10:    () => { if (!lastSale && !cartEmpty) setModal('payment'); },
        F11:    () => { if (!lastSale && !cartEmpty) completeSale({ paymentMethod: 'card', amountTendered: total }); },
        F12:    () => { if (!lastSale && !cartEmpty) completeSale({ paymentMethod: 'cash', amountTendered: total }); },
        Escape: () => { if (saleError) setSaleError(null); else if (lastSale) setLastSale(null); else if (showMobileMore) setShowMobileMore(false); else setModal(null); },
    }, [cart, selectedIdx, total, modal, lastSale, showMobileMore, saleError], { allowInInput: true });

    // Delete stays out of the search box on purpose — in there it is how you
    // fix a typo, not how you remove a line from the sale.
    useKeyboard({
        Delete: () => { if (!lastSale && selectedIdx !== null) removeItem(selectedIdx); },
    }, [cart, selectedIdx, lastSale]);

    return (
        <div className="flex flex-col md:flex-row h-dvh bg-[#F9FAFB] dark:bg-gray-950 overflow-hidden select-none"
             style={{ fontFamily: 'Inter, sans-serif' }}>

            {/* ── Left/Top: Cart area ───────────────────────────────── */}
            <div className="flex flex-col flex-1 min-w-0 overflow-hidden">

                {/* ── Mobile header ──────────────────────────────── */}
                <div className="md:hidden bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800 shrink-0">
                    <div className="flex items-center justify-between px-4 py-3">
                        <div className="flex items-center gap-2">
                            <span className={`w-2.5 h-2.5 rounded-full ${isOnline ? 'bg-green-500' : 'bg-orange-400'}`} />
                            <span className="text-sm font-semibold text-gray-800 dark:text-gray-100">{isOnline ? 'Online' : 'Offline'}</span>
                            <span className="text-sm text-gray-400 dark:text-gray-500">· {user.name.split(' ')[0]}</span>
                            {stuckSales.length > 0 && (
                                <button onClick={() => setModal('stuckSales')}
                                    className="ml-1 flex items-center gap-1 rounded-full bg-red-100 dark:bg-red-900/30 px-2 py-0.5 text-[11px] font-bold text-red-700 dark:text-red-400">
                                    ⚠ {stuckSales.length}
                                </button>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                onClick={toggleDark}
                                className="w-10 h-10 flex items-center justify-center rounded-xl bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400"
                                aria-label="Toggle dark mode"
                            >
                                {isDark ? (
                                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                                    </svg>
                                ) : (
                                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                                    </svg>
                                )}
                            </button>
                            <button
                                onClick={() => setShowMobileMore(true)}
                                className="w-10 h-10 flex items-center justify-center rounded-xl bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                            >
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div className="px-4 pb-3 flex items-center gap-2">
                        {/* No autoHighlight on the phone. Its keyboard has no
                            Enter, it has Go, and Go is how the cashier puts
                            the keyboard away — see searchSelection.js for the
                            charger that got sold that way. A scan is still a
                            scan here: an exact barcode means one product and
                            cannot mean anything else. */}
                        <SearchBar
                            vendorId={vendorId}
                            onSelect={askQuantityFor}
                            onScan={addProduct}
                            autoFocus={false}
                        />
                        <BarcodeScanner vendorId={vendorId} onProductFound={addProduct} />
                    </div>
                </div>

                {/* ── Desktop header ──────────────────────────────── */}
                <div className="hidden md:flex items-center gap-2 px-4 py-3 bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800 shrink-0">
                    {/* Capped rather than left to fill the bar. It was taking the
                        whole width for no benefit — a product search needs far
                        less than that — and the space is worth more spent on
                        getting held sales back in reach. */}
                    <div className="flex flex-1 max-w-md">
                        {/* The counter's till, on a real keyboard: the closest
                            match highlights itself so the loop is type →
                            Enter → quantity → Enter, with no arrow key in it
                            and no hand off the keyboard. */}
                        <SearchBar
                            ref={searchRef}
                            vendorId={vendorId}
                            onSelect={askQuantityFor}
                            onScan={addProduct}
                            autoHighlight
                        />
                    </div>
                    <BarcodeScanner vendorId={vendorId} onProductFound={addProduct} />
                    <button
                        onClick={() => setModal('suspendedSales')}
                        className={`flex items-center gap-1.5 shrink-0 rounded-xl border px-3 py-2.5 text-xs font-semibold transition-colors ${
                            pendingSales.length > 0
                                ? 'bg-orange-50 dark:bg-orange-950/40 border-orange-200 dark:border-orange-900 text-[#F97316] hover:bg-orange-100 dark:hover:bg-orange-950'
                                : 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700'
                        }`}
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Suspended
                        {pendingSales.length > 0 && (
                            <span className="min-w-4.5 h-4.5 px-1 rounded-full bg-[#F97316] text-white text-[10px] font-bold flex items-center justify-center">
                                {pendingSales.length}
                            </span>
                        )}
                    </button>
                    <span className={`text-xs px-2 py-1 rounded-full font-medium shrink-0 ${isOnline ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'}`}>
                        {isOnline ? '●' : '●'}
                    </span>
                    {stuckSales.length > 0 && (
                        <button onClick={() => setModal('stuckSales')}
                            className="flex items-center gap-1 rounded-full bg-red-100 dark:bg-red-900/30 px-2 py-1 text-xs font-bold text-red-700 dark:text-red-400 shrink-0">
                            ⚠ {stuckSales.length} stuck
                        </button>
                    )}
                    <span className="text-xs text-gray-400 dark:text-gray-500 truncate max-w-25">{user.name}</span>
                    <div className="ml-auto flex items-center gap-3">
                        <button onClick={() => setModal('submitCash')}
                            className="text-xs text-gray-400 dark:text-gray-500 hover:text-[#068B03] transition-colors shrink-0">
                            Submit Cash
                        </button>
                        <button onClick={() => setModal('pickings')}
                            className="text-xs text-gray-400 dark:text-gray-500 hover:text-[#068B03] transition-colors shrink-0">
                            Pickings
                        </button>
                        <button onClick={() => setModal('salesHistory')}
                            className="text-xs text-gray-400 dark:text-gray-500 hover:text-[#068B03] transition-colors shrink-0">
                            My Sales
                        </button>
                        {CONFIG.panelUrl && (
                            <a href={CONFIG.panelUrl}
                                className="flex items-center gap-1 text-xs text-gray-400 dark:text-gray-500 hover:text-[#068B03] transition-colors">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                </svg>
                                Dashboard
                            </a>
                        )}
                        <button
                            onClick={toggleDark}
                            className="text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 shrink-0"
                            aria-label="Toggle dark mode"
                        >
                            {isDark ? '☀' : '☾'}
                        </button>
                        <button onClick={onLogout} className="text-xs text-gray-400 dark:text-gray-500 hover:text-red-500 shrink-0">
                            Logout
                        </button>
                    </div>
                </div>

                {/* Customer badge */}
                {customer && (
                    <div className="flex items-center gap-2 px-4 py-2 bg-blue-50 dark:bg-blue-950 border-b border-blue-100 dark:border-blue-900 shrink-0">
                        <span className="text-xs text-blue-600 dark:text-blue-400 font-medium">👤 {customer.name}</span>
                        {customer.phone && <span className="text-xs text-blue-400 dark:text-blue-500">{customer.phone}</span>}
                        <button onClick={() => setCustomer(null)} className="ml-auto text-xs text-blue-400 dark:text-blue-500 hover:text-red-500">✕</button>
                    </div>
                )}

                {/* Cart */}
                <div className="flex-1 overflow-y-auto bg-white dark:bg-gray-900">
                    <Cart
                        items={cart}
                        selectedIdx={selectedIdx}
                        onSelect={handleCartSelect}
                        onQtyChange={updateQty}
                        onRemove={removeItem}
                        onPriceEdit={(idx) => { setSelectedIdx(idx); setModal('price'); }}
                    />
                </div>

                {/* ── DESKTOP totals footer ─────────────────────────── */}
                <div className="hidden md:block bg-white dark:bg-gray-900 border-t-2 border-gray-100 dark:border-gray-800 px-6 py-4 shrink-0">
                    <div className="flex justify-between text-sm text-gray-500 dark:text-gray-400 mb-1">
                        <span>Subtotal</span><span>{fmt(subtotal)}</span>
                    </div>
                    {discountAmount > 0 && (
                        <div className="flex justify-between text-sm text-[#F97316] mb-1">
                            <span>Discount</span><span>− {fmt(discountAmount)}</span>
                        </div>
                    )}
                    {VAT_ENABLED && (
                        <div className="flex justify-between text-sm text-gray-500 dark:text-gray-400 mb-3">
                            <span>VAT ({VAT_RATE}%)</span><span>{fmt(vatAmount)}</span>
                        </div>
                    )}
                    <div className="flex justify-between items-baseline">
                        <span className="text-lg font-bold text-gray-700 dark:text-gray-300" style={{ fontFamily: 'Montserrat, sans-serif' }}>TOTAL</span>
                        <span className="text-4xl font-extrabold text-gray-900 dark:text-gray-100" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                            {fmt(total)}
                        </span>
                    </div>
                </div>

                {/* ── MOBILE bottom bar ─────────────────────────────── */}
                <div className="md:hidden bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800 shrink-0">

                    {/* Icon action buttons */}
                    <div className="flex justify-around px-2 pt-3 pb-2">
                        <button
                            onClick={() => setModal('customer')}
                            className="flex flex-col items-center gap-1 active:scale-95 transition-all"
                        >
                            <span className={`w-11 h-11 rounded-full flex items-center justify-center ${customer ? 'bg-green-100 dark:bg-green-900' : 'bg-gray-100 dark:bg-gray-800'}`}>
                                <svg className={`w-5 h-5 ${customer ? 'text-green-600 dark:text-green-400' : 'text-gray-700 dark:text-gray-400'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </span>
                            <span className={`text-[11px] font-medium ${customer ? 'text-green-600 dark:text-green-400' : 'text-gray-600 dark:text-gray-400'}`}>Customer</span>
                        </button>

                        <button
                            onClick={() => !cartEmpty && suspendCurrentSale()}
                            disabled={cartEmpty}
                            className="relative flex flex-col items-center gap-1 active:scale-95 transition-all disabled:opacity-40"
                        >
                            <span className="w-11 h-11 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                <svg className="w-5 h-5 text-gray-700 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                {pendingSales.length > 0 && (
                                    <span className="absolute top-0 right-1 w-4 h-4 rounded-full bg-[#F97316] text-white text-[9px] font-bold flex items-center justify-center">
                                        {pendingSales.length}
                                    </span>
                                )}
                            </span>
                            <span className="text-[11px] font-medium text-gray-600 dark:text-gray-400">Suspend</span>
                        </button>

                        <button
                            onClick={() => !cartEmpty && clearCart()}
                            disabled={cartEmpty}
                            className="flex flex-col items-center gap-1 active:scale-95 transition-all disabled:opacity-40"
                        >
                            <span className="w-11 h-11 rounded-full bg-orange-50 dark:bg-orange-950 flex items-center justify-center">
                                <svg className="w-5 h-5 text-[#F97316]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </span>
                            <span className="text-[11px] font-medium text-[#F97316]">Void</span>
                        </button>
                    </div>

                    {/* Totals + CHARGE */}
                    <div className="flex items-center gap-3 px-3 pb-4">
                        <div className="flex-1 min-w-0">
                            <div className="flex justify-between text-xs text-gray-400 dark:text-gray-500">
                                <span>Subtotal</span><span>{fmt(subtotal)}</span>
                            </div>
                            {discountAmount > 0 && (
                                <div className="flex justify-between text-xs text-[#F97316] mt-0.5">
                                    <span>Discount</span><span>−{fmt(discountAmount)}</span>
                                </div>
                            )}
                            {VAT_ENABLED && (
                                <div className="flex justify-between text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                    <span>VAT {VAT_RATE}%</span><span>{fmt(vatAmount)}</span>
                                </div>
                            )}
                            <div className="text-[26px] font-extrabold text-gray-900 dark:text-gray-100 leading-tight mt-1"
                                style={{ fontFamily: 'Montserrat, sans-serif' }}>
                                {fmt(total)}
                            </div>
                        </div>
                        <div className="w-5/12 flex flex-col gap-1.5 shrink-0">
                            <div className="flex gap-1.5 h-[34px]">
                                <button
                                    onClick={() => !cartEmpty && !submitting && completeSale({ paymentMethod: 'cash', amountTendered: total })}
                                    disabled={cartEmpty || submitting}
                                    className="flex-1 rounded-xl bg-white border border-gray-200 text-gray-700 text-[10px] font-bold active:scale-95 transition-all disabled:opacity-40 shadow-sm"
                                >
                                    {submitting ? '…' : 'CASH'}
                                </button>
                                <button
                                    onClick={() => !cartEmpty && !submitting && completeSale({ paymentMethod: 'card', amountTendered: total })}
                                    disabled={cartEmpty || submitting}
                                    className="flex-1 rounded-xl bg-white border border-gray-200 text-gray-700 text-[10px] font-bold active:scale-95 transition-all disabled:opacity-40 shadow-sm"
                                >
                                    {submitting ? '…' : 'POS'}
                                </button>
                                <button
                                    onClick={() => !cartEmpty && !submitting && completeSale({ paymentMethod: 'bank_transfer', amountTendered: total })}
                                    disabled={cartEmpty || submitting}
                                    className="flex-1 rounded-xl bg-white border border-gray-200 text-gray-700 text-[10px] font-bold active:scale-95 transition-all disabled:opacity-40 shadow-sm"
                                >
                                    {submitting ? '…' : 'TFER'}
                                </button>
                            </div>
                            <button
                                onClick={() => !cartEmpty && setModal('payment')}
                                disabled={cartEmpty}
                                className="h-[34px] rounded-xl bg-[#068B03] text-white font-bold text-sm flex items-center justify-center gap-1 active:scale-95 transition-all disabled:opacity-40 shadow-sm"
                            >
                                CHARGE
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {/* ── Right: Action grid (desktop only) ────────────────── */}
            <div className="hidden md:block">
                <ActionGrid
                    cartEmpty={cartEmpty}
                    noSelection={selectedIdx === null}
                    onDeleteItem={() => selectedIdx !== null && removeItem(selectedIdx)}
                    onSearch={() => searchRef.current?.focus()}
                    onChangeQty={() => selectedIdx !== null && setModal('quantity')}
                    onNewSale={clearCart}
                    onDiscount={() => setModal('discount')}
                    onCustomer={() => setModal('customer')}
                    onQuickCash={() => !cartEmpty && completeSale({ paymentMethod: 'cash', amountTendered: total })}
                    onQuickPOS={() => !cartEmpty && completeSale({ paymentMethod: 'card', amountTendered: total })}
                    onQuickTransfer={() => !cartEmpty && completeSale({ paymentMethod: 'bank_transfer', amountTendered: total })}
                    onSuspend={suspendCurrentSale}
                    onPayment={() => !cartEmpty && setModal('payment')}
                    onVoid={clearCart}
                    onZReport={() => setModal('zreport')}
                    pendingSales={pendingSales}
                    onViewPending={() => setModal('suspendedSales')}
                    pendingError={pendingError}
                    onReturn={() => setModal('return')}
                />
            </div>

            {/* ── Mobile "More" slide-up sheet ──────────────────────── */}
            {showMobileMore && (
                <div className="fixed inset-0 z-50 md:hidden flex flex-col justify-end">
                    <div
                        className="absolute inset-0 bg-black/40"
                        onClick={() => setShowMobileMore(false)}
                    />
                    <div className="relative bg-white dark:bg-gray-900 rounded-t-2xl px-4 pt-4 pb-8 shadow-2xl">
                        <div className="w-10 h-1 bg-gray-300 dark:bg-gray-700 rounded-full mx-auto mb-4" />
                        <p className="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-4">Menu</p>

                        <div className="grid grid-cols-3 gap-2 mb-4">
                            <SheetBtn
                                label="Discount"
                                disabled={cartEmpty}
                                onClick={() => { setModal('discount'); setShowMobileMore(false); }}
                                color="orange"
                            />
                            <SheetBtn
                                label="Return"
                                onClick={() => { setModal('return'); setShowMobileMore(false); }}
                                color="blue"
                            />
                            <SheetBtn
                                label="Z-Report"
                                onClick={() => { setModal('zreport'); setShowMobileMore(false); }}
                            />
                            <SheetBtn
                                label="My Sales"
                                onClick={() => { setModal('salesHistory'); setShowMobileMore(false); }}
                                color="green"
                            />
                            <SheetBtn
                                label="Pickings"
                                onClick={() => { setModal('pickings'); setShowMobileMore(false); }}
                                color="orange"
                            />
                            <SheetBtn
                                label="Submit Cash"
                                onClick={() => { setModal('submitCash'); setShowMobileMore(false); }}
                                color="green"
                            />
                            <SheetBtn
                                label={pendingSales.length > 0 ? `Suspended (${pendingSales.length})` : 'Suspended'}
                                onClick={() => { setModal('suspendedSales'); setShowMobileMore(false); }}
                                color={pendingSales.length > 0 ? 'orange' : 'gray'}
                            />
                        </div>

                        {pendingError && (
                            <div className="mb-3 px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-xs font-semibold text-red-600">
                                {pendingError}
                            </div>
                        )}

                        {/* A backdated sale is surprising unless it says so. It
                            will not appear in today's takings, which is the
                            point — the goods left on the day named here. */}
                        {recoveredAt && (
                            <div className="mb-3 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-800">
                                <span className="font-bold">Correcting an earlier sale.</span>{' '}
                                This will be recorded on{' '}
                                {new Date(recoveredAt).toLocaleDateString('en-NG', { day: 'numeric', month: 'short' })},
                                the day the goods left — not today.
                            </div>
                        )}

                        <div className="border-t border-gray-100 dark:border-gray-800 pt-3 space-y-0">
                            {CONFIG.panelUrl && (
                                <a href={CONFIG.panelUrl}
                                    className="flex items-center gap-3 py-3 text-sm text-gray-600 dark:text-gray-400 hover:text-[#068B03] border-b border-gray-50 dark:border-gray-800">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                    </svg>
                                    Back to Dashboard
                                </a>
                            )}
                            <button
                                onClick={onLogout}
                                className="flex items-center gap-3 py-3 text-sm text-red-500 w-full"
                            >
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                                Logout
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* ── Modals ────────────────────────────────────────────── */}
            {modal === 'payment' && (
                <PaymentModal
                    total={total}
                    onComplete={completeSale}
                    onClose={() => setModal(null)}
                    cart={cart}
                    customer={customer}
                    subtotal={subtotal}
                    discountAmount={discountAmount}
                    vatAmount={vatAmount}
                />
            )}
            {modal === 'customer' && (
                <CustomerModal
                    vendorId={vendorId}
                    current={customer}
                    onSelect={(c) => { setCustomer(c); setModal(null); }}
                    onClose={() => setModal(null)}
                />
            )}
            {modal === 'discount' && (
                <DiscountModal
                    vendorId={vendorId}
                    subtotal={subtotal}
                    floorTotal={cartFloor}
                    current={cartDiscount}
                    onApply={(d) => { setCartDiscount(d); setModal(null); }}
                    onClose={() => setModal(null)}
                />
            )}
            {/* Correcting a line that is already in the sale: the number
                replaces what is there, and 0 takes the line out. */}
            {modal === 'quantity' && selectedIdx !== null && (
                <QuantityModal
                    item={cart[selectedIdx]}
                    onConfirm={(qty) => { updateQty(selectedIdx, qty); setModal(null); }}
                    onClose={() => setModal(null)}
                    onNegotiate={() => setModal('price')}
                />
            )}
            {/* Saying how many of a just-picked product to put in the sale:
                the number is added, and Escape adds nothing at all. */}
            {modal === 'addQuantity' && pendingProduct && (
                <QuantityModal
                    item={{ ...pendingProduct, qty: 1 }}
                    title="Add to Sale"
                    hint="Type the quantity and press Enter · Esc cancels"
                    onConfirm={confirmPending}
                    onClose={cancelPending}
                    onNegotiate={negotiatePending}
                />
            )}
            {modal === 'price' && selectedIdx !== null && (
                <PriceModal
                    item={cart[selectedIdx]}
                    onConfirm={(price) => { updatePrice(selectedIdx, price); setModal(null); }}
                    onClose={() => setModal(null)}
                />
            )}
            {modal === 'return' && (
                <ReturnModal
                    vendorId={vendorId}
                    cashierId={user.id}
                    onClose={() => setModal(null)}
                />
            )}
            {modal === 'zreport' && (
                <ZReportModal
                    session={session}
                    onClose={() => setModal(null)}
                    onCloseSession={() => {
                        localStorage.removeItem('pos_session');
                        setSession(null);
                        setModal(null);
                    }}
                />
            )}
            {lastSale && (
                <ReceiptModal
                    sale={lastSale}
                    isReprint={isReprintView}
                    onNewSale={() => {
                        setLastSale(null);
                        setIsReprintView(false);
                        setTimeout(() => searchRef.current?.focus(), 50);
                    }}
                />
            )}
            {modal === 'submitCash' && (
                <CashSubmitModal
                    vendorId={vendorId}
                    isOnline={isOnline}
                    onClose={() => setModal(null)}
                />
            )}

            {modal === 'pickings' && (
                <PickingsModal
                    vendorId={vendorId}
                    isOnline={isOnline}
                    cart={cart}
                    onReleased={clearCart}
                    onClose={() => setModal(null)}
                />
            )}

            {modal === 'salesHistory' && (
                <SalesHistoryModal
                    vendorId={vendorId}
                    cashierId={user.id}
                    onClose={() => setModal(null)}
                    onReprint={(sale) => {
                        setModal(null);
                        setIsReprintView(true);
                        setLastSale(sale);
                    }}
                />
            )}
            {modal === 'suspendedSales' && (
                <SuspendedSalesModal
                    sales={pendingSales}
                    cartEmpty={cartEmpty}
                    error={pendingError}
                    onResume={async (sale) => { await resumePendingSale(sale); setModal(null); }}
                    onDiscard={clearPendingSale}
                    onClose={() => setModal(null)}
                />
            )}

            {modal === 'stuckSales' && (
                <StuckSalesModal
                    sales={stuckSales}
                    cartEmpty={cartEmpty}
                    onClose={() => setModal(null)}
                    onRetried={() => syncNow()}
                    // Gated on an empty cart for the same reason resuming a held
                    // sale is: loading one sale over another would lose whatever
                    // is on screen, and a cashier mid-sale would never get it back.
                    onReturnToCart={(items, originalCompletedAt) => {
                        setCart(items);
                        setRecoveredAt(originalCompletedAt ?? null);
                        setSelectedIdx(null);
                        setCartDiscount({ amount: 0, type: 'fixed', approvedBy: null });
                        setModal(null);
                        syncNow();
                    }}
                />
            )}
            {/* A slow connection used to leave the screen looking untouched, so
                a cashier pressed the payment button again — and again — and rang
                up the same goods several times. This says the till has the sale
                and covers the screen while it lands, so there is nothing left to
                press. It clears itself when the receipt appears or the sale is
                refused. */}
            {submitting && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
                        <div className="w-14 h-14 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center mx-auto mb-3">
                            <svg className="w-7 h-7 text-[#068B03] animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                            </svg>
                        </div>
                        <p className="text-sm font-bold text-gray-800 dark:text-gray-100 mb-1">Recording this sale…</p>
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            It has been captured. Please don't press again — the receipt will show as soon as it lands.
                        </p>
                    </div>
                </div>
            )}
            {saleError && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
                        <div className="w-14 h-14 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center mx-auto mb-3">
                            <svg className="w-7 h-7 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v3.75m-9.303 3.376C1.83 17.933 2.914 20 4.673 20h14.654c1.76 0 2.842-2.067 1.976-3.874L13.976 4.126c-.881-1.833-3.07-1.833-3.952 0L2.697 16.126z" />
                            </svg>
                        </div>
                        <p className="text-sm font-bold text-gray-800 dark:text-gray-100 mb-1">Sale not completed</p>
                        <p className="text-sm text-gray-500 dark:text-gray-400 mb-5">{saleError}</p>
                        <button
                            onClick={() => setSaleError(null)}
                            className="w-full py-3 rounded-xl bg-[#068B03] text-white text-sm font-bold hover:bg-[#057002]"
                        >
                            OK, let me fix it
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
