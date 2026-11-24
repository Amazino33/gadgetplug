import { useEffect, useRef, useState } from 'react';
import ModalShell from '../../pos/components/ModalShell';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { printGrn } from '../lib/grn';
import { fmt } from '../../pos/lib/format';

const input = 'w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2.5 text-sm text-gray-900 dark:text-gray-100 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20';
const label = 'mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';

/** Escape closes, the way every till modal does. */
function useEscape(onClose) {
    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);
}

/**
 * The scanned barcode named nothing in this shop. Make the product here, with
 * only what selling it needs, and it goes straight onto the purchase.
 */
export function CreateProductModal({ code = '', name: initialName = '', onCreated, onClose }) {
    const [form, setForm] = useState({ name: initialName, barcode: code, base_unit: 'Piece', price: '' });
    const [errors, setErrors] = useState({});
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const nameRef = useRef(null);

    useEscape(onClose);
    useEffect(() => { nameRef.current?.focus(); }, []);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

    const submit = async (e) => {
        e?.preventDefault();
        if (saving) return;
        setSaving(true);
        setError('');
        setErrors({});
        try {
            const { data } = await api.post('/products', { ...form, price: form.price === '' ? null : form.price });
            onCreated(data.product);
        } catch (err) {
            setErrors(fieldErrors(err));
            setError(errorMessage(err));
            setSaving(false);
        }
    };

    return (
        <ModalShell
            title="New product"
            onClose={onClose}
            footer={(
                <div className="flex gap-2">
                    <button type="button" onClick={onClose} className="flex-1 rounded-xl border border-gray-200 dark:border-gray-700 py-2.5 text-sm font-semibold text-gray-600 dark:text-gray-300">Cancel</button>
                    <button type="submit" form="create-product" disabled={saving} className="flex-1 rounded-xl bg-orange-600 py-2.5 text-sm font-bold text-white hover:bg-orange-700 disabled:opacity-50">
                        {saving ? 'Saving…' : 'Create & add'}
                    </button>
                </div>
            )}
        >
            <form id="create-product" onSubmit={submit} className="space-y-3">
                {code && (
                    <p className="rounded-lg bg-orange-50 dark:bg-orange-950/40 px-3 py-2 text-xs text-orange-800 dark:text-orange-300">
                        No product in this shop has barcode <strong className="font-mono">{code}</strong>. Create it now and it is added to this purchase.
                    </p>
                )}
                <div>
                    <label className={label}>Product name</label>
                    <input ref={nameRef} className={input} value={form.name} onChange={set('name')} required />
                    {errors.name && <p className="mt-1 text-xs text-red-600">{errors.name[0]}</p>}
                </div>
                <div>
                    <label className={label}>Barcode</label>
                    <input className={`${input} font-mono`} value={form.barcode} onChange={set('barcode')} />
                    {errors.barcode && <p className="mt-1 text-xs text-red-600">{errors.barcode[0]}</p>}
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label className={label}>Base unit</label>
                        <input className={input} value={form.base_unit} onChange={set('base_unit')} placeholder="Piece" />
                    </div>
                    <div>
                        <label className={label}>Selling price (₦)</label>
                        <input className={input} type="number" min="0" step="0.01" inputMode="decimal" value={form.price} onChange={set('price')} required />
                        {errors.price && <p className="mt-1 text-xs text-red-600">{errors.price[0]}</p>}
                    </div>
                </div>
                {error && !Object.keys(errors).length && <p className="text-xs font-semibold text-red-600">{error}</p>}
            </form>
        </ModalShell>
    );
}

export function SupplierModal({ onCreated, onClose }) {
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const ref = useRef(null);

    useEscape(onClose);
    useEffect(() => { ref.current?.focus(); }, []);

    const submit = async (e) => {
        e?.preventDefault();
        if (saving) return;
        setSaving(true);
        try {
            const { data } = await api.post('/suppliers', { name, phone: phone || null });
            onCreated(data.supplier);
        } catch (err) {
            setError(errorMessage(err));
            setSaving(false);
        }
    };

    return (
        <ModalShell
            title="New supplier"
            onClose={onClose}
            footer={(
                <button type="submit" form="new-supplier" disabled={saving} className="w-full rounded-xl bg-orange-600 py-2.5 text-sm font-bold text-white hover:bg-orange-700 disabled:opacity-50">
                    {saving ? 'Saving…' : 'Add supplier'}
                </button>
            )}
        >
            <form id="new-supplier" onSubmit={submit} className="space-y-3">
                <div>
                    <label className={label}>Name</label>
                    <input ref={ref} className={input} value={name} onChange={(e) => setName(e.target.value)} required />
                </div>
                <div>
                    <label className={label}>Phone (optional)</label>
                    <input className={input} value={phone} onChange={(e) => setPhone(e.target.value)} inputMode="tel" />
                </div>
                {error && <p className="text-xs font-semibold text-red-600">{error}</p>}
            </form>
        </ModalShell>
    );
}

