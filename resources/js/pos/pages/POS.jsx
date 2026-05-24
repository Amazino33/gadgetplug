import { useState, useRef, useEffect, useCallback } from 'react';
import { useKeyboard } from '../hooks/useKeyboard';
import { useSync } from '../hooks/useSync';
import { fmt, generateOfflineId } from '../lib/format';
import { db } from '../lib/db';
import api from '../lib/api';
import Cart from '../components/Cart';
import SearchBar from '../components/SearchBar';
import ActionGrid from '../components/ActionGrid';
import PaymentModal from '../components/PaymentModal';
import CustomerModal from '../components/CustomerModal';
import DiscountModal from '../components/DiscountModal';
import SuspendTray from '../components/SuspendTray';
import QuantityModal from '../components/QuantityModal';
import ReturnModal from '../components/ReturnModal';
import ZReportModal from '../components/ZReportModal';
import ReceiptModal from '../components/ReceiptModal';

const CONFIG = window.POS_CONFIG ?? {};

const VAT_RATE = 7.5;

export default function POS({ user, vendorId, onLogout }) {
    const [cart, setCart]                 = useState([]);
    const [selectedIdx, setSelectedIdx]   = useState(null);
    const [customer, setCustomer]         = useState(null);
    const [cartDiscount, setCartDiscount] = useState({ amount: 0, type: 'fixed', approvedBy: null });
    const [session, setSession]           = useState(() => {
        const s = localStorage.getItem('pos_session');
        return s ? JSON.parse(s) : null;
    });
    const [isOnline, setIsOnline]         = useState(navigator.onLine);

    // Modal states
    const [modal, setModal] = useState(null); // 'payment'|'customer'|'discount'|'suspend'|'quantity'|'return'|'zreport'
    const [lastSale, setLastSale] = useState(null); // holds completed sale for receipt screen

    const searchRef = useRef(null);

    useSync(vendorId);

    // Online/offline indicator
    useEffect(() => {
        const on  = () => setIsOnline(true);
        const off = () => setIsOnline(false);
        window.addEventListener('online', on);
        window.addEventListener('offline', off);
        return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off); };
    }, []);

    // Auto-open a session if none exists
    useEffect(() => {
        if (!session) openSession();
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const openSession = async () => {
        try {
            const { data } = await api.post('/sessions/open', { vendor_id: vendorId, opening_float: 0 });
            setSession(data);
            localStorage.setItem('pos_session', JSON.stringify(data));
        } catch { /* offline — continue without session */ }
    };

    // ── Cart operations ──────────────────────────────────────────────

    const addProduct = useCallback((product) => {
        setCart((prev) => {
            const idx = prev.findIndex((i) => i.id === product.id);
            if (idx >= 0) {
                const updated = [...prev];
                updated[idx] = { ...updated[idx], qty: updated[idx].qty + 1 };
                setSelectedIdx(idx);
                return updated;
            }
            setSelectedIdx(prev.length);
            return [...prev, { ...product, qty: 1, lineDiscount: 0 }];
        });
        // Refocus search after adding
        setTimeout(() => searchRef.current?.focus(), 50);
    }, []);

    const updateQty = (idx, qty) => {
        if (qty < 1) { removeItem(idx); return; }
        setCart((prev) => {
            const updated = [...prev];
            updated[idx] = { ...updated[idx], qty };
            return updated;
        });
    };

    const removeItem = (idx) => {
        setCart((prev) => prev.filter((_, i) => i !== idx));
        setSelectedIdx(null);
    };

    const clearCart = () => {
        setCart([]);
        setSelectedIdx(null);
        setCustomer(null);
        setCartDiscount({ amount: 0, type: 'fixed', approvedBy: null });
    };

    // ── Totals ───────────────────────────────────────────────────────

    const subtotal = cart.reduce((sum, item) => {
        const lineTotal = item.price * item.qty - (item.lineDiscount || 0);
        return sum + lineTotal;
    }, 0);

    const discountAmount = cartDiscount.type === 'percentage'
        ? subtotal * (cartDiscount.amount / 100)
        : cartDiscount.amount;

    const vatAmount  = (subtotal - discountAmount) * (VAT_RATE / 100);
    const total      = subtotal - discountAmount + vatAmount;

    // ── Complete sale ────────────────────────────────────────────────

    const completeSale = async ({ paymentMethod, amountTendered, bankRef, payments }) => {
        const isSplit = paymentMethod === 'split';
        const payload = {
            offline_id:     generateOfflineId(),
            vendor_id:      vendorId,
            pos_session_id: session?.id ?? null,
            customer_id:    customer?.id ?? null,
            items: cart.map((item) => ({
                product_id:   item.id,
                product_name: item.name,
                product_sku:  item.sku ?? null,
                unit_price:   item.price,
                quantity:     item.qty,
                discount_amount: item.lineDiscount || 0,
                total:        (item.price * item.qty) - (item.lineDiscount || 0),
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
            completed_at:            new Date().toISOString(),
        };

        let savedSale = { ...payload };

        if (isOnline) {
            try {
                const { data } = await api.post('/sales', payload);
                savedSale = { ...payload, reference: data.reference };
            } catch {
                // Fallback to offline queue if request fails mid-flight
                await db.offlineSales.add({ ...payload, synced: 0 });
            }
        } else {
            await db.offlineSales.add({ ...payload, synced: 0 });
        }

        // Build receipt data from current cart before clearing
        const receiptSale = {
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

        clearCart();
        setModal(null);
        setLastSale(receiptSale);
    };

    // ── Keyboard shortcuts ───────────────────────────────────────────

    useKeyboard({
        F3:      () => { if (!lastSale) searchRef.current?.focus(); },
        F4:      () => { if (!lastSale && selectedIdx !== null) setModal('quantity'); },
        F8:      () => { if (!lastSale) clearCart(); },
        F2:      () => { if (!lastSale && cart.length > 0) setModal('discount'); },
        c:       () => { if (!lastSale) setModal('customer'); },
        C:       () => { if (!lastSale) setModal('customer'); },
        F9:      () => { if (!lastSale && cart.length > 0) setModal('suspend'); },
        F10:     () => { if (!lastSale && cart.length > 0) setModal('payment'); },
        F12:     () => { if (!lastSale && cart.length > 0) completeSale({ paymentMethod: 'cash', amountTendered: total }); },
        Escape:  () => { if (lastSale) setLastSale(null); else setModal(null); },
        Delete:  () => { if (!lastSale && selectedIdx !== null) removeItem(selectedIdx); },
    }, [cart, selectedIdx, total, modal, lastSale]);

    return (
        <div className="flex h-screen bg-[#F9FAFB] overflow-hidden select-none" style={{ fontFamily: 'Inter, sans-serif' }}>

            {/* ── LEFT: Cart (75%) ──────────────────────────────────────── */}
            <div className="flex flex-col flex-1 min-w-0">

                {/* Top bar */}
                <div className="flex items-center gap-3 px-4 py-3 bg-white border-b border-gray-100">
                    <SearchBar ref={searchRef} vendorId={vendorId} onSelect={addProduct} />
                    <span className={`text-xs px-2 py-1 rounded-full font-medium ${isOnline ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'}`}>
                        {isOnline ? '● Online' : '● Offline'}
                    </span>
                    <span className="text-xs text-gray-400 truncate">{user.name}</span>
                    <div className="ml-auto flex items-center gap-3">
                        {CONFIG.panelUrl && (
                            <a href={CONFIG.panelUrl}
                                className="flex items-center gap-1 text-xs text-gray-400 hover:text-[#068B03] transition-colors">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                </svg>
                                Dashboard
                            </a>
                        )}
                        <button onClick={onLogout} className="text-xs text-gray-400 hover:text-red-500">Logout</button>
                    </div>
                </div>

                {/* Customer badge */}
                {customer && (
                    <div className="flex items-center gap-2 px-4 py-2 bg-blue-50 border-b border-blue-100">
                        <span className="text-xs text-blue-600 font-medium">👤 {customer.name}</span>
                        {customer.phone && <span className="text-xs text-blue-400">{customer.phone}</span>}
                        <button onClick={() => setCustomer(null)} className="ml-auto text-xs text-blue-400 hover:text-red-500">✕</button>
                    </div>
                )}

                {/* Cart table */}
                <div className="flex-1 overflow-y-auto bg-white">
                    <Cart
                        items={cart}
                        selectedIdx={selectedIdx}
                        onSelect={setSelectedIdx}
                        onQtyChange={updateQty}
                        onRemove={removeItem}
                    />
                </div>

                {/* Totals footer */}
                <div className="bg-white border-t-2 border-gray-100 px-6 py-4 shrink-0">
                    <div className="flex justify-between text-sm text-gray-500 mb-1">
                        <span>Subtotal</span><span>{fmt(subtotal)}</span>
                    </div>
                    {discountAmount > 0 && (
                        <div className="flex justify-between text-sm text-[#F97316] mb-1">
                            <span>Discount</span><span>− {fmt(discountAmount)}</span>
                        </div>
                    )}
                    <div className="flex justify-between text-sm text-gray-500 mb-3">
                        <span>VAT ({VAT_RATE}%)</span><span>{fmt(vatAmount)}</span>
                    </div>
                    <div className="flex justify-between items-baseline">
                        <span className="text-lg font-bold text-gray-700" style={{ fontFamily: 'Montserrat, sans-serif' }}>TOTAL</span>
                        <span className="text-4xl font-extrabold text-gray-900" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                            {fmt(total)}
                        </span>
                    </div>
                </div>
            </div>

            {/* ── RIGHT: Action grid (25%) ──────────────────────────────── */}
            <ActionGrid
                cartEmpty={cart.length === 0}
                noSelection={selectedIdx === null}
                onDeleteItem={() => selectedIdx !== null && removeItem(selectedIdx)}
                onSearch={() => searchRef.current?.focus()}
                onChangeQty={() => selectedIdx !== null && setModal('quantity')}
                onNewSale={clearCart}
                onDiscount={() => setModal('discount')}
                onCustomer={() => setModal('customer')}
                onQuickCash={() => cart.length > 0 && completeSale({ paymentMethod: 'cash', amountTendered: total })}
                onSuspend={() => cart.length > 0 && setModal('suspend')}
                onPayment={() => cart.length > 0 && setModal('payment')}
                onVoid={clearCart}
                onZReport={() => setModal('zreport')}
                onReturn={() => setModal('return')}
            />

            {/* ── Modals ────────────────────────────────────────────────── */}
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
                    current={cartDiscount}
                    onApply={(d) => { setCartDiscount(d); setModal(null); }}
                    onClose={() => setModal(null)}
                />
            )}
            {modal === 'suspend' && (
                <SuspendTray
                    vendorId={vendorId}
                    cart={cart}
                    customer={customer}
                    onSuspend={() => { clearCart(); setModal(null); }}
                    onResume={(resumed) => {
                        setCart(resumed.cart_data.items || []);
                        setCustomer(resumed.cart_data.customer || null);
                        setModal(null);
                    }}
                    onClose={() => setModal(null)}
                />
            )}
            {modal === 'quantity' && selectedIdx !== null && (
                <QuantityModal
                    item={cart[selectedIdx]}
                    onConfirm={(qty) => { updateQty(selectedIdx, qty); setModal(null); }}
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

            {/* Receipt — shown after every completed sale */}
            {lastSale && (
                <ReceiptModal
                    sale={lastSale}
                    onNewSale={() => {
                        setLastSale(null);
                        setTimeout(() => searchRef.current?.focus(), 50);
                    }}
                />
            )}
        </div>
    );
}
