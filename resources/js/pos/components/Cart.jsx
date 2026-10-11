import { useEffect, useRef, useState } from 'react';
import { fmt } from '../lib/format';
import { roomFor } from '../lib/cartAdd';
import { shortUnitName, shortfallFor, stockText, unitsOf } from '../lib/units';

// Clicks inside a row's controls must not also select the row (desktop) or
// start a swipe (mobile).
const stop = (e) => e.stopPropagation();

// ── Pieces shared by every row ────────────────────────────────────────────────

/** A soft background picked from the name, so the same product always looks the same. */
const THUMB_COLOURS = [
    'bg-green-100 text-green-800', 'bg-blue-100 text-blue-800', 'bg-amber-100 text-amber-800',
    'bg-rose-100 text-rose-800', 'bg-violet-100 text-violet-800', 'bg-teal-100 text-teal-800',
];

function initials(name = '') {
    const words = String(name).trim().split(/\s+/).filter(Boolean);

    return (words.length > 1 ? words[0][0] + words[1][0] : (words[0] ?? '?').slice(0, 2)).toUpperCase();
}

function Thumb({ item, size = 'w-9 h-9' }) {
    if (item.image) {
        return <img src={item.image} className={`${size} rounded-lg object-cover shrink-0`} alt="" />;
    }

    const hash = [...String(item.name ?? '')].reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7);

    return (
        <div className={`${size} rounded-lg shrink-0 flex items-center justify-center text-[11px] font-bold ${THUMB_COLOURS[hash % THUMB_COLOURS.length]}`}>
            {initials(item.name)}
        </div>
    );
}

/**
 * Only when it matters: below the product's low-stock level, or more in this
 * sale (in base pieces) than the shelf holds. The full stock is in the name's
 * tooltip instead of taking a line of every row.
 */
function StockBadge({ items, item }) {
    const exceeds = shortfallFor(items, item) > 0;

    if (exceeds) {
        return <span className="shrink-0 rounded px-1.5 py-px text-[10px] font-bold bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300">Exceeds stock</span>;
    }

    if (item.is_low_stock) {
        return <span className="shrink-0 rounded px-1.5 py-px text-[10px] font-bold bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300">Low</span>;
    }

    return null;
}

function nameTooltip(item) {
    const stock = stockText(item);

    return stock ? `${item.name}\nIn stock: ${stock}` : item.name;
}

/**
 * One pill split into segments, exactly one active, smallest unit first. A
 * product sold in one unit shows that unit as plain text, so the column still
 * lines up.
 */
