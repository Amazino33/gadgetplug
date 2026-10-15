import { useEffect, useRef } from 'react';
import { fmt } from '../lib/format';
import { printDocument } from '../lib/printDocument';
import { receiptHtml } from '../lib/receiptDocument';
import { vendorSettings } from '../lib/vendorSettings';
import { formatQty, hasHalf } from '../lib/halves';

const CONFIG = window.POS_CONFIG ?? {};

export default function ReceiptModal({ sale, onNewSale, isReprint = false }) {
    const { items, total, payment_method, amount_tendered, change_given,
            subtotal, discount_amount, vat_amount, reference,
            customer, bank_transfer_reference, payments } = sale;
    const isSplit = payment_method === 'split';

    // Auto-focus the "New Sale" button so Enter immediately starts next sale
    const newSaleRef = useRef(null);
    useEffect(() => {
        setTimeout(() => newSaleRef.current?.focus(), 100);
    }, []);

    // Prints an 80mm document, never this modal — and always the same one.
    //
    // Fresh sale, reprint, online, offline: every print is built here by
    // receiptHtml() from what the till holds, QR included. The QR comes from the
    // token the till minted for the sale, so it no longer depends on the server
    // having seen the sale, or on a connection to fetch a document from it. The
    // server-rendered copy is not fetched for printing any more: two builders
    // meant two receipts that drifted apart, and a cached copy outlived the
    // stylesheet it was printed with.
    const print = () => {
        printDocument(receiptHtml(sale, vendorSettings()));
    };

    // The sale's own time, not the clock. Reading `new Date()` here meant a
    // reprint of yesterday's sale was stamped, on screen and on paper, today.
    const soldAt = (() => {
        const parsed = new Date(sale.completed_at ?? Date.now());

        return Number.isNaN(parsed.getTime()) ? new Date() : parsed;
    })();
    const dateStr = soldAt.toLocaleDateString('en-NG', { day: '2-digit', month: 'short', year: 'numeric' });
    const timeStr = soldAt.toLocaleTimeString('en-NG', { hour: '2-digit', minute: '2-digit' });

    // VAT is a per-vendor setting. This line read a hardcoded 7.5% and printed
    // it whether or not the store charges VAT at all, or charges it at another
    // rate — a wrong tax figure on a customer's receipt.
    const vatEnabled = sale.vat_enabled ?? vendorSettings().vat_enabled ?? true;
    const vatRate    = sale.vat_rate ?? vendorSettings().vat_rate ?? 7.5;
    const showVat    = vatEnabled && Number(vat_amount) > 0;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
            {/* Bounded to the screen and laid out as a column: the header and the
                buttons stay put while the middle scrolls. Without a max height
                the card simply grew with the item list, so on a long sale the
                Print and New Sale buttons sat below the bottom of the screen
                with nothing to scroll — the till looked frozen. */}
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 max-h-[90dvh] flex flex-col overflow-hidden">

                {/* Success / reprint header */}
                <div className={`shrink-0 px-6 py-6 text-center ${isReprint ? 'bg-gray-700' : 'bg-[#068B03]'}`}>
                    <div className="w-14 h-14 rounded-full bg-white/20 flex items-center justify-center mx-auto mb-3">
                        {isReprint ? (
                            <svg className="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                    d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                        ) : (
                            <svg className="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                            </svg>
                        )}
                    </div>
                    <p className="text-white text-lg font-bold">{isReprint ? 'Receipt Reprint' : 'Sale Complete'}</p>
                    <p className="text-white/70 text-xs mt-1">{reference}</p>
                </div>

                {/* The scrolling middle. min-h-0 is what actually lets a flex
                    child shrink below its content and scroll. */}
                <div className="flex-1 min-h-0 overflow-y-auto">

                {/* Change due — cash single payment */}
                {payment_method === 'cash' && change_given > 0 && (
                    <div className="bg-amber-50 border-b border-amber-100 px-6 py-4 text-center">
                        <p className="text-xs font-semibold text-amber-600 uppercase tracking-wide">Change Due to Customer</p>
                        <p className="text-5xl font-extrabold text-amber-600 mt-1"
                            style={{ fontFamily: 'Montserrat, sans-serif' }}>
                            {fmt(change_given)}
                        </p>
                    </div>
                )}

                {/* Bank transfer reference — single payment */}
                {payment_method === 'bank_transfer' && bank_transfer_reference && (
                    <div className="bg-purple-50 border-b border-purple-100 px-6 py-3 text-center">
                        <p className="text-xs text-purple-500">Transfer Reference Logged</p>
                        <p className="text-lg font-bold text-purple-700">{bank_transfer_reference}</p>
                    </div>
                )}

                {/* Split payment summary banner */}
                {isSplit && change_given > 0 && (
                    <div className="bg-amber-50 border-b border-amber-100 px-6 py-4 text-center">
                        <p className="text-xs font-semibold text-amber-600 uppercase tracking-wide">Change Due to Customer</p>
                        <p className="text-5xl font-extrabold text-amber-600 mt-1"
                            style={{ fontFamily: 'Montserrat, sans-serif' }}>
                            {fmt(change_given)}
                        </p>
                    </div>
                )}

                {/* Receipt body. On screen only — the paper is a separate 80mm
                    document, see lib/receiptDocument.js. */}
                <div className="px-6 py-4">

                    {/* Store / date */}
                    <div className="flex justify-between items-start mb-4">
                        <div>
                            <p className="text-xs font-bold text-gray-700">{CONFIG.vendorName ?? 'GadgetPlug'}</p>
                            {customer && (
                                <p className="text-xs text-gray-400 mt-0.5">
                                    {customer.name}{customer.phone ? ` · ${customer.phone}` : ''}
                                </p>
                            )}
                        </div>
                        <div className="text-right">
                            <p className="text-xs text-gray-400">{dateStr}</p>
                            <p className="text-xs text-gray-400">{timeStr}</p>
                        </div>
                    </div>

                    {/* Items */}
                    <div className="border-t border-dashed border-gray-200 pt-3 mb-3 space-y-2">
                        {items.map((item, i) => (
                            <div key={i} className="flex justify-between items-start gap-2">
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs font-medium text-gray-800 truncate">{item.product_name}</p>
                                    {/* A line with a half: "₦10,100 × 1½ Crate", priced per
                                        unit sold rather than as an average per base unit. */}
                                    {hasHalf(item.unit_qty ?? item.quantity) ? (
                                        <p className="text-xs text-gray-400">
                                            {fmt(item.sold_unit_price ?? item.unit_price)} × {formatQty(item.unit_qty ?? item.quantity, true)} {item.unit_name ?? ''}
                                            {item.half_unit_price != null && ` (½ at ${fmt(item.half_unit_price)})`}
                                        </p>
                                    ) : (
                                        <p className="text-xs text-gray-400">{fmt(item.unit_price)} × {item.quantity}</p>
                                    )}
                                </div>
                                <p className="text-xs font-semibold text-gray-700 shrink-0">{fmt(item.total)}</p>
                            </div>
                        ))}
                    </div>

                    {/* Totals */}
                    <div className="border-t border-dashed border-gray-200 pt-3 space-y-1">
                        <div className="flex justify-between text-xs text-gray-500">
                            <span>Subtotal</span><span>{fmt(subtotal)}</span>
                        </div>
                        {discount_amount > 0 && (
                            <div className="flex justify-between text-xs text-[#F97316]">
                                <span>Discount</span><span>−{fmt(discount_amount)}</span>
                            </div>
                        )}
                        {showVat && (
                            <div className="flex justify-between text-xs text-gray-500">
                                <span>VAT ({vatRate}%)</span><span>{fmt(vat_amount)}</span>
                            </div>
                        )}
                        <div className="flex justify-between text-sm font-bold text-gray-800 pt-1 border-t border-gray-200">
                            <span>TOTAL</span><span>{fmt(total)}</span>
                        </div>
                        {!isSplit && (
                            <>
                                <div className="flex justify-between text-xs text-gray-500 pt-1">
                                    <span>Payment</span>
                                    <span className="capitalize">{payment_method.replace('_', ' ')}</span>
                                </div>
                                {payment_method === 'cash' && (
                                    <>
                                        <div className="flex justify-between text-xs text-gray-500">
                                            <span>Tendered</span><span>{fmt(amount_tendered)}</span>
                                        </div>
                                        <div className="flex justify-between text-xs font-semibold text-gray-700">
                                            <span>Change</span><span>{fmt(change_given)}</span>
                                        </div>
                                    </>
                                )}
                                {payment_method === 'bank_transfer' && bank_transfer_reference && (
                                    <div className="flex justify-between text-xs text-gray-500">
                                        <span>Reference</span><span>{bank_transfer_reference}</span>
                                    </div>
                                )}
                            </>
                        )}
                        {isSplit && payments?.length > 0 && (
                            <>
                                <div className="flex justify-between text-xs text-gray-500 pt-1">
                                    <span>Payment</span><span>Split</span>
                                </div>
                                {payments.map((p, i) => (
                                    <div key={i} className="flex justify-between text-xs text-gray-500">
                                        <span className="capitalize">{p.method.replace('_', ' ')}{p.reference ? ` (${p.reference})` : ''}</span>
                                        <span>{fmt(p.amount)}</span>
                                    </div>
                                ))}
                                {change_given > 0 && (
                                    <div className="flex justify-between text-xs font-semibold text-gray-700">
                                        <span>Change</span><span>{fmt(change_given)}</span>
                                    </div>
                                )}
                            </>
                        )}
                    </div>

                    <p className="text-center text-[10px] text-gray-300 mt-4">Thank you for shopping with us</p>
                </div>

                </div>
                {/* ── end scrolling middle ── */}

                {/* Actions — always reachable, whatever the sale's length */}
                <div className="shrink-0 flex gap-3 px-6 pt-4 pb-6 border-t border-gray-100 bg-white">
                    <button
                        onClick={print}
                        className="flex-1 py-3 rounded-xl border-2 border-gray-200 text-sm font-semibold text-gray-600 hover:bg-gray-50 flex items-center justify-center gap-2"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Print
                    </button>
                    <button
                        ref={newSaleRef}
                        onClick={onNewSale}
                        className="flex-1 py-3 rounded-xl bg-[#068B03] text-white text-sm font-bold hover:bg-[#057002] active:scale-95 transition-all"
                    >
                        {isReprint ? 'Close [Enter]' : 'New Sale [Enter]'}
                    </button>
                </div>
            </div>
        </div>
    );
}
