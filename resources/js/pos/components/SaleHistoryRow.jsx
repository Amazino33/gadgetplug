import { fmt } from '../lib/format';

// One sale in a cashier's history, folded or opened out. Shared by the quick
// My Sales list and the full history so a sale reads the same in both.

const STATUS_STYLES = {
    completed:      'bg-green-100 text-green-700',
    voided:         'bg-red-100 text-red-700',
    refunded:       'bg-orange-100 text-orange-700',
    partial_refund: 'bg-orange-100 text-orange-700',
};

export const paymentLabel = (method) => {
    if (!method) return 'Unknown';
    if (method === 'split') return 'Split Payment';
    if (method === 'debt') return 'Credit / Debt';
    if (method === 'bank_transfer') return 'Bank Transfer';
    return method.charAt(0).toUpperCase() + method.slice(1);
};

/** What ReceiptModal needs to reprint a sale exactly as it first came out. */
export const toReprint = (sale) => ({
    id:                      sale.id,
    reference:               sale.reference,
    // Without this the receipt fell back to the current clock, so a
    // reprint of an older sale was stamped with today's date and time.
    completed_at:            sale.completed_at,
    cashier_name:            sale.cashier_name ?? null,
    vat_rate:                sale.vat_rate ?? null,
    vat_enabled:             sale.vat_enabled ?? null,
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
    // The device now keeps the customer's name with the sale, so a
    // reprint names them exactly as the first copy did. It was pinned
    // to null because nothing local had one to give.
    customer:                sale.customer ?? null,
});

/**
 * @param badges   extra chips beside the reference (e.g. Pending upload)
 * @param notice   a line under the date (e.g. why the server refused it)
 * @param actions  extra buttons under Reprint
 * @param timeOnly show the time without the date, for a list already grouped by day
 */
export default function SaleHistoryRow({ sale, expanded, onToggle, onReprint, badges = null, notice = null, actions = null, timeOnly = false }) {
    const at = new Date(sale.completed_at);

    return (
        <div
            onClick={onToggle}
            className={`rounded-xl border ${expanded ? 'border-green-500 shadow-sm dark:border-green-600' : 'border-gray-200 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-700'} p-3 cursor-pointer transition-colors`}
        >
            <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="text-sm font-semibold text-gray-800 dark:text-gray-100">{sale.reference ?? 'Not uploaded yet'}</p>
                        {badges}
                        <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-medium ${STATUS_STYLES[sale.status] ?? 'bg-gray-100 text-gray-600'}`}>
                            {sale.status.replace('_', ' ')}
                        </span>
                    </div>
                    <p className="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">
                        {sale.items.map((i) => i.product_name).join(', ')}
                    </p>
                    <p className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                        {timeOnly
                            ? at.toLocaleTimeString('en-NG', { hour: '2-digit', minute: '2-digit' })
                            : at.toLocaleString('en-NG')}
                        {timeOnly && ` · ${paymentLabel(sale.payment_method)}`}
                    </p>
                    {notice}
                </div>
                <div className="text-right shrink-0 flex flex-col items-end">
                    <p className="text-sm font-bold text-gray-800 dark:text-gray-100 mb-1">{fmt(sale.total)}</p>
                    <button
                        onClick={(e) => { e.stopPropagation(); onReprint(toReprint(sale)); }}
                        className="text-xs font-semibold text-[#068B03] hover:underline"
                    >
                        Reprint
                    </button>
                    {actions}
                </div>
            </div>

            {expanded && (
                <div className="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                    <div className="mb-2 flex items-center justify-between">
                        <span className="text-xs font-semibold text-gray-600 dark:text-gray-300">Payment: {paymentLabel(sale.payment_method)}</span>
                        {sale.payment_method === 'split' && sale.payments && (
                            <div className="text-[10px] text-gray-500 flex gap-2">
                                {sale.payments.map((p, i) => (
                                    <span key={i}>{paymentLabel(p.method)}: {fmt(p.amount)}</span>
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
}
