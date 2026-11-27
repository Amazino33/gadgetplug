import { useEffect, useState } from 'react';
import api, { errorMessage } from '../lib/api';

/**
 * Pick the aisle to map: a mapping task's own aisles, or — for someone who
 * may map anywhere — every active aisle at the branch.
 */
export default function AislePicker({ task, onBack, onPick, onFinished }) {
    const [aisles, setAisles] = useState(task ? task.aisles : null);
    const [error, setError]   = useState('');
    const [busy, setBusy]     = useState(false);

    useEffect(() => {
        if (task) return;
        api.get('/aisles')
            .then(({ data }) => setAisles(data.aisles))
            .catch((e) => setError(errorMessage(e, 'Could not load the aisles.')));
    }, [task]);

    const finish = async () => {
        if (!window.confirm('Mark this mapping task as done? You will not be able to map these aisles from it again.')) return;
        setBusy(true);
        try {
            await api.post(`/tasks/${task.id}/finish`);
            onFinished();
        } catch (e) {
            setError(errorMessage(e));
            setBusy(false);
        }
    };

    return (
        <div className="mx-auto max-w-xl space-y-3 p-3">
            <button type="button" onClick={onBack} className="px-1 text-sm font-semibold text-teal-700">← My tasks</button>
            <h1 className="px-1 text-lg font-extrabold text-gray-800 dark:text-gray-100">Which aisle?</h1>

            {error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700">{error}</p>}
            {aisles === null && !error && <p className="p-4 text-center text-sm text-gray-400">Loading aisles…</p>}
            {aisles?.length === 0 && (
                <p className="rounded-2xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                    No aisles yet. An admin adds them under Inventory → Aisles.
                </p>
            )}

            <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {aisles?.map((aisle) => (
                    <li key={aisle.id}>
                        <button
                            type="button"
                            onClick={() => onPick(aisle)}
                            className="flex w-full items-center justify-between rounded-2xl border border-gray-200 bg-white p-4 text-left shadow-sm active:scale-[0.99] dark:border-gray-800 dark:bg-gray-900"
                        >
                            <span className="text-base font-bold text-gray-800 dark:text-gray-100">{aisle.label}</span>
                            <span className="text-xs text-gray-500">{aisle.products_count} mapped</span>
                        </button>
                    </li>
                ))}
            </ul>

            {task && (
                <button
                    type="button"
                    disabled={busy}
                    onClick={finish}
                    className="w-full rounded-2xl border-2 border-teal-700 px-4 py-3 text-base font-bold text-teal-800 disabled:opacity-50"
                >
                    Mapping done
                </button>
            )}
        </div>
    );
}
