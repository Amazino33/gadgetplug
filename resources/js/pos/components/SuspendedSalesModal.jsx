import { fmt, timeAgo } from '../lib/format';

// The picker for sales that were put on hold.
//
// This list used to live at the bottom of the sidebar, under the Suspend
// button — which on a 768px-tall till sits below the fold, so a cashier who
// held a sale had no way to see it, let alone get it back. It is reachable
// from the top bar now, and lists the items so the right sale can be picked
// without resuming each one to look.
export default function SuspendedSalesModal({ sales = [], cartEmpty, error, onResume, onDiscard, onClose }) {
    const saleTotal = (sale) =>
        (sale.cart_data?.items ?? []).reduce(
            // Mirrors the cart's own line arithmetic, line discount included,
            // so the figure here matches what resuming will actually show.
            (sum, item) =>
                sum + Number(item.price ?? 0) * Number(item.qty ?? 0) - Number(item.lineDiscount ?? 0),
            0,
        );

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85dvh] flex flex-col">

                <div className="shrink-0 flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                    <div>
                        <h2 className="text-sm font-bold text-gray-800 dark:text-gray-100">Suspended Sales</h2>
                        <p className="text-[11px] text-gray-400 mt-0.5">
                            {sales.length === 0
                                ? 'Nothing on hold'
                                : `${sales.length} on hold · from any till`}
                        </p>
                    </div>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Resuming and discarding both happen from in here now, so their
                    failures have to be visible in here — the sidebar's copy of
                    this message sits behind the overlay. */}
                {error && (
                    <div className="shrink-0 px-6 py-2.5 bg-red-50 dark:bg-red-950/40 border-b border-red-100 dark:border-red-900">
                        <p className="text-[11px] font-semibold text-red-600 dark:text-red-400">{error}</p>
                    </div>
                )}

                {/* Said once, up top, rather than repeated under every row */}
                {!cartEmpty && sales.length > 0 && (
                    <div className="shrink-0 px-6 py-2.5 bg-amber-50 dark:bg-amber-950/40 border-b border-amber-100 dark:border-amber-900">
                        <p className="text-[11px] font-semibold text-amber-700 dark:text-amber-400">
                            Finish or void the sale on screen before resuming one of these.
                        </p>
                    </div>
                )}

                <div className="flex-1 min-h-0 overflow-y-auto px-6 py-4 space-y-2">
                    {sales.length === 0 && (
                        <div className="py-10 text-center">
                            <p className="text-sm text-gray-400">No sales are on hold.</p>
                            <p className="text-xs text-gray-400 mt-1">
                                Press F9 to hold the sale on screen and serve the next customer.
                            </p>
                        </div>
                    )}

                    {sales.map((sale) => {
                        const items = sale.cart_data?.items ?? [];

                        return (
                            <div key={sale.id}
                                className="rounded-xl border border-orange-200 dark:border-orange-900 bg-orange-50 dark:bg-orange-950/40 px-3 py-2.5">
                                <div className="flex items-start gap-3">
                                    <button
                                        onClick={() => cartEmpty && onResume(sale)}
                                        disabled={!cartEmpty}
                                        className="flex-1 min-w-0 text-left disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">
                                                {sale.label || sale.customer?.name || `Sale #${sale.id}`}
                                            </p>
                                            <p className="text-sm font-bold text-gray-800 dark:text-gray-100 shrink-0">
                                                {fmt(saleTotal(sale))}
                                            </p>
                                        </div>
                                        <p className="text-[11px] text-gray-500 dark:text-gray-400 truncate mt-0.5">
                                            {items.map((i) => `${i.qty}× ${i.name}`).join(', ') || 'No items'}
                                        </p>
                                        <p className="text-[10px] text-gray-400 mt-0.5">
                                            {items.length} item(s) · held {timeAgo(sale.created_at)}
                                        </p>
                                    </button>

                                    <button
                                        onClick={() => onDiscard(sale.id)}
                                        className="shrink-0 text-[11px] font-semibold text-red-400 hover:text-red-600 px-1"
                                        aria-label="Discard held sale"
                                    >
                                        Discard
                                    </button>
                                </div>

                                {cartEmpty && (
                                    <button
                                        onClick={() => onResume(sale)}
                                        className="mt-2 w-full py-2 rounded-lg bg-[#068B03] text-white text-xs font-bold hover:bg-[#057002] active:scale-95 transition-all"
                                    >
                                        Resume this sale
                                    </button>
                                )}
                            </div>
                        );
                    })}
                </div>

                <div className="shrink-0 px-6 py-3 border-t border-gray-100 dark:border-gray-800">
                    <button
                        onClick={onClose}
                        className="w-full py-2.5 rounded-xl border-2 border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                    >
                        Close [Esc]
                    </button>
                </div>
            </div>
        </div>
    );
}
