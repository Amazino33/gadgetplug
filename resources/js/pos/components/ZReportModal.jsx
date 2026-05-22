import { useState } from 'react';
import { fmt } from '../lib/format';
import api from '../lib/api';

export default function ZReportModal({ session, onClose, onCloseSession }) {
    const [cashCounted, setCashCounted] = useState('');
    const [report, setReport]           = useState(null);
    const [loading, setLoading]         = useState(false);

    const generate = async () => {
        if (!session) return;
        setLoading(true);
        try {
            const { data } = await api.post(`/sessions/${session.id}/close`, {
                cash_counted: cashCounted ? parseFloat(cashCounted) : null,
            });
            setReport(data);
        } catch { /* handle */ } finally {
            setLoading(false);
        }
    };

    const printReport = () => window.print();

    if (!session) {
        return (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
                <div className="bg-white rounded-2xl shadow-2xl w-80 mx-4 p-6 text-center">
                    <p className="text-gray-500 text-sm mb-4">No active session found.</p>
                    <button onClick={onClose} className="w-full py-2.5 bg-gray-900 text-white rounded-xl text-sm font-semibold">Close</button>
                </div>
            </div>
        );
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 sticky top-0 bg-white z-10">
                    <h2 className="font-bold text-gray-800">Z-Report — Close Session</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                <div className="p-6">
                    {!report ? (
                        <>
                            <div className="bg-gray-50 rounded-xl px-4 py-3 mb-4">
                                <p className="text-xs text-gray-400">Session opened at</p>
                                <p className="text-sm font-semibold text-gray-700">{session.opened_at}</p>
                                <p className="text-xs text-gray-400 mt-1">Opening float</p>
                                <p className="text-sm font-semibold text-gray-700">{fmt(session.opening_float)}</p>
                            </div>
                            <div className="mb-4">
                                <label className="text-xs font-semibold text-gray-500 mb-1 block">
                                    Cash in Drawer (counted physically)
                                </label>
                                <input
                                    type="number"
                                    value={cashCounted}
                                    onChange={(e) => setCashCounted(e.target.value)}
                                    placeholder="₦0.00"
                                    className="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-2xl font-bold text-gray-800 text-center focus:outline-none focus:border-[#068B03]"
                                />
                            </div>
                            <div className="flex gap-3">
                                <button onClick={onClose}
                                    className="flex-1 py-3 rounded-xl border border-gray-200 text-sm font-semibold text-gray-500">
                                    Cancel
                                </button>
                                <button onClick={generate} disabled={loading}
                                    className="flex-1 py-3 rounded-xl bg-[#068B03] text-white text-sm font-bold disabled:opacity-40">
                                    {loading ? 'Generating…' : 'Generate Z-Report'}
                                </button>
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="space-y-3 mb-6 print:text-sm">
                                <p className="text-center text-xs text-gray-400 mb-4">
                                    {new Date(report.generated_at).toLocaleString('en-NG')}
                                </p>

                                {[
                                    { label: 'Cash Sales',          value: report.cash_sales,          color: '#068B03' },
                                    { label: 'POS / Card Sales',    value: report.card_sales,          color: '#3B82F6' },
                                    { label: 'Bank Transfer Sales', value: report.bank_transfer_sales, color: '#8B5CF6' },
                                ].map((row) => (
                                    <div key={row.label} className="flex justify-between items-center py-2 border-b border-gray-50">
                                        <span className="text-sm text-gray-600">{row.label}</span>
                                        <span className="text-sm font-bold" style={{ color: row.color }}>{fmt(row.value)}</span>
                                    </div>
                                ))}

                                <div className="flex justify-between items-center py-2 border-b-2 border-gray-200">
                                    <span className="text-sm font-bold text-gray-800">Gross Sales</span>
                                    <span className="text-lg font-extrabold text-gray-900">{fmt(report.total_sales)}</span>
                                </div>

                                {[
                                    { label: 'VAT Collected',   value: report.total_vat,       negative: false },
                                    { label: 'Discounts Given', value: report.total_discounts,  negative: true  },
                                    { label: 'Returns',         value: report.total_returns,    negative: true  },
                                ].map((row) => (
                                    <div key={row.label} className="flex justify-between items-center py-1">
                                        <span className="text-xs text-gray-400">{row.label}</span>
                                        <span className={`text-xs font-semibold ${row.negative ? 'text-red-500' : 'text-gray-600'}`}>
                                            {row.negative ? '−' : ''}{fmt(row.value)}
                                        </span>
                                    </div>
                                ))}

                                <div className="border-t border-gray-200 pt-3">
                                    <div className="flex justify-between items-center py-1">
                                        <span className="text-xs text-gray-400">Transactions</span>
                                        <span className="text-xs font-semibold text-gray-600">{report.transaction_count}</span>
                                    </div>
                                    <div className="flex justify-between items-center py-1">
                                        <span className="text-xs text-gray-400">Cash Expected in Drawer</span>
                                        <span className="text-xs font-semibold text-gray-600">{fmt(report.cash_expected)}</span>
                                    </div>
                                    {report.cash_counted !== null && (
                                        <>
                                            <div className="flex justify-between items-center py-1">
                                                <span className="text-xs text-gray-400">Cash Counted</span>
                                                <span className="text-xs font-semibold text-gray-600">{fmt(report.cash_counted)}</span>
                                            </div>
                                            <div className="flex justify-between items-center py-1">
                                                <span className="text-xs font-bold text-gray-700">Cash Variance</span>
                                                <span className={`text-sm font-extrabold ${Math.abs(report.cash_variance) < 0.01 ? 'text-[#068B03]' : 'text-red-500'}`}>
                                                    {report.cash_variance >= 0 ? '+' : ''}{fmt(report.cash_variance)}
                                                </span>
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>

                            <div className="flex gap-3">
                                <button onClick={printReport}
                                    className="flex-1 py-3 rounded-xl border-2 border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                    Print Report
                                </button>
                                <button onClick={onCloseSession}
                                    className="flex-1 py-3 rounded-xl bg-gray-900 text-white text-sm font-bold">
                                    Close Session
                                </button>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
