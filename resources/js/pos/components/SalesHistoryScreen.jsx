import { useCallback, useEffect, useRef, useState } from 'react';
import api from '../lib/api';
import { fmt } from '../lib/format';
import { localSales } from '../lib/salesHistory';
import { printDocument } from '../lib/printDocument';
import { salesSummaryHtml } from '../lib/receiptDocument';
import { vendorSettings, cashierName } from '../lib/vendorSettings';
import { PRESETS, dayLabel, deviceCovers, deviceSummary, filterLocal, groupByDay, presetRange, ymd } from '../lib/historyRange';
import SaleHistoryRow from './SaleHistoryRow';

// A cashier's own sales over any window, grouped by day, with what each day
// and the whole window add up to. Opened from My Sales.
//
// Every figure comes from the server (CashierSalesHistory), so the slip a
// cashier prints and the day headings they read are worked out one way. With
// no connection it falls back to the device's own last seven days and says so;
// for anything older it says it needs a connection rather than showing an
// empty list that would read as "you sold nothing".

const METHODS = [
    { key: '',              label: 'All payments' },
    { key: 'cash',          label: 'Cash' },
    { key: 'card',          label: 'POS / Card' },
    { key: 'bank_transfer', label: 'Transfer' },
    { key: 'debt',          label: 'Credit' },
    { key: 'split',         label: 'Mixed' },
];

const STATUSES = [
    { key: '',          label: 'All statuses' },
    { key: 'completed', label: 'Completed' },
    { key: 'voided',    label: 'Voided' },
    { key: 'refunded',  label: 'Refunded' },
];

const labelOf = (list, key) => list.find((o) => o.key === key)?.label ?? '';

