import { useEffect, useRef, useState } from 'react';
import AmountKeypad from './AmountKeypad';
import ModalShell from './ModalShell';
import { fmt } from '../lib/format';
import api from '../lib/api';

const METHODS = [
    { key: 'cash', label: 'Cash' },
    { key: 'card', label: 'Card' },
    { key: 'bank_transfer', label: 'Transfer' },
];

/**
 * A customer paying down what they owe, from any branch, recorded the moment
 * it happens — the mirror of ExpenseModal for money arriving instead of
 * leaving. Online only, for the same reason: the amount has to be checked
 * against what is actually still owed right now, not against a balance this
 * till last saw, and cash-vs-terminal has to land correctly the first time.
 */
export default function DebtRepaymentModal({ vendorId, onClose, onRecorded }) {
    const [query, setQuery]       = useState('');
    const [results, setResults]   = useState([]);
    const [customer, setCustomer] = useState(null);
    const [outstanding, setOutstanding] = useState(null);
    const [amount, setAmount]     = useState('');
    const [method, setMethod]     = useState('cash');
    const [note, setNote]         = useState('');
    const [busy, setBusy]         = useState(false);
    const [error, setError]       = useState(null);
    const [justSaved, setJustSaved] = useState(null);
    const inputRef = useRef(null);

    useEffect(() => { if (!customer) inputRef.current?.focus(); }, [customer]);

    const search = async (q) => {
        setQuery(q);

        if (!q.trim()) { setResults([]); return; }

        try {
            const { data } = await api.get('/customers', { params: { vendor_id: vendorId, q } });
            setResults(data);
        } catch {
            setResults([]);
        }
    };

    const selectCustomer = async (c) => {
        setCustomer(c);
        setError(null);

        try {
            const { data } = await api.get(`/customers/${c.id}/outstanding`, { params: { vendor_id: vendorId } });
            setOutstanding(data.outstanding);
        } catch {
            setOutstanding(null);
        }
    };

    const save = async () => {
        setError(null);

        const value = Number(amount);

        if (!Number.isFinite(value) || value <= 0) {
            setError('Enter how much was collected.');

            return;
        }

        if (outstanding !== null && value - outstanding > 0.009) {
            setError(`${customer.name} owes ${fmt(outstanding)}, so ${fmt(value)} cannot be collected.`);

            return;
        }

        setBusy(true);

        try {
            const { data } = await api.post(`/customers/${customer.id}/repayments`, {
                vendor_id: vendorId,
                amount: value,
                method,
                note: note.trim() || null,
            });

            setOutstanding(data.outstanding);
            setJustSaved(value);
            setAmount('');
            setNote('');
            setTimeout(() => setJustSaved(null), 2500);
            onRecorded?.(data.payment);
        } catch (e) {
            setError(
                e?.response?.data?.message
                    ?? 'Could not record it. This one needs a connection — the amount has to be checked against what they actually still owe.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <ModalShell
            title="Debt repayment"
            onClose={onClose}
            maxWidth="max-w-sm"
            footer={customer ? (
                <button
                    onClick={save}
                    disabled={busy || amount === ''}
                    className="w-full rounded-xl bg-[#068B03] py-3.5 text-base font-bold text-white transition-all active:scale-95 disabled:opacity-40"
                >
                    {busy ? 'Recording…' : 'Record it'}
                </button>
            ) : null}
        >
            {!customer ? (
                <>
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={(e) => search(e.target.value)}
                        placeholder="Search by name or phone…"
                        className="mb-3 w-full rounded-xl border-2 border-gray-200 px-4 py-3 text-sm focus:border-[#068B03] focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    />
                    <div className="max-h-72 space-y-1 overflow-y-auto">
                        {results.map((c) => (
                            <button
                                key={c.id}
                                onClick={() => selectCustomer(c)}
                                className="flex w-full items-center justify-between rounded-xl px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-800"
                            >
                                <div>
                                    <p className="text-sm font-medium text-gray-800 dark:text-gray-100">{c.name}</p>
                                    <p className="text-xs text-gray-400">{c.phone}</p>
                                </div>
                            </button>
                        ))}
                        {results.length === 0 && query && (
                            <p className="py-4 text-center text-sm text-gray-400">No customers match.</p>
                        )}
                    </div>
                </>
            ) : (
                <>
                    <div className="mb-3 flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-800">
                        <div>
                            <p className="text-sm font-semibold text-gray-800 dark:text-gray-100">{customer.name}</p>
                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                {outstanding === null ? 'Checking balance…' : `Owes ${fmt(outstanding)}`}
                            </p>
                        </div>
                        <button
                            onClick={() => { setCustomer(null); setOutstanding(null); setQuery(''); setResults([]); }}
                            className="text-xs text-gray-400 hover:text-gray-600"
                        >
                            Change
                        </button>
                    </div>

                    {outstanding === 0 && (
                        <p className="mb-3 rounded-lg bg-gray-50 px-3 py-2 text-center text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                            This customer owes nothing right now.
                        </p>
                    )}

                    <div className="mb-3 grid grid-cols-3 gap-2">
                        {METHODS.map(({ key, label }) => (
                            <button
                                key={key}
                                onClick={() => setMethod(key)}
                                className={`rounded-xl border-2 py-2.5 text-xs font-bold transition-all active:scale-95 ${
                                    method === key
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
                        autoFocusLabel="Amount collected"
                        onSubmit={() => { if (amount !== '' && !busy) save(); }}
                    />

                    <input
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter' && amount !== '' && !busy) save(); }}
                        aria-label="Note"
                        placeholder="Note (optional)"
                        className="mt-3 w-full rounded-xl border-2 border-gray-200 px-4 py-2.5 text-sm text-gray-800 focus:border-[#068B03] focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    />

                    {error && (
                        <p className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-center text-xs font-semibold text-red-600 dark:bg-red-950/40 dark:text-red-300">
                            {error}
                        </p>
                    )}

                    {justSaved !== null && !error && (
                        <p className="mt-2 rounded-lg bg-[#068B03]/10 px-3 py-2 text-center text-xs font-semibold text-[#068B03]">
                            {fmt(justSaved)} collected
                        </p>
                    )}

                    <p className="mt-3 text-[11px] leading-snug text-gray-400 dark:text-gray-500">
                        {method === 'cash'
                            ? 'This adds to what your drawer should hold at cash-up.'
                            : 'This adds to what your terminal should show at cash-up.'}
                    </p>
                </>
            )}
        </ModalShell>
    );
}
