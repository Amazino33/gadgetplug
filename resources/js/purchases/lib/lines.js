/**
 * Purchase lines: what the Purchase Terminal holds while a delivery is built.
 *
 * One source of truth for cost: `baseCost`, the cost of ONE base unit (a
 * piece). Every unit's cost is worked out from it — a carton of 12 costs
 * baseCost × 12 — so typing a carton's cost sets the piece cost, and every
 * other unit follows. It is shared by every line of the same product.
 *
 * Selling prices are per unit (`prices`, keyed by unitKey), also shared per
 * product. The base unit's is the product's own price.
 *
 * Pure functions only, so the arithmetic can be tested without a screen.
 */

export const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

/** Rounded to the shop's price step (₦50 by default); 0 means to the kobo. */
export const roundTo = (n, step) => {
    const s = Number(step) || 0;

    return s > 0 ? Math.round((Number(n) || 0) / s) * s : round2(n);
};

export const unitKey = (u) => (u?.id == null ? 'base' : String(u.id));

/** The units a product can be bought in, smallest first. Never empty. */
export function unitsFor(product) {
    const units = Array.isArray(product?.units) ? product.units.filter(Boolean) : [];

    if (units.length === 0) {
        return [{ id: null, name: product?.base_unit || 'Piece', factor: 1, is_base: true, selling_price: product?.price ?? 0 }];
    }

    return [...units]
        .map((u) => ({ ...u, factor: Math.max(1, Number(u.factor ?? u.conversion_factor ?? 1)) }))
        .sort((a, b) => a.factor - b.factor);
}

export function baseUnitOf(product) {
    const units = unitsFor(product);

    return units.find((u) => u.is_base) ?? units[0];
}

export function unitOf(line) {
    return unitsFor(line.product).find((u) => u.id === line.unitId) ?? baseUnitOf(line.product);
}

/** What the product sells for in each unit now: the panel's starting point. */
export function initialPrices(product) {
    const prices = {};

    for (const u of unitsFor(product)) {
        prices[unitKey(u)] = round2(u.is_base ? product?.price ?? 0 : (u.selling_price ?? (product?.price ?? 0) * u.factor));
    }

    return prices;
}

export const basePrice = (line) => Number(line.prices?.[unitKey(baseUnitOf(line.product))] ?? line.product?.price ?? 0);

let seq = 0;
const nextKey = () => `l${Date.now().toString(36)}${(seq++).toString(36)}`;

/**
 * A fresh line for a product, in the unit it was scanned as (a carton's own
 * barcode) or its base unit. Cost starts at the last cost paid per base unit.
 */
export function newLine(product, unitId = undefined) {
    const units = unitsFor(product);
    const unit  = units.find((u) => u.id === (unitId ?? product?.scanned_unit_id)) ?? baseUnitOf(product);

    return {
        key: nextKey(),
        product,
        unitId: unit.id,
        qty: 1,
        baseCost: Number(product?.cost_price ?? 0) || 0,
        prices: initialPrices(product),
        serials: [],
        propagate: true,
        showPrices: false,
    };
}

/**
 * Adds a scanned or picked product. Same product in the same unit adds one
 * to that line; anything else is a new line, carrying the cost and prices
 * already set for that product so the two lines never disagree.
 */
export function addProduct(lines, product) {
    const unitId = product?.scanned_unit_id ?? baseUnitOf(product).id;
    const at     = lines.findIndex((l) => l.product.id === product.id && l.unitId === unitId);

    if (at >= 0) {
        const next = lines.slice();
        next[at] = { ...next[at], qty: Number(next[at].qty || 0) + 1 };

        return { lines: next, key: next[at].key };
    }

    const sibling = lines.find((l) => l.product.id === product.id);
    const line    = newLine(product, unitId);

    if (sibling) {
        line.baseCost = sibling.baseCost;
        line.prices   = { ...sibling.prices };
    }

    return { lines: [...lines, line], key: line.key };
}

/** Cost of one of `unit`, from the shared base cost. */
export const unitCost = (line, unit) => round2((Number(line.baseCost) || 0) * unit.factor);

/** Every unit's cost, price and margin, for the prices panel. */
export function unitRows(line) {
    return unitsFor(line.product).map((u) => {
        const cost   = unitCost(line, u);
        const price  = Number(line.prices?.[unitKey(u)] ?? 0);
        const margin = price > 0 ? Math.round(((price - cost) / price) * 1000) / 10 : null;

        return { unit: u, key: unitKey(u), cost, price, margin, warn: margin === null || margin <= 0 };
    });
}

// Quantities floor to whole units — or to halves for a shop that sells them,
// where 10½ crates is 10½ and not 10.
const HALVES = (typeof window !== 'undefined' && window.PURCHASES_CONFIG?.allowHalves === true);
const floorQty = (value) => {
    const n = Number(value) || 0;
    return HALVES ? Math.floor(n * 2 + 1e-6) / 2 : Math.floor(n);
};

