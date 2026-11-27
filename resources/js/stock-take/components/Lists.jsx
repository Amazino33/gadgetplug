import { describe, formatQty } from '../lib/units';

/**
 * The two lists beside the counting (B6): what's been counted here, newest
 * first (tap to change, ✕ to take back, with Undo), and what's still to count
 * on this aisle in mapping order, which is shelf order, so it doubles as
 * "next on the shelf". Tapping one there opens it to count.
 *
 * In a sheet on a phone; always on screen at a desk (B8).
 */
export default function Lists({ tab, onTab, counted, toCount, productName, aisleLabel, recount, onEdit, onTakeBack, onPick }) {
    const tabClass = (on) => `h-12 flex-1 rounded-xl text-base font-bold ${on ? 'bg-teal-700 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'}`;

    return (
        <div className="space-y-2">
            <div className="flex gap-1.5" role="tablist">
                <button type="button" role="tab" aria-selected={tab === 'counted'} onClick={() => onTab('counted')} className={tabClass(tab === 'counted')}>Counted ({counted.length})</button>
                <button type="button" role="tab" aria-selected={tab === 'todo'} onClick={() => onTab('todo')} className={tabClass(tab === 'todo')}>Still to count ({toCount.length})</button>
            </div>

            {tab === 'counted' && (
                <>
                    {counted.length === 0 && <p className="rounded-2xl border border-dashed border-gray-300 p-4 text-center text-base text-gray-500">Nothing counted yet. Scan the first product.</p>}
                    <ul className="space-y-1.5">
                        {counted.map((row) => {
                            const name = productName(row.productId);
                            return (
                                <li key={`${row.aisleId}:${row.productId}`} className="flex items-center gap-1 rounded-xl border border-gray-200 bg-white p-1.5 dark:border-gray-800 dark:bg-gray-900">
                                    <button type="button" onClick={() => onEdit(row)} className="min-w-0 flex-1 px-1 text-left">
                                        <p className="truncate text-base font-semibold text-gray-900 dark:text-gray-100">{name}</p>
                                        <p className="truncate text-base text-gray-600 dark:text-gray-300">
                                            {describe(row.breakdown, '0')} = <strong>{formatQty(row.counted)}</strong>
                                            {row.damaged ? <span className="font-semibold text-red-700 dark:text-red-300">{` · ${formatQty(row.damaged)} damaged`}</span> : ''}
                                            {row.expired ? <span className="font-semibold text-red-700 dark:text-red-300">{` · ${formatQty(row.expired)} expired`}</span> : ''}
                                            {row.unlisted ? ' · not on aisle list' : ''}
                                            {recount ? ` · ${aisleLabel(row.aisleId)}` : ''}
                                            {row.syncStatus === 'pending' ? ' · ⬆ waiting to upload' : ''}
                                        </p>
                                        {row.syncStatus === 'rejected' && <p className="text-base font-semibold text-red-600">⚠ {row.reason ?? 'Not accepted'}</p>}
                                    </button>
                                    <button type="button" onClick={() => onTakeBack(row)} aria-label={`Take back ${name}`} className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-xl text-gray-500 active:bg-red-50 active:text-red-600">✕</button>
                                </li>
                            );
                        })}
                    </ul>
                </>
            )}

            {tab === 'todo' && (
                <>
                    {toCount.length === 0 && <p className="rounded-2xl bg-green-50 p-4 text-center text-base font-semibold text-green-900 dark:bg-green-900/30 dark:text-green-100">Everything expected here is counted.</p>}
                    <ol className="space-y-1.5">
                        {toCount.map((product, i) => (
                            <li key={product.id}>
                                <button type="button" onClick={() => onPick(product)} className="flex min-h-12 w-full items-center gap-2 rounded-xl border border-gray-200 bg-white px-2 py-1.5 text-left dark:border-gray-800 dark:bg-gray-900">
                                    <span className="w-8 shrink-0 text-right text-sm font-bold text-gray-500">{i + 1}</span>
                                    <span className="min-w-0 flex-1 truncate text-base font-semibold text-gray-900 dark:text-gray-100">{product.name}</span>
                                    <span className="shrink-0 text-sm font-bold text-teal-700">Count</span>
                                </button>
                            </li>
                        ))}
                    </ol>
                </>
            )}
        </div>
    );
}
