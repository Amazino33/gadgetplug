import { anyTyped, baseTotal, formatQty, key, mergeBreakdown, parseQty, plural, qtyError, spell, toBreakdown, usesPacks } from '../lib/units';
import Keypad from './Keypad';

/** Whether what is typed on a card can be saved. */
/** Damaged and expired typed on this card, in base units (0 when nothing typed). */
export function badOf(card) {
    const n = (v) => parseQty(v) ?? 0;
    return { damaged: n(card?.bad?.damaged), expired: n(card?.bad?.expired) };
}

export function cardReady(card, allowHalves) {
    if (!card) return false;
    const rows = card.rows;
    const bad = badOf(card);
    const badError = ['damaged', 'expired'].some((t) => qtyError(card.bad?.[t], allowHalves));
    const anything = anyTyped(rows, card.values) || bad.damaged > 0 || bad.expired > 0;
    return anything && !badError && !rows.some((unit) => qtyError(card.values[key(unit)], allowHalves));
}

/**
 * The bottom of the counting screen while a product is open: what it is, how
 * many (unit chips, live total, keypad), and Save. Never a pop-up over the
 * camera.
 *
 * Counted before on this aisle? It says so here, with [+ Add] and [Replace]
 * side by side. Add is the default: typing a number straight away adds.
 *
 * The count screen holds the state (a scan needs to read it); this only shows
 * it and reports key presses.
 */
