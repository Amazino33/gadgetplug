import { useState, useEffect } from 'react';
import api from '../lib/api';

export default function ProcurementsModal({ isOpen, onClose, vendorId }) {
    const [procurements, setProcurements] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!isOpen) return;
        
        let isMounted = true;
        setLoading(true);
        setError(null);

        api.get('/procurements', { params: { vendor_id: vendorId } })
            .then(({ data }) => {
                if (isMounted) {
                    setProcurements(data.procurements);
                    setLoading(false);
                }
            })
            .catch(err => {
                if (isMounted) {
                    setError(err.response?.data?.message || 'Failed to load procurements');
                    setLoading(false);
                }
            });

        return () => { isMounted = false; };
    }, [isOpen, vendorId]);

    const handleApprove = async (id) => {
        if (!confirm('Are you sure you want to approve and receive this stock into your branch?')) return;
        
        setSubmitting(true);
        try {
            await api.post(`/procurements/${id}/approve`, { vendor_id: vendorId });
            setProcurements(prev => prev.filter(p => p.id !== id));
            alert('Procurement approved successfully. Stock has been updated.');
        } catch (err) {
            alert(err.response?.data?.message || 'Failed to approve procurement');
        } finally {
            setSubmitting(false);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6">
            <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" onClick={onClose}></div>
            
            <div className="relative w-full max-w-2xl bg-white dark:bg-gray-900 rounded-2xl shadow-2xl flex flex-col max-h-full overflow-hidden border border-gray-100 dark:border-gray-800">
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                    <h2 className="text-lg font-bold text-gray-900 dark:text-gray-100">Receive Stock</h2>
                    <button onClick={onClose} className="p-2 -mr-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div className="p-6 overflow-y-auto flex-1">
                    {loading ? (
                        <div className="py-12 text-center text-gray-500">Loading procurements...</div>
                    ) : error ? (
                        <div className="p-4 bg-red-50 text-red-600 rounded-xl text-sm">{error}</div>
                    ) : procurements.length === 0 ? (
                        <div className="py-12 flex flex-col items-center justify-center text-center">
                            <div className="w-16 h-16 bg-gray-50 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4">
                                <svg className="w-8 h-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </div>
                            <h3 className="text-gray-900 dark:text-gray-100 font-semibold mb-1">No incoming stock</h3>
                            <p className="text-sm text-gray-500">There are no pending procurements for this branch.</p>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {procurements.map(proc => (
                                <div key={proc.id} className="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">
                                    <div className="bg-gray-50 dark:bg-gray-800 px-4 py-3 flex items-center justify-between">
                                        <div>
                                            <div className="font-bold text-gray-900 dark:text-white flex items-center gap-2">
                                                {proc.reference}
                                                <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700 uppercase tracking-wide">Pending</span>
                                            </div>
                                            <div className="text-xs text-gray-500 mt-1">
                                                Created by {proc.creator_name} • {new Date(proc.created_at).toLocaleDateString()}
                                            </div>
                                        </div>
                                        <button 
                                            onClick={() => handleApprove(proc.id)}
                                            disabled={submitting}
                                            className="px-4 py-2 bg-[#068B03] hover:bg-[#057002] text-white text-sm font-bold rounded-lg shadow-sm transition-colors disabled:opacity-50"
                                        >
                                            Receive Stock
                                        </button>
                                    </div>
                                    <div className="p-4 bg-white dark:bg-gray-900">
                                        <div className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Items ({proc.items_count})</div>
                                        <div className="space-y-2">
                                            {proc.items.map((item, idx) => (
                                                <div key={idx} className="flex items-center justify-between text-sm">
                                                    <div className="text-gray-700 dark:text-gray-300">
                                                        <span className="font-medium">{item.quantity}x</span> {item.product_name}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
