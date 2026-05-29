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

// Small button used inside the mobile "More" sheet
const SheetBtn = ({ label, onClick, disabled = false, color = 'gray' }) => {
    const colors = {
        gray:   'bg-gray-100 text-gray-700',
        green:  'bg-green-50 text-green-700',
        orange: 'bg-orange-50 text-orange-600',
        red:    'bg-red-50 text-red-600',
        blue:   'bg-blue-50 text-blue-600',
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
    const vatAmount = (subtotal - discountAmount) * (VAT_RATE / 100);
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
        <div className="flex flex-col md:flex-row h-screen bg-[#F9FAFB] overflow-hidden select-none"
             style={{ fontFamily: 'Inter, sans-serif' }}>

            {/* ── Left/Top: Cart area ───────────────────────────────── */}
            <div className="flex flex-col flex-1 min-w-0 overflow-hidden">

                {/* Top bar */}
                <div className="flex items-center gap-2 px-3 py-2 md:px-4 md:py-3 bg-white border-b border-gray-100 shrink-0">
                    <SearchBar ref={searchRef} vendorId={vendorId} onSelect={addProduct} />
                    <span className={`text-xs px-2 py-1 rounded-full font-medium shrink-0 ${isOnline ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'}`}>
                        {isOnline ? '●' : '●'}
                    </span>
                    <span className="hidden md:block text-xs text-gray-400 truncate max-w-[100px]">{user.name}</span>
                    <div className="ml-auto flex items-center gap-2 md:gap-3">
                        {CONFIG.panelUrl && (
                            <a href={CONFIG.panelUrl}
                                className="hidden md:flex items-center gap-1 text-xs text-gray-400 hover:text-[#068B03] transition-colors">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                </svg>
                                Dashboard
                            </a>
                        )}
                        <button onClick={onLogout} className="text-xs text-gray-400 hover:text-red-500 shrink-0">
                            Logout
                        </button>
                    </div>
                </div>

                {/* Customer badge */}
                {customer && (
                    <div className="flex items-center gap-2 px-4 py-2 bg-blue-50 border-b border-blue-100 shrink-0">
                        <span className="text-xs text-blue-600 font-medium">👤 {customer.name}</span>
                        {customer.phone && <span className="text-xs text-blue-400">{customer.phone}</span>}
                        <button onClick={() => setCustomer(null)} className="ml-auto text-xs text-blue-400 hover:text-red-500">✕</button>
                    </div>
                )}

                {/* Cart */}
                <div className="flex-1 overflow-y-auto bg-white">
                    <Cart
                        items={cart}
                        selectedIdx={selectedIdx}
                        onSelect={setSelectedIdx}
                        onQtyChange={updateQty}
                        onRemove={removeItem}
                    />
                </div>

                {/* ── DESKTOP totals footer ─────────────────────────── */}
                <div className="hidden md:block bg-white border-t-2 border-gray-100 px-6 py-4 shrink-0">
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

                {/* ── MOBILE bottom bar ─────────────────────────────── */}
                <div className="md:hidden bg-white border-t-2 border-gray-100 shrink-0">

                    {/* Totals row */}
                    <div className="flex items-center justify-between px-4 pt-3 pb-1">
                        <div className="text-xs text-gray-400 space-y-0.5">
                            <div>Sub: <span className="text-gray-600 font-medium">{fmt(subtotal)}</span></div>
                            {discountAmount > 0 && (
                                <div className="text-[#F97316]">Disc: − {fmt(discountAmount)}</div>
                            )}
                            <div>VAT: <span className="text-gray-600 font-medium">{fmt(vatAmount)}</span></div>
                        </div>
                        <div className="text-right">
                            <div className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Total</div>
                            <div className="text-3xl font-extrabold text-gray-900 leading-none" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                                {fmt(total)}
                            </div>
                        </div>
                    </div>

                    {/* Quick action row */}
                    <div className="grid grid-cols-4 gap-2 px-3 py-2">
                        <button
                            onClick={clearCart}
                            className="py-2 rounded-xl text-xs font-bold bg-gray-100 text-gray-600 active:scale-95 transition-all">
                            New Sale
                        </button>
                        <button
                            onClick={() => setModal('customer')}
                            className="py-2 rounded-xl text-xs font-bold bg-blue-50 text-blue-600 active:scale-95 transition-all">
                            Customer
                        </button>
                        <button
                            onClick={() => !cartEmpty && setModal('discount')}
                            disabled={cartEmpty}
                            className="py-2 rounded-xl text-xs font-bold bg-orange-50 text-orange-600 active:scale-95 transition-all disabled:opacity-40">
                            Discount
                        </button>
                        <button
                            onClick={() => setShowMobileMore(true)}
                            className="py-2 rounded-xl text-xs font-bold bg-gray-100 text-gray-500 active:scale-95 transition-all">
                            More ⋯
                        </button>
                    </div>

                    {/* Payment buttons */}
                    <div className="flex gap-2 px-3 pb-3">
                        <button
                            onClick={() => !cartEmpty && completeSale({ paymentMethod: 'cash', amountTendered: total })}
                            disabled={cartEmpty}
                            className="flex-1 py-3.5 rounded-xl text-sm font-bold bg-gray-100 text-gray-700 active:scale-95 transition-all disabled:opacity-40">
                            💵 Cash
                        </button>
                        <button
                            onClick={() => !cartEmpty && setModal('payment')}
                            disabled={cartEmpty}
                            className="flex-[2] py-3.5 rounded-xl text-sm font-bold bg-[#068B03] text-white shadow-lg active:scale-95 transition-all disabled:opacity-40">
                            PAYMENT →
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
                    <div className="relative bg-white rounded-t-2xl px-4 pt-4 pb-8 shadow-2xl">
                        {/* Handle bar */}
                        <div className="w-10 h-1 bg-gray-300 rounded-full mx-auto mb-4" />
                        <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-3">More Actions</p>

                        <div className="grid grid-cols-3 gap-2 mb-3">
                            <SheetBtn
                                label="Delete Item"
                                disabled={selectedIdx === null}
                                onClick={() => { removeItem(selectedIdx); setShowMobileMore(false); }}
                                color="red"
                            />
                            <SheetBtn
                                label="Change Qty"
                                disabled={selectedIdx === null}
                                onClick={() => { setModal('quantity'); setShowMobileMore(false); }}
                            />
                            <SheetBtn
                                label="Void Order"
                                disabled={cartEmpty}
                                onClick={() => { clearCart(); setShowMobileMore(false); }}
                                color="orange"
                            />
                            <SheetBtn
                                label="Suspend Sale"
                                disabled={cartEmpty}
                                onClick={() => { setModal('suspend'); setShowMobileMore(false); }}
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

                        {CONFIG.panelUrl && (
                            <a href={CONFIG.panelUrl}
                                className="block text-center text-xs text-gray-400 hover:text-[#068B03] py-2 mt-1">
                                ← Back to Dashboard
                            </a>
                        )}
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