export default function EntryPanel({ card, allowHalves, tapMode, onKey, onUnit, onMode, onSave, onSkip, onConfirmBig, onFixBig, onTarget }) {
    const { product, rows, values, unit: active, mode, existing, unlisted } = card;
    const baseName = product.base_unit ?? 'Piece';
    const typed    = baseTotal(rows, values);
    const already  = existing ? Number(existing.counted) : 0;
    const adding   = existing && mode !== 'replace';
    const total    = adding ? already + typed : typed;
    const ready    = cardReady(card, allowHalves);
    const error    = rows.map((u) => qtyError(values[key(u)], allowHalves)).find(Boolean);
    const typedAny = anyTyped(rows, values);
    // In words, when packs are involved: "12 Crates + 3 Pieces = 147 Pieces".
    const typedLines = toBreakdown(rows, values);
    const wholeLines = adding ? mergeBreakdown(existing?.breakdown, typedLines) : typedLines;
    const inPacks    = usesPacks(wholeLines);
    const totalText  = `${formatQty(total)} ${plural(baseName, total)}`;
    // Damaged / expired: the keypad can type into either instead of the count.
    const target     = card.target ?? 'good';
    const badTyped   = { damaged: card.bad?.damaged ?? '', expired: card.bad?.expired ?? '' };
    const badBefore  = { damaged: adding ? Number(existing?.damaged ?? 0) : 0, expired: adding ? Number(existing?.expired ?? 0) : 0 };

    const press = (e) => e.preventDefault(); // keys never take focus from the scan box
    const chip = (on, tone = 'teal') => `flex h-11 shrink-0 items-center gap-1.5 rounded-full px-3 text-sm font-bold transition-colors ${on
        ? (tone === 'red' ? 'bg-red-600 text-white shadow-sm' : 'bg-teal-700 text-white shadow-sm')
        : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'}`;

    // The one display: what is being typed now, and what it comes to.
    const bigValue = tapMode ? formatQty(total) : target === 'good' ? (values[active] || '') : (badTyped[target] || '');
    let sumLine;
    if (error) {
        sumLine = <span className="text-red-600">{error}</span>;
    } else if (tapMode) {
        sumLine = 'Every scan adds 1';
    } else if (target !== 'good') {
        sumLine = (
            <span className="text-red-700 dark:text-red-300">
                Counted in {plural(baseName, 2).toLowerCase()}, apart from the good ones{badBefore[target] ? ` · adds to ${formatQty(badBefore[target])}` : ''}
            </span>
        );
    } else if (existing) {
        sumLine = (
            <span data-testid="before">
                {!typedAny && <>{card.editing ? 'Was ' : 'Already counted '}<strong>{formatQty(already)}</strong></>}
                {typedAny && adding && <>{formatQty(already)} + {formatQty(typed)} = <strong>{formatQty(total)}</strong></>}
                {typedAny && !adding && <>Was {formatQty(already)} → <strong>{formatQty(total)}</strong></>}
            </span>
        );
    } else if (inPacks) {
        sumLine = <span className="font-bold text-teal-800 dark:text-teal-200" data-testid="in-words">{spell(wholeLines)} = {totalText}</span>;
    } else {
        sumLine = <>= {totalText}</>;
    }
    // What the big number is counted in, beside it: "3 Crates", "2 damaged".
    const activeUnit = rows.find((u) => key(u) === active);
    const bigUnit = tapMode ? '' : target !== 'good' ? target : (bigValue ? plural(activeUnit?.name ?? baseName, Number(bigValue) || 2) : '');

    return (
        <section className="flex min-h-full flex-col gap-2 [@media(max-height:620px)]:gap-1.5 rounded-t-3xl bg-white px-3 pb-[max(0.5rem,env(safe-area-inset-bottom))] pt-2.5 shadow-[0_-4px_20px_rgba(0,0,0,0.06)] dark:bg-gray-900" aria-label={`Count ${product.name}`}>
            <div className="flex shrink-0 items-center gap-2.5">
                {product.image
                    ? <img src={product.image} alt="" className="h-11 w-11 shrink-0 rounded-xl bg-gray-100 object-cover" />
                    : <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-lg font-black text-teal-700 dark:bg-teal-900/40 dark:text-teal-200">{product.name.slice(0, 1)}</div>}
                <div className="min-w-0 flex-1">
                    <h2 className="truncate text-lg font-extrabold leading-tight text-gray-900 dark:text-white">{product.name}</h2>
                    <p className="flex min-w-0 items-center gap-1.5 text-sm text-gray-500">
                        <span className="truncate">{product.sku}</span>
                        {unlisted && <span className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-900">Found here, not on aisle list</span>}
                    </p>
                </div>
                <button type="button" onMouseDown={press} onClick={onSkip} aria-label="Close without saving" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xl text-gray-500 active:bg-gray-200 dark:bg-gray-800 dark:text-gray-300">✕</button>
            </div>

            <div className={`flex h-[72px] [@media(max-height:620px)]:h-14 shrink-0 items-center gap-3 rounded-2xl px-3 ${existing && mode === null ? 'bg-amber-50 ring-1 ring-amber-200 dark:bg-amber-900/30 dark:ring-amber-800' : 'bg-gray-50 dark:bg-gray-800/60'}`} aria-live="polite">
                <div className="min-w-0 flex-1">
                    <p className={`flex items-baseline gap-1.5 leading-none ${target === 'good' ? 'text-gray-900 dark:text-white' : 'text-red-700 dark:text-red-300'}`}>
                        <span className="text-[34px] [@media(max-height:620px)]:text-[28px] font-black tabular-nums" data-testid={tapMode ? 'tap-total' : undefined}>{bigValue || <span className="text-gray-300 dark:text-gray-600">0</span>}</span>
                        {bigUnit && <span className="truncate text-base font-bold opacity-70">{bigUnit}</span>}
                    </p>
                    <p className="mt-1 truncate text-sm font-semibold text-gray-600 dark:text-gray-300">{sumLine}</p>
                </div>
                {existing && (
                    <div className="flex shrink-0 rounded-full bg-white p-0.5 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700" role="group" aria-label="Add or replace">
                        <button type="button" onMouseDown={press} onClick={() => onMode('add')} aria-pressed={mode !== 'replace'}
                            className={`h-9 rounded-full px-3 text-sm font-extrabold ${mode !== 'replace' ? 'bg-teal-700 text-white' : 'text-gray-600 dark:text-gray-300'}`}>+ Add</button>
                        <button type="button" onMouseDown={press} onClick={() => onMode('replace')} aria-pressed={mode === 'replace'}
                            className={`h-9 rounded-full px-3 text-sm font-extrabold ${mode === 'replace' ? 'bg-teal-700 text-white' : 'text-gray-600 dark:text-gray-300'}`}>Replace</button>
                    </div>
                )}
            </div>

            {!tapMode && (
                <div className="-mx-3 flex shrink-0 gap-1.5 overflow-x-auto px-3 [mask-image:linear-gradient(to_right,black_88%,transparent)] [scrollbar-width:none]" role="group" aria-label="What the keypad types">
                    {rows.map((unit) => {
                        const k = key(unit);
                        const on = target === 'good' && k === active;
                        return (
                            <button key={k} type="button" onMouseDown={press} onClick={() => onUnit(k)} aria-pressed={on} aria-label={`Count in ${unit.name}`} className={chip(on)}>
                                <span>{unit.name}{unit.factor > 1 ? ` ×${unit.factor}` : ''}</span>
                                <span className={`tabular-nums ${on ? '' : 'text-gray-900 dark:text-white'}`}>{values[k] || ''}</span>
                            </button>
                        );
                    })}
                    {[['damaged', 'Damaged'], ['expired', 'Expired']].map(([t, label]) => {
                        const n = badBefore[t] + (parseQty(badTyped[t]) ?? 0);
                        return (
                            <button key={t} type="button" onMouseDown={press} onClick={() => onTarget?.(t)} aria-pressed={target === t} aria-label={`Type ${label.toLowerCase()}`} className={chip(target === t, 'red')}>
                                {label}{n ? ` ${formatQty(n)}` : ''}
                            </button>
                        );
                    })}
                </div>
            )}

            <div className="min-h-[12.5rem] [@media(max-height:620px)]:min-h-[10.75rem] flex-1">
                {card.bigAsk ? (
                    <div role="alertdialog" aria-label="Check this number" className="flex h-full flex-col justify-center gap-2 rounded-2xl bg-amber-50 p-3 text-center ring-1 ring-amber-200 dark:bg-amber-900/40 dark:ring-amber-800">
                        <p className="text-lg font-extrabold text-amber-950 dark:text-amber-50">Save {inPacks ? `${spell(wholeLines)} = ` : ''}{totalText}?</p>
                        <p className="text-sm font-semibold text-amber-900 dark:text-amber-100">That is a lot for {product.name}. Check for an extra zero, or the wrong unit.</p>
                        <div className="flex gap-2">
                            <button type="button" onClick={onFixBig} className="h-14 flex-1 rounded-xl border-2 border-amber-700 text-base font-extrabold text-amber-900 dark:text-amber-100">Fix the number</button>
                            <button type="button" onClick={onConfirmBig} className="h-14 flex-1 rounded-xl bg-teal-700 text-base font-extrabold text-white">Yes, {formatQty(total)} is right</button>
                        </div>
                    </div>
                ) : (
                    <Keypad allowHalves={allowHalves} onKey={onKey} onSave={onSave} canSave={ready} />
                )}
            </div>
        </section>
    );
}
