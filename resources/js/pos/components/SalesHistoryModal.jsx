import { useEffect, useState } from 'react';
import api from '../lib/api';
import { localSales, mergeSales, refusedSales, takeBackRefusedSale } from '../lib/salesHistory';
import SaleHistoryRow from './SaleHistoryRow';

// A cashier's own sales — for looking something up and reprinting a receipt.
// Scoped server-side to sales this cashier personally rang up.
//
// Deliberately just the latest few. Anything older, or any particular day, is
// the full history (onOpenFullHistory), which sits behind its own permission;
// this list does not, because every cashier has to be able to reprint.
export default function SalesHistoryModal({ vendorId, cashierId, onClose, onReprint, onReturnToCart, onOpenFullHistory = null, cartEmpty = true }) {
    const [sales, setSales]     = useState([]);
    // Refusal reasons by offline id. A refused sale used to read "Pending
    // upload" here, exactly like one merely waiting for signal, so a cashier
    // looking at a sale the server had turned down had no way to tell, and
    // nothing to press.
    const [refused, setRefused] = useState(new Map());
    const [loading, setLoading] = useState(true);
    const [offline, setOffline] = useState(false);
    const [expandedRef, setExpandedRef] = useState(null);

    useEffect(() => {
        (async () => {
            const local = await localSales(cashierId).catch(() => []);
            setRefused(await refusedSales().catch(() => new Map()));

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

    const returnToCart = async (e, sale) => {
        e.stopPropagation();

        const taken = await takeBackRefusedSale(sale.offline_id);

        if (taken) onReturnToCart?.(taken.lines, taken.completedAt);
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
                        const isRefused = sale.pending_sync && refused.has(sale.offline_id);

                        return (
                            <SaleHistoryRow
                                key={ref}
                                sale={sale}
                                expanded={expandedRef === ref}
                                onToggle={() => setExpandedRef(expandedRef === ref ? null : ref)}
                                onReprint={onReprint}
                                badges={isRefused ? (
                                    <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-red-100 text-red-700">Refused</span>
                                ) : sale.pending_sync && (
                                    <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">Pending upload</span>
                                )}
                                notice={isRefused && (
                                    <p className="text-xs font-medium text-red-700 dark:text-red-400 mt-1">
                                        {refused.get(sale.offline_id)}
                                    </p>
                                )}
                                actions={isRefused && onReturnToCart && (
                                    <button
                                        onClick={(e) => returnToCart(e, sale)}
                                        disabled={!cartEmpty}
                                        title={cartEmpty ? undefined : 'Finish or clear the sale on screen first'}
                                        className="mt-1 text-xs font-semibold text-red-700 hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
                                    >
                                        Return to cart
                                    </button>
                                )}
                            />
                        );
                    })}
                </div>

                {onOpenFullHistory && (
                    <div className="shrink-0 border-t border-gray-100 dark:border-gray-800 px-6 py-3">
                        <button
                            onClick={onOpenFullHistory}
                            className="w-full rounded-xl border border-[#068B03] py-2.5 text-sm font-semibold text-[#068B03] hover:bg-green-50 dark:hover:bg-green-900/20"
                        >
                            View full history by day or date range
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
