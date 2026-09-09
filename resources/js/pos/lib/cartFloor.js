/**
 * The least a cart may sell for, and whether a discount takes it under.
 *
 * The same sum the server works out in PosPriceFloor::guard(). It lives here so
 * the till can answer the question itself: the server's refusal is correct but
 * arrives too late to be useful — while the cashier is offline it lands long
 * after the customer has gone, as a sale that can never sync and goods that are
 * already off the shelf.
 *
 * Every line can sit exactly on its own floor and still be dragged under it by
 * a discount applied to the whole cart, which is the case the till used to
 * allow and the server then refused.
 */

/** Half a kobo, matching the server's epsilon, so a price sitting exactly on its floor passes. */
const EPSILON = 0.005;

/** A line's own floor: its negotiated minimum, else what it lists at. */
export function lineFloor(item) {
    return item.min_price ?? item.listPrice ?? item.price ?? 0;
}

export function cartFloorTotal(cart = []) {
    return cart.reduce((total, item) => total + lineFloor(item) * (item.qty ?? 0), 0);
}

/** What the goods come to before any cart-level discount, net of line discounts. */
export function cartSubtotal(cart = []) {
    return cart.reduce(
        (total, item) => total + (item.price ?? 0) * (item.qty ?? 0) - (item.lineDiscount || 0),
        0,
    );
}

/**
 * Whether taking this much off would push the goods under their floor.
 *
 * Takes the two totals rather than the cart so the discount screen, which only
 * ever knows those, runs this exact rule instead of its own copy of it.
 */
export function breachesFloor(subtotal, floorTotal, discountValue) {
    if (! (discountValue > 0)) return false;

    return Math.max(0, subtotal - discountValue) + EPSILON < floorTotal;
}

/** Whether taking this much off the whole cart would push it under its floor. */
export function discountBreachesFloor(cart, discountValue) {
    return breachesFloor(cartSubtotal(cart), cartFloorTotal(cart), discountValue);
}
