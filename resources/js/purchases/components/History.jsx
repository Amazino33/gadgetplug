import { useCallback, useEffect, useState } from 'react';
import ModalShell from '../../pos/components/ModalShell';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { PaymentModal } from './Modals';
import { printGrn } from '../lib/grn';
import { fmt } from '../../pos/lib/format';
import { useWide } from '../lib/device';

const CONFIG = window.PURCHASES_CONFIG ?? {};
const STATUSES = CONFIG.statuses ?? {};
const PAYMENTS = CONFIG.payments ?? { full: 'Paid', part_payment: 'Part Paid', credit: 'Unpaid' };

const PAY_BADGE = {
    full: 'bg-green-100 text-green-700',
    part_payment: 'bg-amber-100 text-amber-800',
    credit: 'bg-red-100 text-red-700',
};

export function PaymentBadge({ status, label }) {
    if (!status) return <span className="text-[11px] text-gray-400">—</span>;

    return <span className={`inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-bold ${PAY_BADGE[status] ?? PAY_BADGE.credit}`}>{label ?? PAYMENTS[status]}</span>;
}
const field = 'rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20';

const BADGE = {
    draft: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    pending: 'bg-amber-100 text-amber-800',
    changes_requested: 'bg-blue-100 text-blue-800',
    approved: 'bg-green-100 text-green-700',
    voided: 'bg-red-100 text-red-700',
};

