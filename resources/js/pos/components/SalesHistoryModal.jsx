import { useEffect, useState } from 'react';
import { fmt } from '../lib/format';
import api from '../lib/api';

// A cashier's own sales — for looking something up and reprinting a receipt.
// Scoped server-side to sales this cashier personally rang up.
export default function SalesHistoryModal({ vendorId, onClose, onReprint }) {
    const [sales, setSales]     = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError]     = useState('');

    useEffect(() => {
        (async () => {
            try {
                const { data } = await api.get('/sales/my-history', { params: { vendor_id: vendorId } });
                setSales(data.data ?? []);
            } catch {
                setError('Could not load sales history. Check your connection.');
            } finally {
                setLoading(false);
            }
        })();
    }, [vendorId]);

    const statusBadge = (status) => {
        const styles = {
            completed:      'bg-green-100 text-green-700',
            voided:         'bg-red-100 text-red-700',
            refunded:       'bg-orange-100 text-orange-700',
            partial_refund: 'bg-orange-100 text-orange-700',
        };
        return styles[status] ?? 'bg-gray-100 text-gray-600';
    };

    const reprint = (sale) => {
        onReprint({
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

                <div className="overflow-y-auto px-6 py-4 space-y-2">
                    {loading && <p className="text-sm text-gray-400 text-center py-8">Loading…</p>}
                    {error && <p className="text-sm text-red-500 text-center py-8">{error}</p>}
                    {!loading && !error && sales.length === 0 && (
                        <p className="text-sm text-gray-400 text-center py-8">No sales yet today.</p>
                    )}
                    {sales.map((sale) => (
                        <div key={sale.id} className="rounded-xl border border-gray-200 dark:border-gray-800 p-3 flex items-center justify-between gap-3">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <p className="text-sm font-semibold text-gray-800 dark:text-gray-100">{sale.reference}</p>
                                    <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-medium ${statusBadge(sale.status)}`}>
                                        {sale.status.replace('_', ' ')}
                                    </span>
                                </div>
                                <p className="text-xs text-gray-500 dark:text-gray-400 truncate">
                                    {sale.items.map((i) => i.product_name).join(', ')}
                                </p>
                                <p className="text-xs text-gray-400 dark:text-gray-500">
                                    {new Date(sale.completed_at).toLocaleString('en-NG')}
                                </p>
                            </div>
                            <div className="text-right shrink-0">
                                <p className="text-sm font-bold text-gray-800 dark:text-gray-100 mb-1">{fmt(sale.total)}</p>
                                <button
                                    onClick={() => reprint(sale)}
                                    className="text-xs font-semibold text-[#068B03] hover:underline"
                                >
                                    Reprint
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