const METHODS = window.PURCHASES_CONFIG?.methods ?? { cash: 'Cash', transfer: 'Transfer', pos: 'POS', other: 'Other' };

/**
 * How a purchase was paid — asked every time stock is received, and again
 * whenever the supplier is paid later. Built like the till's checkout: the
 * total large at the top, three plain choices, and the balance that will be
 * owed shown live. Nothing confirms until a choice has been made, so "I forgot
 * to put the amount" cannot happen.
 *
 * `allowNone` is the receiving case: goods can arrive on credit. A later
 * payment always pays something.
 */
export function PaymentModal({ title, due, allowNone = true, busy = false, error = '', confirmLabel, onConfirm, onClose }) {
    const [option, setOption] = useState(null);
    const [amount, setAmount] = useState('');
    const [method, setMethod] = useState('transfer');
    const [note, setNote]     = useState('');
    const amountRef = useRef(null);

    useEscape(onClose);

    useEffect(() => {
        if (option === 'part') setTimeout(() => amountRef.current?.focus(), 0);
    }, [option]);

    const total   = Number(due) || 0;
    const paid    = option === 'full' ? total : option === 'part' ? Math.round((Number(amount) || 0) * 100) / 100 : 0;
    const balance = Math.max(0, Math.round((total - paid) * 100) / 100);

    const problem = option === null
        ? 'Choose how this was paid.'
        : option === 'part' && !(paid > 0 && paid < total)
            ? `Enter an amount between ₦0 and ${fmt(total)}.`
            : null;

    const confirm = () => {
        if (problem || busy) return;
        onConfirm({ option, amount: paid, method: option === 'none' ? null : method, note: note || null });
    };

    const choice = (value, text, hint) => (
        <button
            type="button"
            onClick={() => setOption(value)}
            aria-pressed={option === value}
            className={`flex-1 rounded-xl border-2 px-3 py-3 text-left transition-colors ${
                option === value
                    ? 'border-orange-500 bg-orange-50 dark:bg-orange-950/40'
                    : 'border-gray-200 dark:border-gray-700 hover:border-orange-300'
            }`}
        >
            <span className="block text-sm font-extrabold text-gray-900 dark:text-gray-100">{text}</span>
            <span className="block text-[11px] text-gray-500">{hint}</span>
        </button>
    );

    return (
        <ModalShell
            title={title}
            onClose={onClose}
            maxWidth="max-w-lg"
            footer={(
                <div className="space-y-2">
                    {(error || (option !== null && problem)) && (
                        <p role="alert" className="text-xs font-semibold text-red-600">{error || problem}</p>
                    )}
                    <button
                        type="button"
                        onClick={confirm}
                        disabled={!!problem || busy}
                        className="w-full rounded-xl bg-orange-600 py-3.5 text-base font-extrabold text-white shadow-sm hover:bg-orange-700 disabled:cursor-not-allowed disabled:bg-gray-300 dark:disabled:bg-gray-700"
                    >
                        {busy ? 'Saving…' : (confirmLabel ?? 'Confirm')}
                    </button>
                </div>
            )}
        >
            <div className="-mx-5 -mt-4 mb-4 bg-orange-600 px-5 py-4 text-center text-white">
                <p className="text-[11px] font-bold uppercase tracking-widest opacity-80">{allowNone ? 'Purchase total' : 'Owed to supplier'}</p>
                <p className="font-mono text-4xl font-extrabold tabular-nums">{fmt(total)}</p>
            </div>

            <div className="flex gap-2">
                {choice('full', 'Paid in Full', `Pay ${fmt(total)} now`)}
                {choice('part', 'Part Payment', 'Pay some now')}
                {allowNone && choice('none', 'Not Paid', 'On credit')}
            </div>

            {option === 'part' && (
                <div className="mt-4">
                    <label className={label} htmlFor="pay-amount">Amount paid</label>
                    <input
                        id="pay-amount"
                        ref={amountRef}
                        type="number"
                        min="0"
                        step="0.01"
                        inputMode="decimal"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); confirm(); } }}
                        className={`${input} text-lg font-bold tabular-nums`}
                        placeholder="0.00"
                    />
                </div>
            )}

            {option !== null && option !== 'none' && (
                <div className="mt-4">
                    <span className={label}>Paid by</span>
                    <div role="group" aria-label="Payment method" className="grid grid-cols-4 gap-2">
                        {Object.entries(METHODS).map(([value, text]) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setMethod(value)}
                                aria-pressed={method === value}
                                className={`rounded-lg border px-2 py-2 text-xs font-bold ${
                                    method === value
                                        ? 'border-orange-500 bg-orange-600 text-white'
                                        : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-orange-300'
                                }`}
                            >
                                {text}
                            </button>
                        ))}
                    </div>
                    <input
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        placeholder="Note (optional) — e.g. transfer reference"
                        className={`${input} mt-2`}
                        maxLength={500}
                    />
                </div>
            )}

            <div className={`mt-4 rounded-xl px-4 py-3 text-center ${balance > 0 ? 'bg-red-50 dark:bg-red-950/40' : 'bg-green-50 dark:bg-green-950/40'}`}>
                <p className="text-[11px] font-bold uppercase tracking-widest text-gray-500">Balance owed to supplier</p>
                <p className={`font-mono text-3xl font-extrabold tabular-nums ${balance > 0 ? 'text-red-600' : 'text-green-700 dark:text-green-400'}`}>
                    {option === null ? '—' : fmt(balance)}
                </p>
            </div>
        </ModalShell>
    );
}