const dateText = (d) => (d ? new Date(d).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

export function StatusBadge({ status, label }) {
    return <span className={`inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-bold ${BADGE[status] ?? BADGE.draft}`}>{label ?? STATUSES[status] ?? status}</span>;
}

/**
 * Purchase History: every delivery, from this terminal and from the old
 * procurement screens alike.
 */
export default function History({ active, refreshKey, onContinue, onPaid }) {
    const [filters, setFilters] = useState({ from: '', to: '', supplier_id: '', status: '', payment: '', q: '' });
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [suppliers, setSuppliers] = useState([]);
    const [open, setOpen] = useState(null);
    // A payment against a received purchase ('pay'), or an approver receiving
    // a submitted one ('receive'). Both are the same modal.
    const [paying, setPaying] = useState(null); // { mode, purchase }
    const [payBusy, setPayBusy] = useState(false);
    const [payError, setPayError] = useState('');
    // Phones: purchases as cards, and the filters folded away behind a button
    // so the list starts at the top of the screen, not halfway down.
    const wide = useWide();
    const [showFilters, setShowFilters] = useState(false);
    const filterCount = ['from', 'to', 'supplier_id', 'status', 'payment'].filter((k) => filters[k] !== '').length;

    useEffect(() => {
        if (!active) return;
        api.get('/suppliers').then(({ data }) => setSuppliers(data.suppliers)).catch(() => {});
    }, [active, refreshKey]);

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const params = Object.fromEntries(Object.entries({ ...filters, page }).filter(([, v]) => v !== '' && v != null));
            const { data } = await api.get('/purchases', { params });
            setRows(data.data);
            setMeta(data.meta);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setLoading(false);
        }
    }, [filters, page]);

    // Typing in the search box waits a beat; everything else loads at once.
    useEffect(() => {
        if (!active) return undefined;
        const t = setTimeout(load, 250);
        return () => clearTimeout(t);
    }, [active, load, refreshKey]);

    const setF = (k) => (e) => { setPage(1); setFilters((f) => ({ ...f, [k]: e.target.value })); };

    const openRow = async (row) => {
        try {
            const { data } = await api.get(`/purchases/${row.id}`);
            setOpen(data.purchase);
        } catch (err) {
            setError(errorMessage(err));
        }
    };

    // Paying a supplier later. The list and any open detail both refresh from
    // what the server says the purchase now stands at.
    const recordPayment = async (payment) => {
        const { mode, purchase } = paying;
        setPayBusy(true);
        setPayError('');
        try {
            const { data } = mode === 'receive'
                ? await api.post(`/purchases/${purchase.id}/receive`, { payment })
                : await api.post(`/purchases/${purchase.id}/payments`, { amount: payment.amount, method: payment.method, note: payment.note });
            if (open?.id === purchase.id || mode === 'receive') setOpen(data.purchase);
            setPaying(null);
            load();
            onPaid?.();
        } catch (err) {
            const f = fieldErrors(err);
            setPayError(Object.values(f).flat()[0] ?? errorMessage(err));
        } finally {
            setPayBusy(false);
        }
    };

    return (
        <div className={`${active ? 'flex' : 'hidden'} min-h-0 flex-1 flex-col bg-white dark:bg-gray-900`}>
            <div className="flex shrink-0 flex-wrap items-end gap-2 border-b border-gray-100 dark:border-gray-800 px-3 py-3 sm:px-4">
                <input className={`${field} min-w-0 flex-1 md:min-w-48`} placeholder="Search reference, invoice or product…" value={filters.q} onChange={setF('q')} aria-label="Search purchases" />
                <button
                    type="button"
                    onClick={() => setShowFilters((v) => !v)}
                    aria-expanded={showFilters}
                    className={`rounded-xl border px-3 py-2 text-sm font-semibold md:hidden ${filterCount ? 'border-orange-400 bg-orange-50 text-orange-700 dark:bg-orange-950/40' : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'}`}
                >
                    Filters{filterCount ? ` (${filterCount})` : ''}
                </button>
                <div className={`${showFilters ? 'grid' : 'hidden'} w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-1 md:flex-wrap md:items-end`}>
                    <label className="flex flex-col text-[11px] text-gray-500 md:block">From<input type="date" className={`${field} md:ml-1`} value={filters.from} onChange={setF('from')} /></label>
                    <label className="flex flex-col text-[11px] text-gray-500 md:block">To<input type="date" className={`${field} md:ml-1`} value={filters.to} onChange={setF('to')} /></label>
                    <select className={`${field} col-span-2`} value={filters.supplier_id} onChange={setF('supplier_id')} aria-label="Supplier">
                        <option value="">All suppliers</option>
                        {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                    <select className={field} value={filters.status} onChange={setF('status')} aria-label="Status">
                        <option value="">All statuses</option>
                        {Object.entries(STATUSES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                    <select className={field} value={filters.payment} onChange={setF('payment')} aria-label="Payment">
                        <option value="">Any payment</option>
                        {Object.entries(PAYMENTS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                </div>
            </div>

            {error && <p className="mx-4 mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">{error}</p>}

            <div className="min-h-0 flex-1 overflow-auto">
                {!wide ? (
                    <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                        {rows.map((r) => (
                            <li key={r.id}>
                                <div
                                    role="button"
                                    tabIndex={0}
                                    onClick={() => openRow(r)}
                                    onKeyDown={(e) => { if (e.key === 'Enter') openRow(r); }}
                                    className="block px-3 py-3 active:bg-orange-50/60 dark:active:bg-orange-950/30"
                                >
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="truncate font-semibold text-gray-900 dark:text-gray-100">{r.reference}</span>
                                        <span className="shrink-0 font-mono font-bold tabular-nums text-gray-900 dark:text-gray-100">{fmt(r.total)}</span>
                                    </div>
                                    <div className="mt-0.5 flex items-baseline justify-between gap-2 text-xs text-gray-500">
                                        <span className="truncate">{r.supplier ?? '—'} · {dateText(r.date)} · {r.items_count} item{r.items_count === 1 ? '' : 's'}</span>
                                        {r.balance > 0 && <span className="shrink-0 font-semibold tabular-nums text-red-600">owe {fmt(r.balance)}</span>}
                                    </div>
                                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                        <StatusBadge status={r.status} label={r.status_label} />
                                        <PaymentBadge status={r.payment_status} label={r.payment_label} />
                                        {r.received_by && <span className="text-[11px] text-gray-400">by {r.received_by}</span>}
                                    </div>
                                    {(r.can_receive || r.can_pay) && (
                                        <div className="mt-2 flex gap-2">
                                            {r.can_receive && (
                                                <button
                                                    type="button"
                                                    onClick={(e) => { e.stopPropagation(); setPayError(''); setPaying({ mode: 'receive', purchase: r }); }}
                                                    className="flex-1 rounded-lg bg-orange-600 py-2.5 text-sm font-bold text-white"
                                                >
                                                    Approve &amp; Receive
                                                </button>
                                            )}
                                            {r.can_pay && (
                                                <button
                                                    type="button"
                                                    onClick={(e) => { e.stopPropagation(); setPayError(''); setPaying({ mode: 'pay', purchase: r }); }}
                                                    className="flex-1 rounded-lg border border-orange-300 py-2.5 text-sm font-bold text-orange-700 dark:text-orange-400"
                                                >
                                                    Record Payment
                                                </button>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </li>
                        ))}
                        {!loading && rows.length === 0 && (
                            <li className="px-4 py-12 text-center text-sm text-gray-400">No purchases match.</li>
                        )}
                    </ul>
                ) : (
                <table className="w-full min-w-[900px] text-sm">
                    <thead className="sticky top-0 bg-gray-50 dark:bg-gray-800 text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th className="px-4 py-2 text-left">Date</th>
                            <th className="px-3 py-2 text-left">Reference</th>
                            <th className="px-3 py-2 text-left">Supplier</th>
                            <th className="px-3 py-2 text-right">Items</th>
                            <th className="px-3 py-2 text-right">Total</th>
                            <th className="px-3 py-2 text-right">Paid</th>
                            <th className="px-3 py-2 text-right">Balance</th>
                            <th className="px-3 py-2 text-left">Status</th>
                            <th className="px-3 py-2 text-left">Payment</th>
                            <th className="px-3 py-2 text-left">Received by</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.id} onClick={() => openRow(r)} className="cursor-pointer border-b border-gray-100 dark:border-gray-800 hover:bg-orange-50/60 dark:hover:bg-orange-950/30">
                                <td className="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-300">{dateText(r.date)}</td>
                                <td className="px-3 py-2.5">
                                    <div className="font-semibold text-gray-900 dark:text-gray-100">{r.reference}</div>
                                    {r.invoice_number && <div className="text-[11px] text-gray-400">Inv {r.invoice_number}</div>}
                                </td>
                                <td className="px-3 py-2.5 text-gray-700 dark:text-gray-300">{r.supplier ?? '—'}</td>
                                <td className="px-3 py-2.5 text-right tabular-nums">{r.items_count}</td>
                                <td className="px-3 py-2.5 text-right font-semibold tabular-nums">{fmt(r.total)}</td>
                                <td className="px-3 py-2.5 text-right tabular-nums text-gray-600 dark:text-gray-300">{fmt(r.paid)}</td>
                                <td className={`px-3 py-2.5 text-right tabular-nums ${r.balance > 0 ? 'font-semibold text-red-600' : 'text-gray-400'}`}>{fmt(r.balance)}</td>
                                <td className="px-3 py-2.5"><StatusBadge status={r.status} label={r.status_label} /></td>
                                <td className="px-3 py-2.5"><PaymentBadge status={r.payment_status} label={r.payment_label} /></td>
                                <td className="px-3 py-2.5 text-gray-600 dark:text-gray-300">{r.received_by ?? '—'}</td>
                                <td className="px-3 py-2.5 text-right">
                                    {r.can_receive && (
                                        <button
                                            type="button"
                                            onClick={(e) => { e.stopPropagation(); setPayError(''); setPaying({ mode: 'receive', purchase: r }); }}
                                            className="whitespace-nowrap rounded-lg bg-orange-600 px-2.5 py-1 text-[11px] font-bold text-white hover:bg-orange-700"
                                        >
                                            Approve
                                        </button>
                                    )}
                                    {r.can_pay && (
                                        <button
                                            type="button"
                                            onClick={(e) => { e.stopPropagation(); setPayError(''); setPaying({ mode: 'pay', purchase: r }); }}
                                            className="whitespace-nowrap rounded-lg border border-orange-300 px-2.5 py-1 text-[11px] font-bold text-orange-700 hover:bg-orange-50 dark:text-orange-400 dark:hover:bg-orange-950/40"
                                        >
                                            Record Payment
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {!loading && rows.length === 0 && (
                            <tr><td colSpan={11} className="px-4 py-12 text-center text-sm text-gray-400">No purchases match.</td></tr>
                        )}
                    </tbody>
                </table>
                )}
            </div>

            <div className="flex shrink-0 items-center justify-between border-t border-gray-100 dark:border-gray-800 px-3 py-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] text-xs text-gray-500 sm:px-4">
                <span>{loading ? 'Loading…' : `${meta.total} purchase${meta.total === 1 ? '' : 's'}`}</span>
                <div className="flex items-center gap-2">
                    <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-1 disabled:opacity-40">Prev</button>
                    <span>{meta.current_page} / {meta.last_page}</span>
                    <button type="button" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)} className="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-1 disabled:opacity-40">Next</button>
                </div>
            </div>

            {open && !paying && (
                <DetailModal
                    purchase={open}
                    onClose={() => setOpen(null)}
                    onContinue={() => { const p = open; setOpen(null); onContinue(p); }}
                    onPay={() => { setPayError(''); setPaying({ mode: 'pay', purchase: open }); }}
                    onReceive={() => { setPayError(''); setPaying({ mode: 'receive', purchase: open }); }}
                />
            )}

            {paying && (
                <PaymentModal
                    title={paying.mode === 'receive'
                        ? `Approve & receive ${paying.purchase.reference}`
                        : `Pay ${paying.purchase.supplier ?? 'supplier'} · ${paying.purchase.reference}`}
                    due={paying.mode === 'receive' ? paying.purchase.total : paying.purchase.balance}
                    allowNone={paying.mode === 'receive'}
                    busy={payBusy}
                    error={payError}
                    confirmLabel={paying.mode === 'receive' ? 'Approve & Receive' : 'Record Payment'}
                    onClose={() => setPaying(null)}
                    onConfirm={recordPayment}
                />
            )}
        </div>
    );
}

function DetailModal({ purchase: p, onClose, onContinue, onPay, onReceive }) {
    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const isDraft = p.status === 'draft';
    const wide = useWide();

    return (
        <ModalShell
            title={`${p.reference}${p.invoice_number ? ` · Inv ${p.invoice_number}` : ''}`}
            onClose={onClose}
            maxWidth="max-w-4xl"
            footer={(
                <div className="flex flex-wrap gap-2">
                    {p.can_receive && (
                        <button type="button" onClick={onReceive} className="flex-1 rounded-xl bg-orange-600 py-2.5 text-sm font-bold text-white hover:bg-orange-700">
                            Approve &amp; Receive
                        </button>
                    )}
                    {p.can_resume && (
                        <button type="button" onClick={onContinue} className={`flex-1 rounded-xl py-2.5 text-sm font-bold ${isDraft ? 'bg-orange-600 text-white hover:bg-orange-700' : 'border border-orange-300 text-orange-700 hover:bg-orange-50 dark:text-orange-400'}`}>
                            {isDraft ? 'Continue in terminal' : 'Correct in terminal'}
                        </button>
                    )}
                    {p.can_pay && (
                        <button type="button" onClick={onPay} className="flex-1 rounded-xl bg-orange-600 py-2.5 text-sm font-bold text-white hover:bg-orange-700">
                            Record Payment
                        </button>
                    )}
                    {p.review_url && !isDraft && (
                        <a href={p.review_url} className="flex-1 rounded-xl border border-gray-200 dark:border-gray-700 py-2.5 text-center text-sm font-semibold text-gray-700 dark:text-gray-300">
                            Review in Procurements
                        </a>
                    )}
                    <button type="button" onClick={() => printGrn(p)} className="flex-1 rounded-xl border border-orange-300 py-2.5 text-sm font-bold text-orange-700 dark:text-orange-400 hover:bg-orange-50 dark:hover:bg-orange-950/40">
                        {p.status === 'approved' ? 'Reprint GRN' : 'Print'}
                    </button>
                </div>
            )}
        >
            <div className="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <Info label="Status"><StatusBadge status={p.status} label={p.status_label} /></Info>
                <Info label="Supplier">{p.supplier ?? '—'}</Info>
                <Info label="Date">{dateText(p.purchased_on || p.date)}</Info>
                <Info label="Branch">{p.store ?? '—'}</Info>
                <Info label="Recorded by">{p.recorded_by ?? '—'}</Info>
                <Info label="Received by">{p.received_by ?? '—'}</Info>
                <Info label="Received at">{p.received_at ? new Date(p.received_at).toLocaleString('en-NG') : '—'}</Info>
                <Info label="Payment"><PaymentBadge status={p.payment_status} label={p.payment_label} /></Info>
            </div>

            {p.status === 'approved' && (
                <p className="mb-3 text-[11px] text-gray-400">Received purchases are read-only.</p>
            )}
            {p.awaiting_approval && (
                <p className="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                    Submitted by {p.recorded_by ?? 'staff'}{p.submitted_at ? ` on ${new Date(p.submitted_at).toLocaleString('en-NG')}` : ''}. No stock has moved yet{p.can_receive ? ' — check it, then Approve & Receive.' : '; a manager will approve it.'}
                </p>
            )}

            {!wide ? (
                <ul className="divide-y divide-gray-100 rounded-xl border border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                    {p.lines.map((l) => {
                        const serials = [...(l.serials ?? []), ...(l.legacy_serial ? [l.legacy_serial] : [])];
                        return (
                            <li key={l.id} className="px-3 py-2.5 text-sm">
                                <div className="flex items-baseline justify-between gap-2">
                                    <span className="min-w-0 truncate font-semibold text-gray-800 dark:text-gray-100">{l.product_name}</span>
                                    <span className="shrink-0 font-semibold tabular-nums">{fmt(l.line_total)}</span>
                                </div>
                                <div className="mt-0.5 text-xs text-gray-500 tabular-nums">
                                    {l.unit_qty} {l.unit_name} × {fmt(l.cost_per_unit)}
                                    {l.factor > 1 ? ` · ${l.base_qty} base at ${fmt(l.base_cost)}` : ''}
                                </div>
                                <div className="text-xs text-gray-400 tabular-nums">Sells {fmt(l.selling_price)}{l.barcode || l.sku ? ` · ${l.barcode || l.sku}` : ''}</div>
                                {serials.length > 0 && <div className="mt-1 break-all font-mono text-[11px] text-gray-500">S/N: {serials.join(', ')}</div>}
                            </li>
                        );
                    })}
                </ul>
            ) : (
            <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-sm">
                    <thead className="bg-gray-50 dark:bg-gray-800 text-[10px] uppercase tracking-wide text-gray-500">
                        <tr>
                            <th className="px-3 py-2 text-left">Product</th>
                            <th className="px-2 py-2 text-right">Qty</th>
                            <th className="px-2 py-2 text-right">Base qty</th>
                            <th className="px-2 py-2 text-right">Cost / unit</th>
                            <th className="px-2 py-2 text-right">Cost / base</th>
                            <th className="px-2 py-2 text-right">Sell (base)</th>
                            <th className="px-2 py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {p.lines.map((l) => {
                            const serials = [...(l.serials ?? []), ...(l.legacy_serial ? [l.legacy_serial] : [])];
                            return (
                                <tr key={l.id} className="border-b border-gray-100 dark:border-gray-800 align-top">
                                    <td className="px-3 py-2">
                                        <div className="font-semibold text-gray-800 dark:text-gray-100">{l.product_name}</div>
                                        <div className="font-mono text-[11px] text-gray-400">{l.barcode || l.sku || '—'}</div>
                                        {serials.length > 0 && (
                                            <div className="mt-1 font-mono text-[11px] text-gray-500">S/N: {serials.join(', ')}</div>
                                        )}
                                    </td>
                                    <td className="px-2 py-2 text-right whitespace-nowrap">{l.unit_qty} {l.unit_name}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{l.base_qty}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{fmt(l.cost_per_unit)}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{fmt(l.base_cost)}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{fmt(l.selling_price)}</td>
                                    <td className="px-2 py-2 text-right font-semibold tabular-nums">{fmt(l.line_total)}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            )}

            <div className="ml-auto mt-3 w-64 space-y-1 text-sm">
                <div className="flex justify-between font-bold"><span>Total</span><span className="tabular-nums">{fmt(p.total)}</span></div>
                <div className="flex justify-between text-gray-500"><span>Paid</span><span className="tabular-nums">{fmt(p.paid)}</span></div>
                <div className={`flex justify-between ${p.balance > 0 ? 'font-semibold text-red-600' : 'text-gray-500'}`}><span>Balance</span><span className="tabular-nums">{fmt(p.balance)}</span></div>
            </div>
            {!isDraft && (
                <div className="mt-4">
                    <h3 className="mb-2 text-[11px] font-bold uppercase tracking-wide text-gray-500">Payments</h3>
                    {(p.payments ?? []).length === 0 ? (
                        <p className="text-xs text-gray-400">Nothing paid yet.</p>
                    ) : (
                        <ol className="space-y-2 border-l-2 border-orange-200 dark:border-orange-900 pl-4">
                            {p.payments.map((pay) => (
                                <li key={pay.id} className="relative">
                                    <span className="absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full bg-orange-500" />
                                    <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                        <span className="font-bold tabular-nums text-gray-900 dark:text-gray-100">{fmt(pay.amount)} <span className="font-normal text-gray-500">· {pay.method_label}</span></span>
                                        <span className="text-[11px] text-gray-400">{pay.paid_at ? new Date(pay.paid_at).toLocaleString('en-NG') : ''}{pay.recorded_by ? ` · ${pay.recorded_by}` : ''}</span>
                                    </div>
                                    {pay.note && <p className="text-xs text-gray-500">{pay.note}</p>}
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            )}
            {p.notes && <p className="mt-3 text-xs text-gray-500"><strong>Notes:</strong> {p.notes}</p>}
        </ModalShell>
    );
}

function Info({ label, children }) {
    return (
        <div>
            <div className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{label}</div>
            <div className="text-gray-800 dark:text-gray-200">{children}</div>
        </div>
    );
}
