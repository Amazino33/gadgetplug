/**
 * Counting in units: one row per unit the product has (Crate, Piece …), the
 * total in base units shown as it is typed — the same sum the server makes.
 */

/** The rows to show: the product's units, biggest first; a lone base row if it has none. */
export function unitRows(product) {
    const units = (product?.units ?? []).slice().sort((a, b) => b.factor - a.factor);

    if (units.length > 0) return units;

    return [{ id: null, name: product?.base_unit ?? 'Piece', factor: 1, is_base: true }];
}

/** A typed value as a number, or null when empty or not a number. "1½" is 1.5. */
export function parseQty(raw) {
    const text = String(raw ?? '').trim();
    if (text === '') return null;

    const normal = text.endsWith('½') ? `${text.slice(0, -1).trim() || '0'}.5` : text.replace(',', '.');
    const value = Number(normal);

    return Number.isFinite(value) ? value : null;
}

/** Why a typed value cannot be saved, or null when it can. */
export function qtyError(raw, allowHalves) {
    const value = parseQty(raw);
    if (String(raw ?? '').trim() === '') return null;
    if (value === null || value < 0) return 'Numbers only, 0 or more.';

    const steps = allowHalves ? value * 2 : value;
    if (Math.abs(steps - Math.round(steps)) > 1e-9) return allowHalves ? 'Whole or half units only.' : 'Whole units only.';

    return null;
}

/** Base-unit total of what has been typed: Σ qty × factor. */
export function baseTotal(rows, values) {
    const total = rows.reduce((sum, unit) => {
        const qty = parseQty(values[key(unit)]);
        return qty === null ? sum : sum + qty * unit.factor;
    }, 0);

    return Math.round(total * 1000) / 1000;
}

/** Anything typed at all (a typed 0 counts: "none here"). */
export function anyTyped(rows, values) {
    return rows.some((unit) => String(values[key(unit)] ?? '').trim() !== '');
}

/** The lines the server expects. */
export function toLines(rows, values) {
    return rows
        .filter((unit) => String(values[key(unit)] ?? '').trim() !== '')
        .map((unit) => ({ unit_id: unit.id, qty: String(values[key(unit)]).trim() }));
}

/** Typed values from a saved breakdown, for editing it. */
export function fromBreakdown(rows, breakdown) {
    const values = {};
    (breakdown ?? []).forEach((line) => {
        const unit = rows.find((u) => (u.id ?? null) === (line.unit_id ?? null)) ?? rows.find((u) => u.factor === line.factor);
        if (unit) values[key(unit)] = String(line.qty);
    });
    return values;
}

/** "12 Crates + 3 Pieces": what was typed, in its own units, said properly. */
export function spell(breakdown) {
    return (breakdown ?? [])
        .filter((l) => Number(l.qty) !== 0)
        .map((l) => `${formatQty(l.qty)} ${plural(l.unit, Number(l.qty))}`)
        .join(' + ');
}

/** True when any unit other than the base one was used: then the total in base units is worth saying. */
export const usesPacks = (breakdown) => (breakdown ?? []).some((l) => Number(l.qty) !== 0 && Number(l.factor) > 1);

/** "3 Crate + 5 Piece" */
export function describe(breakdown, fallback) {
    const parts = (breakdown ?? []).filter((l) => Number(l.qty) !== 0).map((l) => `${formatQty(l.qty)} ${l.unit}`);
    return parts.length ? parts.join(' + ') : fallback;
}

export function formatQty(value) {
    const n = Number(value ?? 0);
    if (Number.isInteger(n)) return String(n);
    const whole = Math.floor(n);
    return Math.abs(n - whole - 0.5) < 1e-9 ? `${whole > 0 ? whole : ''}½` : String(n);
}

export function plural(word, n) {
    if (n === 1) return word;
    // Already plural or an abbreviation ending in s (pcs, kgs, Pieces): as is.
    if (/s$/i.test(word)) return word;
    return /(x|ch|sh)$/i.test(word) ? `${word}es` : `${word}s`;
}

export const key = (unit) => String(unit.id ?? 'base');

/**
 * The stored form of what was typed: [{unit_id, unit, factor, qty}], zeros
 * left out — the same shape the server keeps.
 */
export function toBreakdown(rows, values) {
    return rows
        .map((unit) => ({ unit, qty: parseQty(values[key(unit)]) }))
        .filter(({ qty }) => qty !== null && qty !== 0)
        .map(({ unit, qty }) => ({ unit_id: unit.id ?? null, unit: unit.name, factor: unit.factor, qty }));
}

/** Two counts of one product in one aisle, added unit by unit (Add). */
export function mergeBreakdown(first, second) {
    const merged = new Map();
    for (const line of [...(first ?? []), ...(second ?? [])]) {
        const k = String(line.unit_id ?? 'base');
        const prev = merged.get(k);
        merged.set(k, prev ? { ...prev, qty: Math.round((Number(prev.qty) + Number(line.qty)) * 1000) / 1000 } : { ...line });
    }
    return [...merged.values()].sort((a, b) => b.factor - a.factor);
}

/** Base units in a breakdown. */
export function totalOf(breakdown) {
    return Math.round((breakdown ?? []).reduce((sum, line) => sum + Number(line.qty) * Number(line.factor), 0) * 1000) / 1000;
}
