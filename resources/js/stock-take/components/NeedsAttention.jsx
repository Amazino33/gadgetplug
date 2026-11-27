import { formatQty } from '../lib/units';

/** What to do about a count the server refused, from its reason. */
export function adviceFor(reason) {
    const text = String(reason ?? '');
    if (/already counted/i.test(text)) return 'Another task has already counted it. Take this count back. If you think the other count is wrong, tell the manager.';
    if (/no longer open|not open|cancel/i.test(text)) return 'The task was closed or cancelled. Ask the manager: nothing more can be saved on it.';
    if (/half|whole/i.test(text)) return 'Count it again in whole units.';
    if (/not part of|not on this recount|not in this task|aisle/i.test(text)) return 'It can’t be counted on this task. Take it back.';
    return 'Count it again, or take it back.';
}

/**
 * Needs attention (A5): what the server refused, each with the reason and
 * what to do. A list to work through, never a pop-up in the middle of counting.
 */
export default function NeedsAttention({ status, productName, aisleLabel, onCountAgain, onTakeBack, onOpenHandIn }) {
    const { rejected, handIn, blocked } = status;

    if (!rejected.length && handIn?.status !== 'refused' && !blocked) {
        return <p className="rounded-2xl bg-green-50 p-4 text-base font-semibold text-green-900 dark:bg-green-900/30 dark:text-green-100">Nothing needs attention.</p>;
    }

    return (
        <ul className="space-y-2">
            {blocked && (
                <li className="rounded-2xl border-2 border-red-500 bg-red-50 p-3 text-base text-red-900 dark:bg-red-900/30 dark:text-red-100">
                    <p className="font-bold">On another phone</p>
                    <p>{blocked} Counts made here stay on this phone until then.</p>
                </li>
            )}

            {handIn?.status === 'refused' && (
                <li className="rounded-2xl border-2 border-red-500 bg-white p-3 dark:bg-gray-900">
                    <p className="text-base font-bold text-gray-900 dark:text-white">Submission not accepted</p>
                    <p className="text-base text-gray-700 dark:text-gray-200">{handIn.reason}</p>
                    <button type="button" onClick={onOpenHandIn} className="mt-2 h-12 w-full rounded-xl bg-teal-700 text-base font-bold text-white">Try submitting again</button>
                </li>
            )}

            {rejected.map((row) => (
                <li key={`${row.aisleId}:${row.productId}`} className="rounded-2xl border border-red-300 bg-white p-3 dark:border-red-800 dark:bg-gray-900">
                    <p className="text-base font-bold text-gray-900 dark:text-white">{productName(row.productId)}</p>
                    <p className="text-sm text-gray-600 dark:text-gray-300">{aisleLabel(row.aisleId)} · counted {formatQty(row.counted)}</p>
                    <p className="mt-1 text-base font-semibold text-red-700 dark:text-red-300">⚠ {row.reason ?? 'Not accepted'}</p>
                    <p className="text-base text-gray-700 dark:text-gray-200">{adviceFor(row.reason)}</p>
                    <div className="mt-2 grid grid-cols-2 gap-2">
                        <button type="button" onClick={() => onCountAgain(row)} className="h-12 rounded-xl border-2 border-teal-700 text-base font-bold text-teal-800 dark:text-teal-200">Count again</button>
                        <button type="button" onClick={() => onTakeBack(row)} className="h-12 rounded-xl bg-gray-800 text-base font-bold text-white">Take back</button>
                    </div>
                </li>
            ))}
        </ul>
    );
}
