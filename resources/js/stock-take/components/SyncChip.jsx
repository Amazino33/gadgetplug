/**
 * The sync chip (A9), small and always at the top of the counting screens.
 * Tapping it when something needs attention opens that list.
 */
export default function SyncChip({ status, onAttention }) {
    const { kind, waiting, attention, queuedHandIn } = status;

    const look = {
        saved:     ['bg-green-100 text-green-900 dark:bg-green-900/40 dark:text-green-100', '✓ All saved'],
        waiting:   ['bg-gray-200 text-gray-800 dark:bg-gray-800 dark:text-gray-100', queuedHandIn && !waiting ? '⬆ Submitting…' : `⬆ ${waiting} waiting`],
        offline:   ['bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100', '📴 Offline — saved on phone'],
        attention: ['bg-red-600 text-white', `⚠ ${attention} need${attention === 1 ? 's' : ''} attention`],
    }[kind];

    return (
        <button
            type="button"
            onClick={kind === 'attention' ? onAttention : undefined}
            aria-label={`Sync: ${look[1]}`}
            className={`inline-flex min-h-8 shrink-0 items-center whitespace-nowrap rounded-full px-2.5 py-1 text-sm font-bold ${look[0]} ${kind === 'attention' ? '' : 'cursor-default'}`}
        >
            {look[1]}
        </button>
    );
}
