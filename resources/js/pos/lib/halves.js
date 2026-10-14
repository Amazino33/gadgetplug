import { vendorSettings } from './vendorSettings';

/**
 * Half quantities at the till: ½, 1½, 2½ of a unit.
 *
 * Only a shop that sells halves ever gets one (vendorSettings().
 * allow_half_quantities, cached from TillProfile). For every other shop each
 * function here gives back exactly what the till always did: a whole quantity
 * is priced unit price × quantity, steps by 1 and prints as a plain number.
 *
 * The arithmetic is done in whole half-steps (qty × 2) and whole kobo
 * (naira × 100) so nothing drifts; it is converted back only for display and
 * for the payload. The rules mirror app/Services/Pos/HalfPricing.php, and the
 * server's figure is the one that is kept:
 *
 *   - retail half: the unit's set half price, or half its price when blank;
 *   - wholesale half: always half the unit's wholesale price;
 *   - a haggled price: half of what was agreed;
 *   - a line of q: floor(q) whole units + one half when q has a half;
 *   - the floor of a half: half the unit's floor.
 */

const EPSILON = 1e-6;

export function halvesEnabled() {
    return Boolean(vendorSettings().allow_half_quantities);
}

/** How far the quantity steppers move: ½ for a halves shop, 1 for everyone else. */
export function qtyStep(halves = halvesEnabled()) {
    return halves ? 0.5 : 1;
}

/** The quantity in whole half-steps: 1.5 → 3. */
export function toSteps(qty) {
    return Math.round(Number(qty || 0) * 2);
}

export function isWhole(qty) {
    const n = Number(qty);
    return Number.isFinite(n) && Math.abs(n - Math.round(n)) < EPSILON;
}

export function isHalfStep(qty) {
    return isWhole(Number(qty) * 2);
}

/** True for 0.5, 1.5 — not for 1 or 2. */
export function hasHalf(qty) {
    return isHalfStep(qty) && ! isWhole(qty);
}

/** The whole units in a quantity: 1 for 1.5, 0 for 0.5. */
export function wholePart(qty) {
    return Math.floor(toSteps(qty) / 2);
}

/** A half-step-safe quantity: 1.4999999 → 1.5, 2.0000001 → 2. */
export function cleanQty(qty) {
    return toSteps(qty) / 2;
}

/** Floors a number of units to what can actually be sold: 19.75 → 19.5 (halves) or 19. */
export function floorToStep(qty, halves = halvesEnabled()) {
    const n = Number(qty);

    if (! Number.isFinite(n)) return n;

    return halves ? Math.floor(n * 2 + EPSILON) / 2 : Math.floor(n + EPSILON);
}

const kobo = (naira) => Math.round(Number(naira || 0) * 100);

/**
 * What half of one unit costs on this cart line.
 *
 * At the list price the unit's own half price applies (half_price comes down
 * with the catalogue, only for a halves shop). Wholesale is always exactly
 * half. A haggled price has no half of its own, so it is half of the deal.
 */
export function halfUnitPrice(line) {
    const price = Number(line?.price ?? 0);
    const list  = Number(line?.listPrice ?? price);
    const atList = Math.abs(price - list) < 0.005;

    if (line?.price_type !== 'wholesale' && atList && line?.half_price != null) {
        return kobo(line.half_price) / 100;
    }

    return Math.round(kobo(price) / 2) / 100;
}

/** The line's money before its discount: whole units, plus a half if there is one. */
export function lineGross(line) {
    const qty   = Number(line?.qty ?? 0);
    const price = Number(line?.price ?? 0);

    // No half in it: price × qty, the very expression the till always used,
    // so a whole line's total does not move by so much as float noise.
    if (! hasHalf(qty)) return price * qty;

    const whole = wholePart(qty) * kobo(price);
    const half  = hasHalf(qty) ? kobo(halfUnitPrice(line)) : 0;

    return (whole + half) / 100;
}

/** The line's money after its own discount. */
export function lineNet(line) {
    return lineGross(line) - Number(line?.lineDiscount || 0);
}

/** The lowest this line may come to: whole units at the floor, a half at half of it. */
export function lineFloorTotal(line, unitFloor) {
    const qty = Number(line?.qty ?? 0);

    if (! hasHalf(qty)) return unitFloor * qty;

    return (wholePart(qty) * kobo(unitFloor) + (hasHalf(qty) ? Math.round(kobo(unitFloor) / 2) : 0)) / 100;
}

/** For people to read: "½", "1½", "12" in a halves shop; the plain number elsewhere. */
export function formatQty(qty, halves = halvesEnabled()) {
    const n = Number(qty);

    if (! Number.isFinite(n)) return String(qty ?? '');
    if (isWhole(n)) return String(Math.round(n));

    if (halves && hasHalf(n)) {
        const sign  = n < 0 ? '-' : '';
        const whole = wholePart(Math.abs(n));

        return `${sign}${whole > 0 ? whole : ''}½`;
    }

    return String(Number(n.toFixed(3)));
}

/** A quantity typed at the till: "2", "2.5", "2½", "½". Null when it is not a number. */
export function parseQty(text) {
    let value = String(text ?? '').trim();

    if (value === '') return null;

    if (value.endsWith('½')) {
        const whole = value.slice(0, -1).trim();
        value = `${whole === '' ? '0' : whole}.5`;
    }

    if (! /^\d*\.?\d+$|^\d+\.$/.test(value)) return null;

    return Number(value);
}

/** Why a typed quantity is not acceptable here, or null when it is. */
export function qtyError(qty, halves = halvesEnabled()) {
    if (qty == null || ! Number.isFinite(Number(qty))) return 'Enter a quantity.';
    if (Number(qty) <= 0) return 'The quantity must be more than zero.';

    if (halves) return isHalfStep(qty) ? null : 'Quantities go in halves: ½, 1, 1½ …';

    return isWhole(qty) ? null : 'The quantity must be a whole number.';
}
