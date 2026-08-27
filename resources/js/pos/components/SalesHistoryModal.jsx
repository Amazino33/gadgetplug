import { useEffect, useState } from 'react';
import { fmt } from '../lib/format';
import api from '../lib/api';
import { localSales, mergeSales } from '../lib/salesHistory';

// A cashier's own sales — for looking something up and reprinting a receipt.
// Scoped server-side to sales this cashier personally rang up.
export default function SalesHistoryModal({ vendorId, cashierId, onClose, onReprint }) {
    const [sales, setSales]     = useState([]);
    const [loading, setLoading] = useState(true);
    const [offline, setOffline] = useState(false);
    const [expandedRef, setExpandedRef] = useState(null);

    useEffect(() => {
        (async () => {
            const local = await localSales(cashierId).catch(() => []);

            setSales(mergeSales(local, []));
            setLoading(false);

            try {
                const { data } = await api.get('/sales/my-history', { params: { vendor_id: vendorId } });
                setSales(mergeSales(local, data.data ?? []));
                setOffline(false);
            } catch {
                setOffline(true);
            }
        })();
    }, [vendorId, cashierId]);

    const statusBadge = (status) => {
        const styles = {
            completed:      'bg-green-100 text-green-700',
            voided:         'bg-red-100 text-red-700',
            refunded:       'bg-orange-100 text-orange-700',
            partial_refund: 'bg-orange-100 text-orange-700',
        };
        return styles[status] ?? 'bg-gray-100 text-gray-600';
    };

    const paymentLabel = (sale) => {
        if (!sale.payment_method) return 'Unknown';
        if (sale.payment_method === 'split') return 'Split Payment';
        if (sale.payment_method === 'debt') return 'Credit / Debt';
        if (sale.payment_method === 'bank_transfer') return 'Bank Transfer';
        return sale.payment_method.charAt(0).toUpperCase() + sale.payment_method.slice(1);
    };

    const reprint = (e, sale) => {
        e.stopPropagation();
        onReprint({
            id:                      sale.id,
            reference:               sale.reference,
            items:                   sale.items,
            subtotal:                sale.subtotal,
            discount_amount:         sale.discount_amount,
            vat_amount:              sale.vat_amount,
            total:                   sale.total,
            payment_method:          sale.payment_method,
            amount_tendered:         sale.amount_tendered,
            change_given:            sale.change_given,
            bank_transfer_reference: sale.bank_transfer_reference,
            payments:                sale.payments,
            customer:                null,
        });
    };

    const toggleExpand = (ref) => {
        setExpandedRef(expandedRef === ref ? null : ref);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col">
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                    <h2 className="text-sm font-bold text-gray-800 dark:text-gray-100">My Sales History</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="overflow-y-auto px-6 py-4 space-y-3">
                    {loading && <p className="text-sm text-gray-400 text-center py-8">Loading…</p>}

                    {offline && (
                        <p className="text-xs text-amber-600 dark:text-amber-400 text-center pb-2">
                            Offline — showing this device's record of the last 7 days.
                        </p>
                    )}

                    {!loading && sales.length === 0 && (
                        <p className="text-sm text-gray-400 text-center py-8">No sales yet.</p>
                    )}
                    {sales.map((sale) => {
                        const ref = sale.reference ?? sale.completed_at;
                        const isExpanded = expandedRef === ref;
                        
                        return (
                            <div 
                                key={ref} 
                                onClick={() => toggleExpand(ref)}
                                className={`rounded-xl border ${isExpanded ? 'border-green-500 shadow-sm dark:border-green-600' : 'border-gray-200 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-700'} p-3 cursor-pointer transition-colors`}
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <p className="text-sm font-semibold text-gray-800 dark:text-gray-100">{sale.reference ?? 'Not uploaded yet'}</p>
                                            {sale.pending_sync && (
                                                <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">Pending upload</span>
                                            )}
                                            <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-medium ${statusBadge(sale.status)}`}>
                                                {sale.status.replace('_', ' ')}
                                            </span>
                                        </div>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">
                                            {sale.items.map((i) => i.product_name).join(', ')}
                                        </p>
                                        <p className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                            {new Date(sale.completed_at).toLocaleString('en-NG')}
                                        </p>
                                    </div>
                                    <div className="text-right shrink-0 flex flex-col items-end">
                                        <p className="text-sm font-bold text-gray-800 dark:text-gray-100 mb-1">{fmt(sale.total)}</p>
                                        <button
                                            onClick={(e) => reprint(e, sale)}
                                            className="text-xs font-semibold text-[#068B03] hover:underline"
                                        >
                                            Reprint
                                        </button>
                                    </div>
                                </div>
                                
                                {isExpanded && (
                                    <div className="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                                        <div className="mb-2 flex items-center justify-between">
                                            <span className="text-xs font-semibold text-gray-600 dark:text-gray-300">Payment: {paymentLabel(sale)}</span>
                                            {sale.payment_method === 'split' && sale.payments && (
                                                <div className="text-[10px] text-gray-500 flex gap-2">
                                                    {sale.payments.map((p, i) => (
                                                        <span key={i}>{p.method}: {fmt(p.amount)}</span>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                        
                                        <div className="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-2 space-y-1.5">
                                            {sale.items.map((item, idx) => (
                                                <div key={idx} className="flex justify-between text-xs">
                                                    <div className="text-gray-700 dark:text-gray-300">
                                                        <span className="text-gray-500 mr-2">{item.quantity}x</span>
                                                        {item.product_name}
                                                    </div>
                                                    <div className="text-gray-800 dark:text-gray-200 font-medium">
                                                        {fmt(item.total)}
                                                    </div>
                                                </div>
                                            ))}
                                            
                                            {(Number(sale.discount_amount) > 0 || Number(sale.vat_amount) > 0) && (
                                                <div className="border-t border-gray-200 dark:border-gray-700 mt-1.5 pt-1.5">
                                                    {Number(sale.discount_amount) > 0 && (
                                                        <div className="flex justify-between text-xs text-red-600">
                                                            <span>Discount</span>
                                                            <span>-{fmt(sale.discount_amount)}</span>
                                                        </div>
                                                    )}
                                                    {Number(sale.vat_amount) > 0 && (
                                                        <div className="flex justify-between text-xs text-gray-500">
                                                            <span>VAT</span>
                                                            <span>{fmt(sale.vat_amount)}</span>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
