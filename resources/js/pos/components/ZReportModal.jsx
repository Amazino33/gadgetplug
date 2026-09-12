import { useEffect, useState } from 'react';
import { printDocument } from '../lib/printDocument';
import { zReportHtml } from '../lib/receiptDocument';
import { vendorSettings, cashierName } from '../lib/vendorSettings';
import { fmt } from '../lib/format';
import api from '../lib/api';

/**
 * The signed slip for a day that has been cashed up.
 *
 * It no longer generates anything. Closing the day is what produces the report,
 * because the report is the reconciliation — counting the drawer here as well
 * would have been the same question asked twice, and two answers to "how much
 * was in the till" is worse than one.
 *
 * So this fetches the slip the close already wrote and puts it on paper. Before
 * the day is counted there is nothing to show, and it says so rather than
 * offering a button that would produce a report of half the money.
 */
export default function ZReportModal({ session, onClose }) {
    const [report, setReport] = useState(null);
    const [state, setState] = useState('loading');

    useEffect(() => {
        if (!session?.id) {
            setState('none');

            return;
        }

        let cancelled = false;

        api.get(`/sessions/${session.id}/z-report`)
            .then(({ data }) => {
                if (cancelled) return;
                setReport(data);
                setState('ready');
            })
            .catch((e) => {
                if (cancelled) return;
                // 404 is the ordinary case, not a failure: the day is still
                // being traded and has not been counted yet.
                setState(e?.response?.status === 404 ? 'not-yet' : 'error');
            });

        return () => { cancelled = true; };
    }, [session?.id]);

    const printReport = () => {
        if (!report) return;

        printDocument(zReportHtml(report, session, { ...vendorSettings(), cashier_name: cashierName() }));
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="mx-4 max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div className="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-white px-6 py-4">
                    <h2 className="font-bold text-gray-800">Z-Report</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600" aria-label="Close">✕</button>
                </div>

                <div className="p-6">
                    {state === 'loading' && (
                        <p className="py-8 text-center text-sm text-gray-400">Fetching the slip…</p>
                    )}

                    {(state === 'none' || state === 'not-yet') && (
                        <div className="py-6 text-center">
                            <p className="text-sm text-gray-500">
                                Nothing to print yet. The Z-report is produced when you cash up.
                            </p>
                            <button onClick={onClose} className="mt-5 w-full rounded-xl bg-gray-900 py-2.5 text-sm font-semibold text-white">
                                Close
                            </button>
                        </div>
                    )}

                    {state === 'error' && (
                        <div className="py-6 text-center">
                            <p className="text-sm text-gray-500">
                                Could not reach the server for the slip. Try again when you have a signal.
                            </p>
                            <button onClick={onClose} className="mt-5 w-full rounded-xl bg-gray-900 py-2.5 text-sm font-semibold text-white">
                                Close
                            </button>
                        </div>
                    )}

                    {state === 'ready' && report && (
                        <>
                            <p className="mb-4 text-center text-xs text-gray-400">
                                {new Date(report.generated_at).toLocaleString('en-NG')}
                            </p>

                            <div className="space-y-3">
                                {[
                                    { label: 'Cash Sales', value: report.cash_sales, color: '#068B03' },
                                    { label: 'POS / Card Sales', value: report.card_sales, color: '#3B82F6' },
                                    { label: 'Bank Transfer Sales', value: report.bank_transfer_sales, color: '#8B5CF6' },
                                ].map((r) => (
                                    <div key={r.label} className="flex items-center justify-between border-b border-gray-50 py-2">
                                        <span className="text-sm text-gray-600">{r.label}</span>
                                        <span className="text-sm font-bold" style={{ color: r.color }}>{fmt(r.value)}</span>
                                    </div>
                                ))}

                                <div className="flex items-center justify-between border-b-2 border-gray-200 py-2">
                                    <span className="text-sm font-bold text-gray-800">Gross Sales</span>
                                    <span className="text-lg font-extrabold text-gray-900">{fmt(report.total_sales)}</span>
                                </div>

                                <Leg
                                    title="Cash drawer"
                                    expected={report.cash_expected}
                                    counted={report.cash_counted}
                                    variance={report.cash_variance}
                                />

                                {/* The half the old slip stayed silent about. */}
                                <Leg
                                    title="Moniepoint terminal"
                                    expected={report.terminal_expected}
                                    counted={report.terminal_counted}
                                    variance={report.terminal_variance}
                                />

                                {report.notes && (
                                    <div className="rounded-xl bg-gray-50 px-4 py-3">
                                        <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Cashier note</p>
                                        <p className="mt-1 text-xs text-gray-600">{report.notes}</p>
                                    </div>
                                )}
                            </div>

                            <button
                                onClick={printReport}
                                className="mt-6 w-full rounded-xl border-2 border-gray-200 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                            >
                                Print Report
                            </button>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

function Leg({ title, expected, counted, variance }) {
    if (counted == null) return null;

    const value = Number(variance ?? 0);
    const balanced = Math.abs(value) < 0.01;

    return (
        <div className="border-t border-gray-200 pt-3">
            <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400">{title}</p>
            <div className="flex items-center justify-between py-1">
                <span className="text-xs text-gray-400">Expected</span>
                <span className="text-xs font-semibold text-gray-600">{fmt(expected)}</span>
            </div>
            <div className="flex items-center justify-between py-1">
                <span className="text-xs text-gray-400">Counted</span>
                <span className="text-xs font-semibold text-gray-600">{fmt(counted)}</span>
            </div>
            <div className="flex items-center justify-between py-1">
                <span className="text-xs font-bold text-gray-700">Variance</span>
                <span className={`text-sm font-extrabold ${balanced ? 'text-[#068B03]' : 'text-red-500'}`}>
                    {value >= 0 ? '+' : ''}{fmt(value)}
                </span>
            </div>
        </div>
    );
}
