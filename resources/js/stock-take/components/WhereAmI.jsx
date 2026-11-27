import { useState } from 'react';
import api, { errorMessage } from '../lib/api';

/**
 * "Which aisle are you in?" — count and map in one walk (Quick count).
 *
 * Shown when the counter starts, when they tap the aisle in the header, and
 * every 30 minutes (`check`), in case they moved on and forgot to say so.
 * The check covers the whole screen and the camera ignores scans until it is
 * answered: one tap on "Yes, still here" if nothing changed.
 *
 * An aisle nobody set up can be added by name; with no signal it is made on
 * the phone and created on the server when signal returns.
 * "No aisle" counts without recording where.
 */
export default function WhereAmI({ taskId, aisles, current, check = false, onPick, onAdded, onAddLocal, onClose }) {
    const [adding, setAdding] = useState(false);
    const [name, setName]     = useState('');
    const [busy, setBusy]     = useState(false);
    const [error, setError]   = useState('');

    const real  = aisles.filter((a) => !a.quick);
    const none  = aisles.find((a) => a.quick);

    const add = async (e) => {
        e.preventDefault();
        const value = name.trim();
        if (!value) return;
        setBusy(true);
        setError('');
        try {
            const { data } = await api.post(`/tasks/${taskId}/aisles`, { name: value });
            await onAdded(data.aisle);
            if (data.aisle.held_by) {
                setError(`${data.aisle.label} is with ${data.aisle.held_by}'s count. Pick another aisle.`);
            } else {
                onPick(data.aisle);
            }
        } catch (err) {
            if (err?.response) {
                setError(errorMessage(err));
            } else {
                // No signal: made on the phone now, created on the server when signal returns.
                const local = await onAddLocal?.(value);
                if (local) onPick(local);
                else setError('Could not add that aisle on this phone.');
            }
        } finally {
            setBusy(false);
        }
    };

    const row = (a, label = a.label) => {
        const held = !!a.held_by;
        const here = current?.id === a.id;
        return (
            <li key={a.id}>
                <button
                    type="button"
                    disabled={held}
                    onClick={() => onPick(a)}
                    className={`flex min-h-14 w-full items-center justify-between gap-2 rounded-2xl border p-3 text-left ${here ? 'border-teal-600 bg-teal-50 dark:bg-teal-900/30' : 'border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900'} disabled:opacity-50`}
                >
                    <span className="text-lg font-bold text-gray-900 dark:text-gray-100">{label}</span>
                    <span className="shrink-0 text-sm font-semibold text-gray-500">{held ? `With ${a.held_by}` : here ? 'Here now' : ''}</span>
                </button>
            </li>
        );
    };

    return (
        <div role="dialog" aria-label="Which aisle are you in?" className="fixed inset-0 z-50 flex flex-col bg-[#F3F4F6] dark:bg-gray-950">
            <header className="flex shrink-0 items-center gap-2 border-b border-gray-200 bg-white px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
                <h1 className="min-w-0 flex-1 text-xl font-extrabold text-gray-900 dark:text-white">
                    {check ? 'Are you still here?' : 'Which aisle are you in?'}
                </h1>
                {!check && onClose && (
                    <button type="button" onClick={onClose} aria-label="Close" className="flex h-12 w-12 items-center justify-center rounded-xl text-2xl text-gray-500">✕</button>
                )}
            </header>

            <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-3 pb-8">
                {check && current && (
                    <>
                        <p className="px-1 text-base text-gray-600 dark:text-gray-300">
                            It has been 30 minutes. Counts are saved to the aisle you are in, so the shelf map is right.
                        </p>
                        <button
                            type="button"
                            onClick={() => onPick(current)}
                            className="h-16 w-full rounded-2xl bg-teal-700 text-lg font-extrabold text-white shadow-sm active:scale-[0.99]"
                        >
                            Yes, still in {current.quick ? 'no aisle' : current.label}
                        </button>
                        <p className="px-1 pt-2 text-sm font-bold uppercase tracking-wide text-gray-500">No, I am in</p>
                    </>
                )}

                <ul className="space-y-2">
                    {real.map((a) => row(a))}
                </ul>

                {adding ? (
                    <form onSubmit={add} className="space-y-2 rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                        <label htmlFor="new-aisle" className="block text-sm font-bold text-gray-700 dark:text-gray-200">Name of this aisle</label>
                        <input
                            id="new-aisle"
                            autoFocus
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            maxLength={80}
                            placeholder="Aisle 12, Fridge, Back store"
                            className="w-full rounded-xl border border-gray-300 bg-white px-3 py-3 text-base outline-none focus:border-teal-600 dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                        />
                        <div className="flex gap-2">
                            <button type="submit" disabled={busy || !name.trim()} className="h-12 flex-1 rounded-xl bg-teal-700 text-base font-extrabold text-white disabled:opacity-50">
                                {busy ? 'Adding…' : 'Add and count here'}
                            </button>
                            <button type="button" onClick={() => { setAdding(false); setError(''); }} className="h-12 rounded-xl border border-gray-300 px-4 text-base font-bold text-gray-700 dark:border-gray-700 dark:text-gray-200">Cancel</button>
                        </div>
                    </form>
                ) : (
                    <button type="button" onClick={() => setAdding(true)} className="h-14 w-full rounded-2xl border-2 border-dashed border-teal-600 text-base font-extrabold text-teal-800 dark:text-teal-200">
                        + An aisle not on this list
                    </button>
                )}

                {error && <p role="alert" className="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">{error}</p>}

                {none && (
                    <ul className="pt-2">
                        {row(none, 'No aisle (just count)')}
                    </ul>
                )}
            </div>
        </div>
    );
}
