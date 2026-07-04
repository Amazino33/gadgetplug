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
    const [cart, setCart]                 = useState([]);
    const [selectedIdx, setSelectedIdx]   = useState(null);
    const [customer, setCustomer]         = useState(null);
    const [cartDiscount, setCartDiscount] = useState({ amount: 0, type: 'fixed', approvedBy: null });
    const [session, setSession]           = useState(() => {
        const s = localStorage.getItem('pos_session');
        return s ? JSON.parse(s) : null;
    });
    const [isOnline, setIsOnline]         = useState(navigator.onLine);
    const [modal, setModal]               = useState(null);
    const [lastSale, setLastSale]         = useState(null);
    const [showMobileMore, setShowMobileMore] = useState(false);
    const [isDark, setIsDark] = useState(() => localStorage.getItem('darkMode') === 'true');

    const toggleDark = () => {
        const next = !isDark;
        setIsDark(next);
        localStorage.setItem('darkMode', String(next));
        document.documentElement.classList.toggle('dark', next);
    };

    const searchRef = useRef(null);

    useSync(vendorId);

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
        setTimeout(() => searchRef.current?.focus(), 50);
    }, []);

    const updateQty = (idx, qty) => {
        if (qty < 1) { removeItem(idx); return; }
        setCart((prev) => { const u = [...prev]; u[idx] = { ...u[idx], qty }; return u; });
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
        setSelectedIdx(null);
        setCustomer(null);
        setCartDiscount({ amount: 0, type: 'fixed', approvedBy: null });
    };

    // ── Totals ───────────────────────────────────────────────────────

    const subtotal       = cart.reduce((s, i) => s + i.price * i.qty - (i.lineDiscount || 0), 0);
    const discountAmount = cartDiscount.type === 'percentage'
        ? subtotal * (cartDiscount.amount / 100)
        : cartDiscount.amount;
    const vatAmount = VAT_ENABLED ? (subtotal - discountAmount) * (VAT_RATE / 100) : 0;
    const total     = subtotal - discountAmount + vatAmount;
    const cartEmpty = cart.length === 0;

    // ── Complete sale ────────────────────────────────────────────────

    const completeSale = async ({ paymentMethod, amountTendered, bankRef, payments }) => {
        const isSplit = paymentMethod === 'split';
        const payload = {
            offline_id:              generateOfflineId(),
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
            completed_at:            new Date().toISOString(),
        };

        let savedSale = { ...payload };

        if (isOnline) {
            try {
                const { data } = await api.post('/sales', payload);
                savedSale = { ...payload, reference: data.reference };
            } catch {
                await db.offlineSales.add({ ...payload, synced: 0 });
            }
        } else {
            await db.offlineSales.add({ ...payload, synced: 0 });
        }

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

    // ── Keyboard shortcuts (desktop) ─────────────────────────────────

    useKeyboard({
        F3:     () => { if (!lastSale) searchRef.current?.focus(); },
        F4:     () => { if (!lastSale && selectedIdx !== null) setModal('quantity'); },
        F8:     () => { if (!lastSale) clearCart(); },
        F2:     () => { if (!lastSale && !cartEmpty) setModal('discount'); },
        c:      () => { if (!lastSale) setModal('customer'); },
        C:      () => { if (!lastSale) setModal('customer'); },
        F9:     () => { if (!lastSale && !cartEmpty) setModal('suspend'); },
        F10:    () => { if (!lastSale && !cartEmpty) setModal('payment'); },
        F12:    () => { if (!lastSale && !cartEmpty) completeSale({ paymentMethod: 'cash', amountTendered: total }); },
        Escape: () => { if (lastSale) setLastSale(null); else if (showMobileMore) setShowMobileMore(false); else setModal(null); },
        Delete: () => { if (!lastSale && selectedIdx !== null) removeItem(selectedIdx); },
    }, [cart, selectedIdx, total, modal, lastSale, showMobileMore]);

    return (
        <div className="flex flex-col md:flex-row h-screen bg-[#F9FAFB] dark:bg-gray-950 overflow-hidden select-none"
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
                    <div className="px-4 pb-3">
                        <SearchBar vendorId={vendorId} onSelect={addProduct} autoFocus={false} />
                    </div>
                </div>

                {/* ── Desktop header ──────────────────────────────── */}
                <div className="hidden md:flex items-center gap-2 px-4 py-3 bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800 shrink-0">
                    <SearchBar ref={searchRef} vendorId={vendorId} onSelect={addProduct} />
                    <span className={`text-xs px-2 py-1 rounded-full font-medium shrink-0 ${isOnline ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'}`}>
                        {isOnline ? '●' : '●'}
                    </span>
                    <span className="text-xs text-gray-400 dark:text-gray-500 truncate max-w-25">{user.name}</span>
                    <div className="ml-auto flex items-center gap-3">
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
                            onClick={() => !cartEmpty && setModal('suspend')}
                            disabled={cartEmpty}
                            className="flex flex-col items-center gap-1 active:scale-95 transition-all disabled:opacity-40"
                        >
                            <span className="w-11 h-11 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                <svg className="w-5 h-5 text-gray-700 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
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
                        <button
                            onClick={() => !cartEmpty && setModal('payment')}
                            disabled={cartEmpty}
                            className="h-[74px] w-5/12 rounded-2xl bg-[#068B03] text-white font-bold text-lg flex items-center justify-center gap-2 active:scale-95 transition-all disabled:opacity-40 shrink-0"
                        >
                            CHARGE
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
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
                    onSuspend={() => !cartEmpty && setModal('suspend')}
                    onPayment={() => !cartEmpty && setModal('payment')}
                    onVoid={clearCart}
                    onZReport={() => setModal('zreport')}
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
                        </div>

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
