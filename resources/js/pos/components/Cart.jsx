import { useRef } from 'react';
import { fmt } from '../lib/format';

function MobileCartRow({ item, idx, isSelected, onSelect, onRemove }) {
    const rowRef   = useRef(null);
    const startX   = useRef(0);
    const snapTx   = useRef(0);
    const active   = useRef(false);
    const moved    = useRef(false);

    const OPEN  = -88;
    const CLOSE = 0;
    const GATE  = -44;

    const lineTotal = item.price * item.qty - (item.lineDiscount || 0);

    const onPointerDown = (e) => {
        active.current = true;
        moved.current  = false;
        startX.current = e.clientX;
        rowRef.current.style.transition = 'none';
        rowRef.current.setPointerCapture(e.pointerId);
    };

    const onPointerMove = (e) => {
        if (!active.current) return;
        const dx = e.clientX - startX.current;
        if (Math.abs(dx) > 7) moved.current = true;
        const tx = Math.min(0, Math.max(OPEN, snapTx.current + dx));
        rowRef.current.style.transform = `translateX(${tx}px)`;
    };

    const onPointerUp = () => {
        if (!active.current) return;
        active.current = false;
        rowRef.current.style.transition = 'transform 0.18s ease';

        if (!moved.current) {
            rowRef.current.style.transform = `translateX(${CLOSE}px)`;
            snapTx.current = CLOSE;
            onSelect(idx);
            return;
        }

        const raw  = rowRef.current.style.transform.match(/-?[\d.]+/);
        const tx   = raw ? parseFloat(raw[0]) : 0;
        const snap = tx < GATE ? OPEN : CLOSE;
        snapTx.current = snap;
        rowRef.current.style.transform = `translateX(${snap}px)`;
    };

    return (
        <div className="relative overflow-hidden mx-3 mb-2">
            {/* Delete button revealed by left swipe */}
            <button
                onClick={() => onRemove(idx)}
                className="absolute top-0 bottom-0 right-0 flex flex-col items-center justify-center gap-1 bg-red-500 text-white text-xs font-semibold rounded-r-2xl"
                style={{ width: 88 }}
            >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Delete
            </button>

            {/* Swipeable card */}
            <div
                ref={rowRef}
                className={`flex items-center gap-3 px-4 py-3 rounded-2xl border border-gray-100 dark:border-gray-800 ${
                    isSelected ? 'bg-green-50 dark:bg-green-950' : 'bg-white dark:bg-gray-900'
                }`}
                style={{
                    transform:   'translateX(0)',
                    touchAction: 'pan-y',
                    userSelect:  'none',
                }}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
            >
                {item.image
                    ? <img src={item.image} className="w-11 h-11 rounded-xl object-cover shrink-0" alt="" />
                    : <div className="w-11 h-11 rounded-xl bg-gray-100 shrink-0" />
                }
                <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">{item.name}</p>
                    <p className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                        {item.sku && `${item.sku} · `}{fmt(item.price)}
                    </p>
                    {item.lineDiscount > 0 && (
                        <span className="inline-block mt-1 text-xs bg-orange-50 text-orange-600 font-semibold px-2 py-0.5 rounded-full">
                            −{fmt(item.lineDiscount)}
                        </span>
                    )}
                </div>
                <div className="text-right shrink-0">
                    <p className="text-xs text-gray-400 dark:text-gray-500">×{item.qty}</p>
                    <p className="text-sm font-bold text-gray-800 dark:text-gray-100">{fmt(lineTotal)}</p>
                </div>
            </div>
        </div>
    );
}

