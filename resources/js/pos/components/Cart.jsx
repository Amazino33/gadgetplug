import { fmt } from '../lib/format';

export default function Cart({ items, selectedIdx, onSelect, onQtyChange, onRemove }) {
    if (items.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center h-full text-gray-300">
                <svg className="w-16 h-16 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1}
                        d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0a1.5 1.5 0 103 0m6 0a1.5 1.5 0 103 0" />
                </svg>
                <p className="text-sm">Cart is empty</p>
                <p className="text-xs mt-1">Scan a barcode or press F3 to search</p>
            </div>
        );
    }

    return (
        <table className="w-full">
            <thead className="sticky top-0 bg-gray-50 z-10">
                <tr className="text-xs text-gray-400 uppercase tracking-wide">
                    <th className="px-6 py-3 text-left font-medium">Product</th>
                    <th className="px-4 py-3 text-center font-medium w-32">Qty</th>
                    <th className="px-4 py-3 text-right font-medium w-36">Unit Price</th>
                    <th className="px-4 py-3 text-right font-medium w-36">Total</th>
                    <th className="px-4 py-3 w-10"></th>
                </tr>
            </thead>
            <tbody>
                {items.map((item, idx) => {
                    const lineTotal = item.price * item.qty - (item.lineDiscount || 0);
                    const isSelected = idx === selectedIdx;

                    return (
                        <tr
                            key={item.id}
                            onClick={() => onSelect(idx)}
                            className={`border-b border-gray-50 cursor-pointer transition-colors ${
                                isSelected ? 'bg-green-50' : 'hover:bg-gray-50'
                            }`}
                        >
                            <td className="px-6 py-4">
                                <div className="flex items-center gap-3">
                                    {item.image
                                        ? <img src={item.image} className="w-10 h-10 rounded-lg object-cover shrink-0" />
                                        : <div className="w-10 h-10 rounded-lg bg-gray-100 shrink-0" />
                                    }
                                    <div>
                                        <p className="text-sm font-medium text-gray-800">{item.name}</p>
                                        {item.sku && <p className="text-xs text-gray-400">{item.sku}</p>}
                                        {item.lineDiscount > 0 && (
                                            <p className="text-xs text-[#F97316]">Discount: −{fmt(item.lineDiscount)}</p>
                                        )}
                                    </div>
                                </div>
                            </td>
                            <td className="px-4 py-4 text-center">
                                <div className="flex items-center justify-center gap-2">
                                    <button
                                        onClick={(e) => { e.stopPropagation(); onQtyChange(idx, item.qty - 1); }}
                                        className="w-7 h-7 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold text-sm flex items-center justify-center"
                                    >−</button>
                                    <span className="w-8 text-center text-sm font-semibold">{item.qty}</span>
                                    <button
                                        onClick={(e) => { e.stopPropagation(); onQtyChange(idx, item.qty + 1); }}
                                        className="w-7 h-7 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold text-sm flex items-center justify-center"
                                    >+</button>
                                </div>
                            </td>
                            <td className="px-4 py-4 text-right text-sm text-gray-600">
                                {fmt(item.price)}
                            </td>
                            <td className="px-4 py-4 text-right text-sm font-semibold text-gray-800">
                                {fmt(lineTotal)}
                            </td>
                            <td className="px-4 py-4 text-center">
                                <button
                                    onClick={(e) => { e.stopPropagation(); onRemove(idx); }}
                                    className="text-gray-300 hover:text-red-400 transition-colors"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}
