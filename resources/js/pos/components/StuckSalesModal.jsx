import { fmt } from '../lib/format';
import { db } from '../lib/db';
import { forgetUnsyncedSale } from '../lib/salesHistory';
import { cartLinesFromSale } from '../lib/recoverSale';

// Sales that reached the server and were genuinely rejected (insufficient
// stock, a price below floor, etc.) — not a connectivity problem, so retrying
// silently forever would just fail identically. Surfaced here so a manager
// can see exactly what happened and fix the underlying cause, then retry.
export default function StuckSalesModal({ sales, onClose, onRetried, onReturnToCart, cartEmpty = true }) {
    const retry = async (id) => {
        await db.offlineSales.update(id, { sync_status: undefined, sync_error: undefined });
        onRetried();
    };

    // Retry alone is a dead end for the commonest refusal there is: a price
    // below the floor fails identically every time, however often it is tried.
    // This hands the sale back to the till so the line can be corrected and
    // rung again.
    //
    // Safe to take back because a refused sale was never recorded: the server
    // rolled its transaction back, so no stock moved and no money was posted.
    // The only trace is on this device, and both copies go here.
    const returnToCart = async (sale) => {
        const items = sale.items ?? [];

        // Rebuilt from today's catalogue rather than from the sale's own lines
        // — see lib/recoverSale for why the bare lines could not be repriced.
        const catalogue = await db.products.bulkGet(items.map((i) => i.product_id));

        await db.offlineSales.delete(sale.id);
        await forgetUnsyncedSale(sale.offline_id);

        // The day the goods actually left, carried back so the corrected sale
        // is recorded then rather than on the day it was put right.
        onReturnToCart(cartLinesFromSale(items, catalogue), sale.completed_at ?? null);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] flex flex-col">
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                    <h2 className="text-sm font-bold text-gray-800 dark:text-gray-100">
                        {sales.length} sale{sales.length !== 1 ? 's' : ''} need attention
                    </h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="overflow-y-auto px-6 py-4 space-y-3">
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        These sales were rung up on this till but the server refused them — the goods left the shelf,
                        but the sale is not recorded yet. Fix the reason below (e.g. add stock) and tap Retry, or
                        send the sale back to the till to correct a price or a line and ring it again.
                    </p>
                    {sales.map((sale) => (
                        <div key={sale.id} className="rounded-xl border border-red-200 dark:border-red-900/40 bg-red-50 dark:bg-red-900/10 p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold text-gray-800 dark:text-gray-100">
                                        {sale.items.map((i) => `${i.product_name} x${i.quantity}`).join(', ')}
                                    </p>
                                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {new Date(sale.completed_at).toLocaleString('en-NG')} · {fmt(sale.total)}
                                    </p>
                                    <p className="text-xs font-medium text-red-700 dark:text-red-400 mt-1">
                                        {sale.sync_error || 'Rejected by the server.'}
                                    </p>
                                </div>
                                <div className="shrink-0 flex flex-col gap-1.5">
                                    <button
                                        onClick={() => retry(sale.id)}
                                        className="rounded-lg bg-white dark:bg-gray-800 border border-red-200 dark:border-red-900/40 px-3 py-1.5 text-xs font-semibold text-red-700 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/20"
                                    >
                                        Retry
                                    </button>
                                    <button
                                        onClick={() => returnToCart(sale)}
                                        disabled={! cartEmpty}
                                        title={cartEmpty ? undefined : 'Finish or clear the sale on screen first'}
                                        className="rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 disabled:cursor-not-allowed"
                                    >
                                        Return to cart
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