/** What was just received, with the GRN to print. Closing it starts the next delivery. */
export function GrnModal({ purchase, storeName, onClose, title = 'Stock received' }) {
    useEscape(onClose);

    return (
        <ModalShell
            title={title}
            onClose={onClose}
            maxWidth="max-w-lg"
            footer={(
                <div className="flex gap-2">
                    <button type="button" onClick={() => printGrn(purchase, { storeName })} className="flex-1 rounded-xl border border-orange-300 py-2.5 text-sm font-bold text-orange-700 dark:text-orange-400 hover:bg-orange-50 dark:hover:bg-orange-950/40">
                        Print GRN
                    </button>
                    <button type="button" autoFocus onClick={onClose} className="flex-1 rounded-xl bg-orange-600 py-2.5 text-sm font-bold text-white hover:bg-orange-700">
                        Next delivery
                    </button>
                </div>
            )}
        >
            <div className="mb-3 flex items-baseline justify-between">
                <div>
                    <p className="text-lg font-extrabold text-gray-900 dark:text-gray-100">{purchase.reference}</p>
                    <p className="text-xs text-gray-500">{purchase.supplier}{purchase.invoice_number ? ` · Inv ${purchase.invoice_number}` : ''}</p>
                </div>
                <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-bold text-green-700">{purchase.status_label}</span>
            </div>
            <ul className="divide-y divide-gray-100 dark:divide-gray-800 text-sm">
                {purchase.lines.map((l) => (
                    <li key={l.id} className="flex justify-between gap-3 py-1.5">
                        <span className="truncate text-gray-700 dark:text-gray-300">{l.product_name} <span className="text-gray-400">× {l.unit_qty} {l.unit_name}</span></span>
                        <span className="tabular-nums text-gray-900 dark:text-gray-100">{fmt(l.line_total)}</span>
                    </li>
                ))}
            </ul>
            <div className="mt-3 space-y-1 border-t border-gray-100 dark:border-gray-800 pt-3 text-sm">
                <div className="flex justify-between font-bold"><span>Total</span><span className="tabular-nums">{fmt(purchase.total)}</span></div>
                <div className="flex justify-between text-gray-500"><span>Paid</span><span className="tabular-nums">{fmt(purchase.paid)}</span></div>
                <div className={`flex justify-between ${purchase.balance > 0 ? 'text-red-600 font-semibold' : 'text-gray-500'}`}><span>Owed to supplier</span><span className="tabular-nums">{fmt(purchase.balance)}</span></div>
            </div>
        </ModalShell>
    );
}