/** Everything a line's row shows that is worked out rather than typed. */
export function calc(line) {
    const unit     = unitOf(line);
    const qty      = Math.max(0, floorQty(line.qty));
    const cost     = unitCost(line, unit);
    const costBase = Number(line.baseCost) || 0;
    const sell     = basePrice(line);
    const profit   = sell - costBase;
    const margin   = sell > 0 ? (profit / sell) * 100 : null;
    const rows     = unitRows(line);

    return {
        unit,
        factor: unit.factor,
        cost,
        baseQty: qty * unit.factor,
        costBase: round2(costBase),
        lineTotal: round2(qty * cost),
        sell,
        profit: round2(profit),
        margin: margin === null ? null : Math.round(margin * 10) / 10,
        rows,
        // Any unit selling at or below what it cost — or with no price at all.
        warn: rows.some((r) => r.warn),
    };
}

/** A different unit on the line. Its cost comes from the shared base cost. */
export function changeUnit(line, unitId) {
    const to = unitsFor(line.product).find((u) => u.id === unitId) ?? unitOf(line);

    return { ...line, unitId: to.id };
}

/**
 * A cost typed for one unit sets the base cost — that unit's cost ÷ its
 * factor — for every line of the product, so every unit's cost follows.
 */
export function setUnitCost(lines, productId, unit, value) {
    const baseCost = (Number(value) || 0) / Math.max(1, unit.factor);

    return lines.map((l) => (l.product.id === productId ? { ...l, baseCost } : l));
}

/**
 * A selling price typed for one unit. With "update other units by the same %"
 * on, every other unit moves by the change this one made, rounded to the
 * shop's step — measured from `anchor`, the prices as they stood when the
 * person started typing, so each keystroke does not compound on the last.
 * The typed price itself is kept exactly as typed.
 */
export function setUnitPrice(lines, productId, key, price, { propagate = true, step = 50, anchor = null } = {}) {
    const value = Number(price) || 0;

    return lines.map((l) => {
        if (l.product.id !== productId) return l;

        const from   = anchor ?? l.prices;
        const was    = Number(from[key]) || 0;
        const prices = { ...l.prices, [key]: value };

        if (propagate && was > 0) {
            const ratio = value / was;

            for (const k of Object.keys(from)) {
                if (k !== key) prices[k] = roundTo((Number(from[k]) || 0) * ratio, step);
            }
        }

        return { ...l, prices };
    });
}

export function setPropagate(lines, productId, propagate) {
    return lines.map((l) => (l.product.id === productId ? { ...l, propagate } : l));
}

export function totals(lines) {
    return lines.reduce(
        (acc, l) => {
            const c = calc(l);
            acc.total = round2(acc.total + c.lineTotal);
            acc.units += c.baseQty;
            return acc;
        },
        { total: 0, units: 0 },
    );
}

/** Serials are wanted only for products that track them, one per base unit. */
export function serialProblem(line) {
    if (!line.product?.track_serial) return null;

    const { baseQty } = calc(line);
    const entered     = (line.serials ?? []).map((s) => String(s).trim()).filter(Boolean);

    if (entered.length !== baseQty) return `${entered.length} / ${baseQty} serials`;
    if (new Set(entered).size !== entered.length) return 'A serial is entered twice';

    return null;
}

export function toPayload(lines) {
    return lines.map((l) => {
        const unit = unitOf(l);

        return {
            product_id: l.product.id,
            product_unit_id: l.unitId,
            quantity: Math.max(HALVES ? 0.5 : 1, floorQty(l.qty)),
            unit_cost: unitCost(l, unit),
            selling_price: round2(basePrice(l)),
            unit_prices: unitsFor(l.product)
                .filter((u) => u.id != null && !u.is_base)
                .map((u) => ({ unit_id: u.id, price: round2(l.prices?.[unitKey(u)] ?? 0) })),
            serials: l.product?.track_serial ? (l.serials ?? []).map((s) => String(s).trim()).filter(Boolean) : [],
        };
    });
}

/** A saved draft's lines, back in the terminal. */
export function fromDetail(purchase) {
    return (purchase?.lines ?? [])
        .filter((l) => l.product)
        .map((l) => {
            const line   = newLine(l.product, l.unit_id ?? undefined);
            const factor = Math.max(1, Number(l.factor) || unitOf(line).factor);
            const prices = { ...line.prices, [unitKey(baseUnitOf(l.product))]: round2(l.selling_price) };

            for (const [unitId, price] of Object.entries(l.unit_prices ?? {})) prices[String(unitId)] = round2(price);

            return {
                ...line,
                qty: l.unit_qty,
                baseCost: (Number(l.cost_per_unit) || 0) / factor,
                prices,
                serials: [...(l.serials ?? [])],
            };
        });
}

/** Whether a typed code reads like a barcode or SKU rather than a name. */
export function looksLikeCode(text) {
    const t = String(text ?? '').trim();

    return t.length > 0 && !/\s/.test(t) && /\d/.test(t);
}
