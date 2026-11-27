import { useState } from 'react';
import api, { errorMessage } from '../lib/api';
import { newSaveId } from '../lib/saveId';

/**
 * Something on the shelf the system does not know. A name and a photo are
 * enough for a manager to create the product or link the barcode later; while
 * counting, how many were there too.
 *
 * Mapping posts to /unknown-items; counting passes `endpoint` for its task.
 */
export default function UnknownItemForm({ aisle, taskId, barcode: scanned, onClose, onSaved, endpoint = '/unknown-items', withQuantity = false }) {
    const [barcode, setBarcode] = useState(scanned ?? '');
    const [name, setName]       = useState('');
    const [photo, setPhoto]     = useState(null);
    const [qty, setQty]         = useState('');
    const [unit, setUnit]       = useState('');
    const [busy, setBusy]       = useState(false);
    const [error, setError]     = useState('');
    // One id for this form: pressing Save again after a lost answer records it once.
    const [saveId]              = useState(newSaveId);

    const submit = async (e) => {
        e.preventDefault();
        if (!name.trim()) { setError('Give it a name, as written on the pack.'); return; }

        const form = new FormData();
        form.append('aisle_id', aisle.id);
        if (taskId && endpoint === '/unknown-items') form.append('task_id', taskId);
        if (barcode.trim()) form.append('barcode', barcode.trim());
        form.append('name', name.trim());
        if (photo) form.append('photo', photo);
        if (withQuantity && qty.trim() !== '') form.append('qty', qty.trim());
        if (withQuantity && unit.trim() !== '') form.append('unit', unit.trim());
        if (withQuantity) form.append('save_id', saveId);

        setBusy(true);
        setError('');
        try {
            await api.post(endpoint, form);
            onSaved(name.trim());
        } catch (err) {
            setError(err?.response ? errorMessage(err) : 'Not saved — check the connection and press Save again.');
            setBusy(false);
        }
    };

    const field = 'mt-1 w-full rounded-xl border border-gray-300 px-3 py-3 text-base dark:border-gray-700 dark:bg-gray-800 dark:text-white';

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/50 sm:items-center" role="dialog" aria-label="Not in the system">
            <form onSubmit={submit} className="max-h-full w-full max-w-md space-y-3 overflow-y-auto rounded-t-3xl bg-white p-5 dark:bg-gray-900 sm:rounded-3xl">
                <h2 className="text-lg font-extrabold text-gray-800 dark:text-gray-100">Not in the system</h2>
                <p className="text-sm text-gray-500">
                    {scanned ? `Nothing in this shop has the barcode ${scanned}.` : 'Write down what it is.'} A manager will add it or link it.
                </p>

                <label className="block text-sm font-semibold text-gray-700 dark:text-gray-200">
                    Name on the pack
                    <input value={name} onChange={(e) => setName(e.target.value)} autoFocus className={field} />
                </label>
                <label className="block text-sm font-semibold text-gray-700 dark:text-gray-200">
                    Barcode
                    <input value={barcode} onChange={(e) => setBarcode(e.target.value)} inputMode="numeric" className={field} />
                </label>
                {withQuantity && (
                    <div className="flex gap-2">
                        <label className="block w-1/2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                            How many
                            <input value={qty} onChange={(e) => setQty(e.target.value)} inputMode="decimal" placeholder="0" className={field} />
                        </label>
                        <label className="block w-1/2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                            Of what
                            <input value={unit} onChange={(e) => setUnit(e.target.value)} placeholder="Piece, Carton…" className={field} />
                        </label>
                    </div>
                )}
                <label className="block text-sm font-semibold text-gray-700 dark:text-gray-200">
                    Photo
                    <input type="file" accept="image/*" capture="environment" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} className="mt-1 block w-full text-sm" />
                </label>

                {error && <p role="alert" className="text-sm font-semibold text-red-600">{error}</p>}

                <div className="flex gap-2 pt-1">
                    <button type="button" onClick={onClose} className="flex-1 rounded-xl border border-gray-300 py-3 text-base font-bold text-gray-700 dark:border-gray-700 dark:text-gray-200">Cancel</button>
                    <button type="submit" disabled={busy} className="flex-1 rounded-xl bg-teal-700 py-3 text-base font-bold text-white disabled:opacity-50">Save</button>
                </div>
            </form>
        </div>
    );
}
