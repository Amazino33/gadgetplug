import { useEffect, useState } from 'react';
import AmountKeypad from './AmountKeypad';
import ModalShell from './ModalShell';
import { fmt } from '../lib/format';
import api from '../lib/api';

/**
 * Money paid out of the drawer, written down while it is happening.
 *
 * A cashier hands the driver ₦3,000 at eleven in the morning. Recording it then,
 * with the receipt still in their hand, is the whole point: an expense
 * reconstructed at eight in the evening — after seeing the drawer is short — is
 * a story nobody can check. This is the paid-out slip that used to live in the
 * till, kept where it has always been kept.
 *
 * Deliberately short. Amount, what it was for, a note if they want one. A
 * cashier with a customer waiting will not fill in a form, and a control nobody
 * uses protects nothing.
 *
 * Online only, and it says so. The expense has to reach the accounts as it is
 * recorded — one that exists on the device alone would leave the books believing
 * the shop still has the money, and the drawer count would disagree with them.
 */
export default function ExpenseModal({ vendorId, onClose, onRecorded }) {
    const [amount, setAmount] = useState('');
    const [category, setCategory] = useState('logistics_other');
    const [note, setNote] = useState('');
    const [categories, setCategories] = useState({ logistics_other: 'Transport / delivery', other: 'Something else' });
    const [today, setToday] = useState([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [justSaved, setJustSaved] = useState(null);

    useEffect(() => {
        let cancelled = false;

        api.get('/expenses', { params: { vendor_id: vendorId } })
            .then(({ data }) => {
                if (cancelled) return;
                if (data?.categories) setCategories(data.categories);
                setToday(data?.expenses ?? []);
            })
            .catch(() => {});

        return () => { cancelled = true; };
    }, [vendorId]);

    const save = async () => {
        setError(null);

        const value = Number(amount);

        if (!Number.isFinite(value) || value <= 0) {
            setError('Enter how much left the drawer.');

            return;
        }

        setBusy(true);

        try {
            const { data } = await api.post('/expenses', {
                vendor_id: vendorId,
                amount: value,
                category,
                description: note.trim() || null,
            });

            setToday((rows) => [data.expense, ...rows]);
            setAmount('');
            setNote('');
            // Said out loud, briefly. A form that empties itself with no word
            // leaves a cashier wondering whether it went in at all.
            setJustSaved(value);
            setTimeout(() => setJustSaved(null), 2500);
            onRecorded?.(data.expense);
        } catch (e) {
            setError(
                e?.response?.data?.message
                    ?? 'Could not record it. This one needs a connection — the money has to reach the books as it leaves the drawer.',
            );
        } finally {
            setBusy(false);
        }
    };

    const spentToday = today.reduce((sum, row) => sum + Number(row.amount ?? 0), 0);

    return (
        <ModalShell
            title="Money out of the drawer"
            onClose={onClose}
            maxWidth="max-w-sm"
            footer={(
                <button
                    onClick={save}
                    disabled={busy || amount === ''}
                    className="w-full rounded-xl bg-[#068B03] py-3.5 text-base font-bold text-white transition-all active:scale-95 disabled:opacity-40"
                >
                    {busy ? 'Recording…' : 'Record it'}
                </button>
            )}
        >
            <div className="mb-3 grid grid-cols-2 gap-2">
                {Object.entries(categories).map(([key, label]) => (
                    <button
                        key={key}
                        onClick={() => setCategory(key)}
                        className={`rounded-xl border-2 py-2.5 text-xs font-bold transition-all active:scale-95 ${
                            category === key
                                ? 'border-[#068B03] bg-[#068B03]/5 text-[#068B03]'
                                : 'border-gray-200 text-gray-500 dark:border-gray-700 dark:text-gray-400'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <AmountKeypad
                value={amount}
                onChange={(next) => { setAmount(next); setError(null); }}
                autoFocusLabel="Amount paid out"
                onSubmit={() => { if (amount !== '' && !busy) save(); }}
            />

            <input
                value={note}
                onChange={(e) => setNote(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && amount !== '' && !busy) save(); }}
                aria-label="What it was for"
                placeholder="What was it for? (optional)"
                className="mt-3 w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm text-gray-800 focus:border-[#068B03] focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
            />

            {error && (
                <p className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-center text-xs font-semibold text-red-600 dark:bg-red-950/40 dark:text-red-300">
                    {error}
                </p>
            )}

            {justSaved !== null && !error && (
                <p className="mt-2 rounded-lg bg-[#068B03]/10 px-3 py-2 text-center text-xs font-semibold text-[#068B03]">
                    {fmt(justSaved)} recorded
                </p>
            )}

            {today.length > 0 && (
                <div className="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <div className="mb-1.5 flex items-baseline justify-between">
                        <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            Paid out today
                        </p>
                        <p className="text-sm font-extrabold tabular-nums text-gray-800 dark:text-gray-100">
                            {fmt(spentToday)}
                        </p>
                    </div>

                    {/* Shown back so a cashier can see they already wrote one
                        down, rather than recording it twice and going short by
                        the difference. This is the part that may scroll — it is
                        reference, not the job in hand. */}
                    {today.map((row) => (
                        <div key={row.id} className="flex items-center justify-between py-0.5 text-xs">
                            <span className="truncate pr-2 text-gray-500 dark:text-gray-400">
                                {row.description || categories[row.category] || row.category}
                            </span>
                            <span className="shrink-0 tabular-nums text-gray-700 dark:text-gray-300">{fmt(row.amount)}</span>
                        </div>
                    ))}

                    <p className="mt-2 text-[11px] leading-snug text-gray-400 dark:text-gray-500">
                        This comes off what your drawer should hold at cash-up, and your manager sees it.
                    </p>
                </div>
            )}
        </ModalShell>
    );
}