export default function SalesHistoryScreen({ vendorId, cashierId, onBack, onClose, onReprint }) {
    const [preset, setPreset]   = useState('today');
    const [custom, setCustom]   = useState({ from: '', to: '' });
    const [method, setMethod]   = useState('');
    const [status, setStatus]   = useState('');
    const [search, setSearch]   = useState('');
    const [q, setQ]             = useState('');

    // online | device | needs_connection | forbidden | error
    const [mode, setMode]       = useState('online');
    const [sales, setSales]     = useState([]);
    const [days, setDays]       = useState({});
    const [summary, setSummary] = useState(null);
    const [period, setPeriod]   = useState(null);
    const [page, setPage]       = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [loadingMore, setLoadingMore] = useState(false);
    const [message, setMessage] = useState('');
    const [expanded, setExpanded] = useState(null);

    // Only the latest request may write to the screen. Changing a filter while
    // the previous one is still in flight must not let the old answer land last.
    const requestId = useRef(0);
    const sentinel = useRef(null);

    // Typing is not a request per keystroke.
    useEffect(() => {
        const timer = setTimeout(() => setQ(search.trim()), 300);

        return () => clearTimeout(timer);
    }, [search]);

    const params = useCallback((pageNo) => ({
        vendor_id: vendorId,
        preset,
        ...(preset === 'custom' ? presetRange('custom', custom) : {}),
        method: method || undefined,
        status: status || undefined,
        q: q || undefined,
        page: pageNo,
    }), [vendorId, preset, custom, method, status, q]);

    const fallBackToDevice = useCallback(async () => {
        const range = presetRange(preset, custom);
        setPeriod(range);
        setSummary(null);
        setDays({});

        if (!deviceCovers(range)) {
            setSales([]);
            setMode('needs_connection');
            return;
        }

        const rows = await localSales(cashierId).catch(() => []);
        const local = rows.map((row) => ({ ...row, id: row.server_id, pending_sync: row.synced !== 1 }));

        setSales(filterLocal(local, { ...range, method: method || null, status: status || null, q }));
        setLastPage(1);
        setMode('device');
    }, [cashierId, preset, custom, method, status, q]);

    useEffect(() => {
        const id = ++requestId.current;

        setLoading(true);
        setExpanded(null);

        api.get('/sales/history', { params: params(1) })
            .then(({ data }) => {
                if (id !== requestId.current) return;

                setSales(data.data ?? []);
                setDays(data.days ?? {});
                setSummary(data.summary);
                setPeriod(data.period);
                setPage(data.current_page);
                setLastPage(data.last_page);
                setMode('online');
            })
            .catch(async (error) => {
                if (id !== requestId.current) return;

                if (!error.response) {
                    await fallBackToDevice();
                } else if (error.response.status === 403) {
                    setMode('forbidden');
                    setMessage(error.response.data?.message ?? 'You do not have permission to view the full sales history.');
                } else {
                    setMode('error');
                    setMessage(error.response.data?.message ?? 'Could not load your sales. Try again.');
                }
            })
            .finally(() => {
                if (id === requestId.current) setLoading(false);
            });
    }, [params, fallBackToDevice]);

    const loadMore = useCallback(async () => {
        if (mode !== 'online' || loading || loadingMore || page >= lastPage) return;

        const id = requestId.current;
        setLoadingMore(true);

        try {
            const { data } = await api.get('/sales/history', { params: params(page + 1) });

            if (id !== requestId.current) return;

            setSales((prev) => [...prev, ...(data.data ?? [])]);
            setDays((prev) => ({ ...prev, ...(data.days ?? {}) }));
            setPage(data.current_page);
            setLastPage(data.last_page);
        } catch {
            // Left for the button below: a failed page is retried by pressing it.
        } finally {
            setLoadingMore(false);
        }
    }, [mode, loading, loadingMore, page, lastPage, params]);

    // Keep loading as the cashier reaches the bottom.
    useEffect(() => {
        const el = sentinel.current;

        if (!el || typeof IntersectionObserver === 'undefined') return undefined;

        const observer = new IntersectionObserver((entries) => {
            if (entries.some((e) => e.isIntersecting)) loadMore();
        }, { rootMargin: '200px' });

        observer.observe(el);

        return () => observer.disconnect();
    }, [loadMore]);

    const print = () => {
        if (!summary || !period) return;

        printDocument(salesSummaryHtml(summary, {
            from: period.from,
            to: period.to,
            filters: {
                Payment: method ? labelOf(METHODS, method) : null,
                Status: status ? labelOf(STATUSES, status) : null,
                Search: q || null,
            },
        }, { ...vendorSettings(), cashier_name: cashierName() }));
    };

    const grouped = groupByDay(sales);
    const today = ymd(new Date());

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-gray-50 dark:bg-gray-950">
            {/* Header */}
            <div className="flex shrink-0 items-center gap-2 border-b border-gray-200 bg-white px-3 py-2.5 dark:border-gray-800 dark:bg-gray-900 sm:px-5">
                <button
                    onClick={onBack}
                    className="flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                >
                    <span aria-hidden="true">←</span> My Sales
                </button>
                <h2 className="flex-1 truncate text-sm font-bold text-gray-800 dark:text-gray-100">Sales history</h2>
                <button
                    onClick={print}
                    disabled={!summary}
                    title={summary ? 'Print this summary on the receipt printer' : 'Printing needs a connection'}
                    className="rounded-lg bg-[#068B03] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#057a02] disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Print summary
                </button>
                <button
                    onClick={onClose}
                    aria-label="Close"
                    className="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800"
                >
                    ✕
                </button>
            </div>

            {/* Filters */}
            <div className="shrink-0 space-y-2 border-b border-gray-200 bg-white px-3 py-2.5 dark:border-gray-800 dark:bg-gray-900 sm:px-5">
                <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-0.5">
                    {PRESETS.map((p) => (
                        <button
                            key={p.key}
                            onClick={() => setPreset(p.key)}
                            className={`shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition-colors ${preset === p.key
                                ? 'bg-[#068B03] text-white'
                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'}`}
                        >
                            {p.label}
                        </button>
                    ))}
                </div>

                {preset === 'custom' && (
                    <div className="flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                        <label className="flex items-center gap-1.5">
                            From
                            <input
                                type="date"
                                value={custom.from}
                                max={today}
                                onChange={(e) => setCustom((c) => ({ ...c, from: e.target.value }))}
                                className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-800"
                            />
                        </label>
                        <label className="flex items-center gap-1.5">
                            To
                            <input
                                type="date"
                                value={custom.to}
                                max={today}
                                onChange={(e) => setCustom((c) => ({ ...c, to: e.target.value }))}
                                className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-800"
                            />
                        </label>
                        <span className="text-gray-400">For one day, pick the same date twice.</span>
                    </div>
                )}

                <div className="flex flex-wrap gap-2">
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search reference or product"
                        className="min-w-0 flex-1 basis-48 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    />
                    <select
                        value={method}
                        onChange={(e) => setMethod(e.target.value)}
                        aria-label="Payment method"
                        className="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    >
                        {METHODS.map((m) => <option key={m.key} value={m.key}>{m.label}</option>)}
                    </select>
                    <select
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                        aria-label="Status"
                        className="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    >
                        {STATUSES.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
                    </select>
                </div>
            </div>

            {/* Body */}
            <div className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl space-y-4 px-3 py-4 sm:px-5">
                    {mode === 'device' && (
                        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">
                            Offline — showing this device's own record. It does not know about voids or refunds, and totals by payment need a connection.
                        </p>
                    )}

                    {(mode === 'forbidden' || mode === 'error') && (
                        <p className="rounded-lg bg-red-50 px-3 py-6 text-center text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">{message}</p>
                    )}

                    {mode === 'needs_connection' && (
                        <p className="rounded-lg bg-amber-50 px-3 py-6 text-center text-sm text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">
                            Needs a connection. This device only keeps the last 7 days — pick a recent range, or try again when you are back online.
                        </p>
                    )}

                    {loading && <p className="py-8 text-center text-sm text-gray-400">Loading…</p>}

                    {!loading && mode === 'online' && summary && <Summary summary={summary} period={period} />}

                    {!loading && mode === 'device' && <DeviceSummary summary={deviceSummary(sales)} period={period} />}

                    {!loading && (mode === 'online' || mode === 'device') && sales.length === 0 && (
                        <p className="py-8 text-center text-sm text-gray-400">No sales match.</p>
                    )}

                    {!loading && grouped.map(({ date, sales: daySales }) => (
                        <section key={date}>
                            <DayHeading date={date} summary={days[date]} fallback={mode === 'device' ? deviceSummary(daySales) : null} />
                            <div className="space-y-2">
                                {daySales.map((sale) => {
                                    const key = sale.id ?? sale.offline_id ?? sale.completed_at;

                                    return (
                                        <SaleHistoryRow
                                            key={key}
                                            sale={sale}
                                            timeOnly
                                            expanded={expanded === key}
                                            onToggle={() => setExpanded(expanded === key ? null : key)}
                                            onReprint={onReprint}
                                            badges={sale.pending_sync && (
                                                <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">Pending upload</span>
                                            )}
                                        />
                                    );
                                })}
                            </div>
                        </section>
                    ))}

                    {mode === 'online' && !loading && page < lastPage && (
                        <div ref={sentinel} className="pb-4 text-center">
                            <button
                                onClick={loadMore}
                                disabled={loadingMore}
                                className="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-100 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                            >
                                {loadingMore ? 'Loading…' : 'Load more'}
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

const rangeText = (period) => {
    if (!period?.from) return '';

    return period.from === period.to ? dayLabel(period.from) : `${dayLabel(period.from)} – ${dayLabel(period.to)}`;
};

function Stat({ label, value, sub = null, tone = 'text-gray-800 dark:text-gray-100' }) {
    return (
        <div className="rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-800/60">
            <p className="text-[11px] font-medium text-gray-500 dark:text-gray-400">{label}</p>
            <p className={`text-sm font-bold tabular-nums ${tone}`}>{value}</p>
            {sub && <p className="text-[11px] text-gray-400">{sub}</p>}
        </div>
    );
}

function Summary({ summary, period }) {
    const refunds = summary.refunds;

    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p className="text-xs text-gray-500 dark:text-gray-400">{rangeText(period)}</p>
            <div className="mt-1 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Collected</p>
                    <p className="text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{fmt(summary.net_collected)}</p>
                    {refunds && refunds.money_out > 0 && (
                        <p className="text-[11px] text-gray-400">after {fmt(refunds.money_out)} refunded</p>
                    )}
                </div>
                {summary.revenue != null && (
                    <div className="text-right">
                        <p className="text-[11px] text-gray-500 dark:text-gray-400">Revenue excl. VAT</p>
                        <p className="text-sm font-semibold tabular-nums text-gray-700 dark:text-gray-200">{fmt(summary.revenue)}</p>
                    </div>
                )}
            </div>

            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <Stat label="Sales" value={summary.sales_count} />
                <Stat label="Cash" value={fmt(summary.tenders.cash)} />
                <Stat label="POS / Card" value={fmt(summary.tenders.card)} />
                <Stat label="Transfer" value={fmt(summary.tenders.bank_transfer)} />
                {summary.credit > 0 && <Stat label="On credit" value={fmt(summary.credit)} sub="not collected" />}
                {refunds && refunds.count > 0 && (
                    <Stat label={`Refunds (${refunds.count})`} value={`-${fmt(refunds.total)}`} tone="text-orange-600" />
                )}
                {summary.voided.count > 0 && (
                    <Stat label={`Voided (${summary.voided.count})`} value={fmt(summary.voided.total)} sub="not counted" tone="text-red-600" />
                )}
            </div>
        </div>
    );
}

function DeviceSummary({ summary, period }) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p className="text-xs text-gray-500 dark:text-gray-400">{rangeText(period)}</p>
            <p className="mt-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Sold (this device)</p>
            <p className="text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{fmt(summary.total)}</p>
            <p className="text-xs text-gray-500">{summary.sales_count} {summary.sales_count === 1 ? 'sale' : 'sales'}</p>
        </div>
    );
}

function DayHeading({ date, summary, fallback }) {
    const count = summary?.sales_count ?? fallback?.sales_count;
    const amount = summary ? summary.net_collected : fallback?.total;

    return (
        <div className="sticky top-0 z-10 -mx-3 mb-2 flex items-baseline justify-between gap-2 bg-gray-50/95 px-3 py-1.5 backdrop-blur dark:bg-gray-950/95 sm:-mx-5 sm:px-5">
            <h3 className="text-xs font-bold text-gray-700 dark:text-gray-200">{dayLabel(date)}</h3>
            {count != null && (
                <p className="text-right text-xs text-gray-500 dark:text-gray-400">
                    {count} {count === 1 ? 'sale' : 'sales'} · <span className="font-semibold text-gray-800 dark:text-gray-100">{fmt(amount)}</span>
                    {summary?.revenue != null && (
                        <span className="ml-1.5 text-[11px] text-gray-400">rev {fmt(summary.revenue)}</span>
                    )}
                </p>
            )}
        </div>
    );
}
