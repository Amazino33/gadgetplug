import { useEffect, useRef, useState } from 'react';
import { fmt } from '../lib/format';
import api from '../lib/api';

const CONFIG = window.POS_CONFIG ?? {};

export default function ReceiptModal({ sale, onNewSale, isReprint = false }) {
    const { items, total, payment_method, amount_tendered, change_given,
            subtotal, discount_amount, vat_amount, reference,
            customer, bank_transfer_reference, payments } = sale;
    const isSplit = payment_method === 'split';

    const printRef = useRef(null);

    // Surfaced on screen when the receipt document could not be fetched, so a
    // failing printer path is visible at the till instead of silently degrading.
    const [printWarning, setPrintWarning] = useState(null);

    // Auto-focus the "New Sale" button so Enter immediately starts next sale
    const newSaleRef = useRef(null);
    useEffect(() => {
        setTimeout(() => newSaleRef.current?.focus(), 100);
    }, []);

    // Prints the server-rendered 80mm document rather than this modal.
    //
    // Printing the modal meant hiding the whole app with `visibility: hidden`
    // and pinning the receipt with `position: fixed`, which cannot paginate —
    // a long sale was silently cut off at one page and the modal's own padding
    // leaked onto the paper. The standalone page also carries the vendor's
    // receipt settings, the cashier's name and the store address, none of which
    // exist in the browser here.
    //
    // A sale queued offline has no server id yet, so that case still falls back
    // to printing this modal.
    const print = async () => {
        if (!sale?.id) {
            // Offline this is expected — the sale has no server id until it
            // syncs. Online it means whoever built this sale object dropped the
            // id, and the till quietly prints the wrong thing. That mistake has
            // now been made twice (new sale, then reprint), so it says so.
            if (navigator.onLine) {
                console.warn('Receipt has no sale id — printing the fallback copy instead of the receipt document.');
                setPrintWarning('Printed a basic copy — this receipt was not linked to a saved sale.');
            }
            window.print();
            return;
        }

        const existing = document.getElementById('receipt-print-frame');
        if (existing) existing.remove();

        const frame = document.createElement('iframe');
        frame.id = 'receipt-print-frame';
        frame.setAttribute('aria-hidden', 'true');
        // Sized to the paper and parked off-screen rather than collapsed to
        // 0x0. A zero-width frame gives the receipt a zero-width containing
        // block to lay out against, and the printer then scales whatever it got
        // to fit the roll — which is what made the print blurry.
        frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:80mm;height:297mm;border:0;';
        document.body.appendChild(frame);

        try {
            const { data: html } = await api.get(`/sales/${sale.id}/receipt?print=1`);
            frame.contentWindow.document.open();
            frame.contentWindow.document.write(html);
            frame.contentWindow.document.close();
        } catch (err) {
            // Falling back silently is how this stayed hidden: the till printed
            // the modal — no QR, no store details, wrong paper size — and looked
            // like the receipt was simply badly designed rather than failing.
            // Say so, then still give the cashier paper.
            const status = err?.response?.status;
            const detail = status ? `HTTP ${status}` : (err?.message ?? 'network error');
            console.error(`Receipt document failed (${detail}) — printing the fallback copy.`, err);
            setPrintWarning(`Printed a basic copy — the full receipt could not be loaded (${detail}).`);
            window.print();
        }
    };

    const now = new Date();
    const dateStr = now.toLocaleDateString('en-NG', { day: '2-digit', month: 'short', year: 'numeric' });
    const timeStr = now.toLocaleTimeString('en-NG', { hour: '2-digit', minute: '2-digit' });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">

                {/* Success / reprint header */}
                <div className={`px-6 py-6 text-center ${isReprint ? 'bg-gray-700' : 'bg-[#068B03]'}`}>
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

                {/* Receipt body — also used for printing */}
                <div ref={printRef} className="print-receipt px-6 py-4">

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
                                    <p className="text-xs text-gray-400">{fmt(item.unit_price)} × {item.quantity}</p>
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
                        <div className="flex justify-between text-xs text-gray-500">
                            <span>VAT (7.5%)</span><span>{fmt(vat_amount)}</span>
                        </div>
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

                {printWarning && (
                    <p className="px-6 pb-2 text-[11px] text-amber-600">{printWarning}</p>
                )}

                {/* Actions */}
                <div className="flex gap-3 px-6 pb-6">
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
