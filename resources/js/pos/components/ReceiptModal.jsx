import { useEffect, useRef } from 'react';
import { fmt } from '../lib/format';

const CONFIG = window.POS_CONFIG ?? {};

export default function ReceiptModal({ sale, onNewSale }) {
    const { items, total, payment_method, amount_tendered, change_given,
            subtotal, discount_amount, vat_amount, reference,
            customer, bank_transfer_reference } = sale;

    const printRef = useRef(null);

    // Auto-focus the "New Sale" button so Enter immediately starts next sale
    const newSaleRef = useRef(null);
    useEffect(() => {
        setTimeout(() => newSaleRef.current?.focus(), 100);
    }, []);

    const print = () => window.print();

    const now = new Date();
    const dateStr = now.toLocaleDateString('en-NG', { day: '2-digit', month: 'short', year: 'numeric' });
    const timeStr = now.toLocaleTimeString('en-NG', { hour: '2-digit', minute: '2-digit' });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">

                {/* Success header */}
                <div className="bg-[#068B03] px-6 py-6 text-center">
                    <div className="w-14 h-14 rounded-full bg-white/20 flex items-center justify-center mx-auto mb-3">
                        <svg className="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <p className="text-white text-lg font-bold">Sale Complete</p>
                    <p className="text-white/70 text-xs mt-1">{reference}</p>
                </div>

                {/* Change due — shown large for cash */}
                {payment_method === 'cash' && change_given > 0 && (
                    <div className="bg-amber-50 border-b border-amber-100 px-6 py-4 text-center">
                        <p className="text-xs font-semibold text-amber-600 uppercase tracking-wide">Change Due to Customer</p>
                        <p className="text-5xl font-extrabold text-amber-600 mt-1"
                            style={{ fontFamily: 'Montserrat, sans-serif' }}>
                            {fmt(change_given)}
                        </p>
                    </div>
                )}

                {/* Bank transfer reference confirmation */}
                {payment_method === 'bank_transfer' && bank_transfer_reference && (
                    <div className="bg-purple-50 border-b border-purple-100 px-6 py-3 text-center">
                        <p className="text-xs text-purple-500">Transfer Reference Logged</p>
                        <p className="text-lg font-bold text-purple-700">{bank_transfer_reference}</p>
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
                    </div>

                    <p className="text-center text-[10px] text-gray-300 mt-4">Thank you for shopping with us</p>
                </div>

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
                        New Sale [Enter]
                    </button>
                </div>
            </div>
        </div>
    );
}