function UnitSegments({ item, idx, onUnitChange }) {
    const units = unitsOf(item);

    if (units.length < 2) {
        return (
            <span className="text-xs text-gray-400 dark:text-gray-500 truncate" title={item.unit_name ?? item.base_unit ?? ''}>
                {item.unit_name ?? item.base_unit ?? ''}
            </span>
        );
    }

    // Abbreviated only when the full names would not fit the column.
    const crowded = units.reduce((n, u) => n + String(u.name).length, 0) > 14;

    return (
        <div role="group" aria-label="Selling unit" className="inline-flex max-w-full rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden" onClick={stop} onPointerDown={stop}>
            {units.map((u) => {
                const active = Number(u.id) === Number(item.unit_id);

                return (
                    <button
                        key={u.id}
                        type="button"
                        title={u.name}
                        aria-pressed={active}
                        onClick={() => onUnitChange?.(idx, u.id)}
                        className={`min-h-9 px-2 text-xs font-semibold whitespace-nowrap border-r last:border-r-0 border-gray-200 dark:border-gray-700 transition-colors ${
                            active
                                ? 'bg-[#068B03] text-white'
                                : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'
                        }`}
                    >
                        {crowded ? shortUnitName(u.name) : u.name}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * − [qty] +. Tapping the number makes it a field with the value selected:
 * Enter or leaving it commits, Esc cancels, and anything that is not a whole
 * number of 1 or more is put back as it was. Quantities are whole units — the
 * database counts stock in whole base pieces.
 */
function QtyStepper({ items, item, idx, onQtyChange, onQtyEditDone }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState('');
    const inputRef = useRef(null);
    // Enter and Esc have already decided; the blur that follows the field
    // disappearing must not decide again (Esc would otherwise commit).
    const settledRef = useRef(false);
    const room = roomFor(items, item, idx);
    const plusDisabled = item.qty + 1 > room;

    useEffect(() => {
        if (editing) inputRef.current?.select();
    }, [editing]);

    const commit = (focusSearchAfter) => {
        const n = Number(draft);

        if (Number.isInteger(n) && n >= 1 && n !== item.qty) onQtyChange(idx, n);

        setEditing(false);
        if (focusSearchAfter) onQtyEditDone?.();
    };

    const btn = 'w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 font-bold text-sm flex items-center justify-center disabled:opacity-30 disabled:cursor-not-allowed';

    return (
        <div className="flex items-center gap-1.5" onClick={stop} onPointerDown={stop}>
            <button type="button" aria-label="One less" onClick={() => onQtyChange(idx, item.qty - 1)} className={btn}>−</button>

            {editing ? (
                <input
                    ref={inputRef}
                    type="text"
                    inputMode="numeric"
                    aria-label="Quantity"
                    value={draft}
                    onChange={(e) => setDraft(e.target.value.replace(/[^\d]/g, ''))}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') { e.preventDefault(); settledRef.current = true; commit(true); }
                        if (e.key === 'Escape') { e.preventDefault(); settledRef.current = true; setEditing(false); onQtyEditDone?.(); }
                    }}
                    onBlur={() => {
                        if (settledRef.current) { settledRef.current = false; return; }
                        commit(false);
                    }}
                    className="w-11 h-9 rounded-md border-2 border-[#068B03] bg-white dark:bg-gray-900 text-center text-sm font-semibold font-mono tabular-nums outline-none dark:text-gray-100"
                />
            ) : (
                <button
                    type="button"
                    title="Tap to type a quantity"
                    onClick={() => { settledRef.current = false; setDraft(String(item.qty)); setEditing(true); }}
                    className="w-11 h-9 rounded-md text-center text-sm font-semibold font-mono tabular-nums hover:bg-gray-100 dark:hover:bg-gray-800 dark:text-gray-200"
                >
                    {item.qty}
                </button>
            )}

            <button
                type="button"
                aria-label="One more"
                onClick={() => onQtyChange(idx, item.qty + 1)}
                // Stops at what the shelf holds, as the quantity box does.
                disabled={plusDisabled}
                title={plusDisabled ? 'No more on the shelf at this branch' : undefined}
                className={btn}
            >+</button>
        </div>
    );
}

/** Retail or wholesale for this one line, under its price — only where a wholesale price exists. */
function PriceType({ item, idx, onPriceTypeChange }) {
    if (! unitsOf(item).some((u) => u.wholesale_price != null)) return null;

    const next = item.price_type === 'wholesale' ? 'retail' : 'wholesale';

    return (
        <button
            type="button"
            onClick={(e) => { stop(e); onPriceTypeChange?.(idx, next); }}
            title={item.wholesaleMissing ? `No wholesale price for ${item.unit_name} — sold at retail` : `Switch this line to ${next}`}
            className={`text-[10px] font-semibold uppercase tracking-wide ${item.price_type === 'wholesale' ? 'text-violet-600 dark:text-violet-400' : 'text-gray-400 dark:text-gray-500'} hover:underline`}
        >
            {item.wholesaleMissing ? 'retail*' : item.price_type ?? 'retail'}
        </button>
    );
}

function UnitPrice({ item, idx, allowPriceChange, onPriceEdit, onPriceTypeChange }) {
    const editable = allowPriceChange && item.can_negotiate;

    return (
        <div className="flex flex-col items-end leading-tight" onClick={stop}>
            {editable ? (
                <button
                    type="button"
                    onClick={() => onPriceEdit(idx)}
                    title="Click to change this price"
                    className="text-sm text-gray-700 dark:text-gray-300 underline decoration-dotted underline-offset-4 hover:text-[#068B03] dark:hover:text-green-400 font-mono tabular-nums"
                >
                    {fmt(item.price)}
                </button>
            ) : (
                <span className="text-sm text-gray-700 dark:text-gray-300 font-mono tabular-nums">{fmt(item.price)}</span>
            )}
            {item.listPrice > item.price ? (
                <span className="text-[10px] text-gray-400 dark:text-gray-500 line-through font-mono tabular-nums">{fmt(item.listPrice)}</span>
            ) : (
                <PriceType item={item} idx={idx} onPriceTypeChange={onPriceTypeChange} />
            )}
        </div>
    );
}

function RemoveButton({ idx, onRemove }) {
    return (
        <button
            type="button"
            aria-label="Remove item"
            onClick={(e) => { stop(e); onRemove(idx); }}
            className="w-8 h-9 flex items-center justify-center text-gray-300 dark:text-gray-600 hover:text-red-400 dark:hover:text-red-400 transition-colors"
        >
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    );
}

// ── Desktop / tablet row ──────────────────────────────────────────────────────

/**
 * One line per item on a wide screen, every control in a fixed-width column so
 * they line up down the cart; two lines below 1100px (see .cart-row in pos.css).
 */
function CartRow({ items, item, idx, isSelected, allowPriceChange, handlers }) {
    const { onSelect, onQtyChange, onRemove, onPriceEdit, onUnitChange, onPriceTypeChange, onSetDefaultUnit, onQtyEditDone } = handlers;
    const lineTotal = item.price * item.qty - (item.lineDiscount || 0);
    const canMakeDefault = unitsOf(item).length > 1 && item.unit_id != null && Number(item.unit_id) !== Number(item.default_unit_id);

    return (
        <div
            onClick={() => onSelect(idx)}
            className={`cart-row border-b border-gray-50 dark:border-gray-800 cursor-pointer transition-colors ${
                isSelected ? 'bg-green-50 dark:bg-green-950' : 'hover:bg-gray-50 dark:hover:bg-gray-800'
            }`}
        >
            <div className="cart-thumb"><Thumb item={item} /></div>

            {/* Two short lines: the name, and the code beneath it. */}
            <div className="cart-name min-w-0">
                <p className="text-sm font-medium text-gray-800 dark:text-gray-100 truncate" title={nameTooltip(item)}>{item.name}</p>
                <div className="flex items-center gap-1.5 min-w-0 mt-0.5">
                    {item.sku && <span className="text-[11px] text-gray-400 dark:text-gray-500 truncate">{item.sku}</span>}
                    <StockBadge items={items} item={item} />
                    {item.lineDiscount > 0 && (
                        <span className="shrink-0 text-[10px] text-brand-orange font-mono tabular-nums">−{fmt(item.lineDiscount)}</span>
                    )}
                    {isSelected && canMakeDefault && (
                        <button
                            type="button"
                            onClick={(e) => { stop(e); onSetDefaultUnit?.(idx, item.unit_id); }}
                            title="Add this product as this unit from now on"
                            className="shrink-0 text-[10px] text-[#068B03] dark:text-green-400 underline decoration-dotted underline-offset-2"
                        >
                            Make {item.unit_name} default
                        </button>
                    )}
                </div>
            </div>

            <div className="cart-qty"><QtyStepper items={items} item={item} idx={idx} onQtyChange={onQtyChange} onQtyEditDone={onQtyEditDone} /></div>
            <div className="cart-unit min-w-0"><UnitSegments item={item} idx={idx} onUnitChange={onUnitChange} /></div>
            <div className="cart-price">
                <UnitPrice item={item} idx={idx} allowPriceChange={allowPriceChange} onPriceEdit={onPriceEdit} onPriceTypeChange={onPriceTypeChange} />
            </div>
            <div className="cart-total text-right text-sm font-semibold text-gray-800 dark:text-gray-100 font-mono tabular-nums">{fmt(lineTotal)}</div>
            <div className="cart-remove"><RemoveButton idx={idx} onRemove={onRemove} /></div>
        </div>
    );
}

// ── Phone row: swipe left to delete ───────────────────────────────────────────

function MobileCartRow({ items, item, idx, isSelected, onSelect, onRemove, onUnitChange }) {
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
                <Thumb item={item} size="w-11 h-11" />
                <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate" title={nameTooltip(item)}>{item.name}</p>
                    <div className="flex items-center gap-1.5 mt-0.5 min-w-0">
                        <span className="text-xs text-gray-400 dark:text-gray-500 font-mono tabular-nums truncate">
                            {item.sku && <span className="font-sans">{item.sku} · </span>}{fmt(item.price)}
                        </span>
                        <StockBadge items={items} item={item} />
                    </div>
                    {unitsOf(item).length > 1 && (
                        <div className="mt-1.5"><UnitSegments item={item} idx={idx} onUnitChange={onUnitChange} /></div>
                    )}
                </div>
                <div className="text-right shrink-0">
                    <p className="text-xs text-gray-400 dark:text-gray-500 font-mono tabular-nums">×{item.qty}</p>
                    <p className="text-sm font-bold text-gray-800 dark:text-gray-100 font-mono tabular-nums">{fmt(lineTotal)}</p>
                </div>
            </div>
        </div>
    );
}

// ── The cart ──────────────────────────────────────────────────────────────────

export default function Cart({
    items, selectedIdx, onSelect, onQtyChange, onRemove, onPriceEdit,
    onUnitChange, onPriceTypeChange, onSetDefaultUnit, onQtyEditDone,
    allowPriceChange = false,
}) {
    // Two units of one product are two lines, so the id alone is not unique.
    const lineKey = (item, idx) => `${item.id}-${item.unit_id ?? 'x'}-${idx}`;
    const handlers = { onSelect, onQtyChange, onRemove, onPriceEdit, onUnitChange, onPriceTypeChange, onSetDefaultUnit, onQtyEditDone };

    if (items.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center h-full text-gray-300 dark:text-gray-700">
                <svg className="w-16 h-16 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1}
                        d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0a1.5 1.5 0 103 0m6 0a1.5 1.5 0 103 0" />
                </svg>
                <p className="text-sm">Cart is empty</p>
                <p className="text-xs mt-1 hidden md:block">Scan a barcode or press <kbd className="px-1.5 py-0.5 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 font-mono shadow-sm mx-1">F3</kbd> to search</p>
                <p className="text-xs mt-1 md:hidden">Search above to add products</p>
            </div>
        );
    }

    return (
        <>
            {/* ── Phone: swipeable cards ─────────────────── */}
            <div className="md:hidden py-2">
                {items.map((item, idx) => (
                    <MobileCartRow
                        key={lineKey(item, idx)}
                        items={items}
                        item={item}
                        idx={idx}
                        isSelected={idx === selectedIdx}
                        onSelect={onSelect}
                        onRemove={onRemove}
                        onUnitChange={onUnitChange}
                    />
                ))}
            </div>

            {/* ── Tablet & desktop: aligned grid rows ─────── */}
            <div className="hidden md:block">
                {/* Headings share the rows' columns, so only on the one-line layout. */}
                <div className="cart-row cart-head sticky top-0 bg-gray-50 dark:bg-gray-950 z-10 text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide font-medium">
                    <span className="cart-thumb" />
                    <span className="cart-name">Product</span>
                    <span className="cart-qty text-center">Qty</span>
                    <span className="cart-unit">Unit</span>
                    <span className="cart-price text-right">Unit Price</span>
                    <span className="cart-total text-right">Total</span>
                    <span className="cart-remove" />
                </div>

                {items.map((item, idx) => (
                    <CartRow
                        key={lineKey(item, idx)}
                        items={items}
                        item={item}
                        idx={idx}
                        isSelected={idx === selectedIdx}
                        allowPriceChange={allowPriceChange}
                        handlers={handlers}
                    />
                ))}
            </div>
        </>
    );
}