export default function Cart({ items, selectedIdx, onSelect, onQtyChange, onRemove }) {
    if (items.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center h-full text-gray-300 dark:text-gray-700">
                <svg className="w-16 h-16 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1}
                        d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0a1.5 1.5 0 103 0m6 0a1.5 1.5 0 103 0" />
                </svg>
                <p className="text-sm">Cart is empty</p>
                <p className="text-xs mt-1 hidden md:block">Scan a barcode or press F3 to search</p>
                <p className="text-xs mt-1 md:hidden">Search above to add products</p>
            </div>
        );
    }

    return (
        <>
            {/* ── Mobile: swipeable card rows ─────────────────── */}
            <div className="md:hidden py-2">
                {items.map((item, idx) => (
                    <MobileCartRow
                        key={item.id}
                        item={item}
                        idx={idx}
                        isSelected={idx === selectedIdx}
                        onSelect={onSelect}
                        onRemove={onRemove}
                    />
                ))}
            </div>

            {/* ── Desktop: table layout ───────────────────────── */}
            <table className="hidden md:table w-full">
                <thead className="sticky top-0 bg-gray-50 dark:bg-gray-950 z-10">
                    <tr className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">
                        <th className="px-6 py-3 text-left font-medium">Product</th>
                        <th className="px-4 py-3 text-center font-medium w-32">Qty</th>
                        <th className="px-4 py-3 text-right font-medium w-36">Unit Price</th>
                        <th className="px-4 py-3 text-right font-medium w-36">Total</th>
                        <th className="px-4 py-3 w-10"></th>
                    </tr>
                </thead>
                <tbody>
                    {items.map((item, idx) => {
                        const lineTotal  = item.price * item.qty - (item.lineDiscount || 0);
                        const isSelected = idx === selectedIdx;

                        return (
                            <tr
                                key={item.id}
                                onClick={() => onSelect(idx)}
                                className={`border-b border-gray-50 dark:border-gray-800 cursor-pointer transition-colors ${
                                    isSelected ? 'bg-green-50 dark:bg-green-950' : 'hover:bg-gray-50 dark:hover:bg-gray-800'
                                }`}
                            >
                                <td className="px-6 py-4">
                                    <div className="flex items-center gap-3">
                                        {item.image
                                            ? <img src={item.image} className="w-10 h-10 rounded-lg object-cover shrink-0" alt="" />
                                            : <div className="w-10 h-10 rounded-lg bg-gray-100 shrink-0" />
                                        }
                                        <div>
                                            <p className="text-sm font-medium text-gray-800 dark:text-gray-100">{item.name}</p>
                                            {item.sku && <p className="text-xs text-gray-400 dark:text-gray-500">{item.sku}</p>}
                                            {item.lineDiscount > 0 && (
                                                <p className="text-xs text-brand-orange">Discount: −{fmt(item.lineDiscount)}</p>
                                            )}
                                        </div>
                                    </div>
                                </td>
                                <td className="px-4 py-4 text-center">
                                    <div className="flex items-center justify-center gap-2">
                                        <button
                                            onClick={(e) => { e.stopPropagation(); onQtyChange(idx, item.qty - 1); }}
                                            className="w-7 h-7 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 font-bold text-sm flex items-center justify-center"
                                        >−</button>
                                        <span className="w-8 text-center text-sm font-semibold dark:text-gray-200">{item.qty}</span>
                                        <button
                                            onClick={(e) => { e.stopPropagation(); onQtyChange(idx, item.qty + 1); }}
                                            className="w-7 h-7 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 font-bold text-sm flex items-center justify-center"
                                        >+</button>
                                    </div>
                                </td>
                                <td className="px-4 py-4 text-right text-sm text-gray-600 dark:text-gray-400">{fmt(item.price)}</td>
                                <td className="px-4 py-4 text-right text-sm font-semibold text-gray-800 dark:text-gray-100">{fmt(lineTotal)}</td>
                                <td className="px-4 py-4 text-center">
                                    <button
                                        onClick={(e) => { e.stopPropagation(); onRemove(idx); }}
                                        className="text-gray-300 dark:text-gray-600 hover:text-red-400 dark:hover:text-red-400 transition-colors"
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
        </>
    );
}
