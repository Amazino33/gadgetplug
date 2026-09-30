/**
 * Puts goods on a sale.
 *
 * Adding, never setting. The same product reached twice is one line whose
 * quantity grew, not two lines and not a quantity overwritten — a cashier who
 * scans a charger three times has three chargers, and one who asks for two of
 * something already sitting at four wants six.
 *
 * That is the opposite of what the quantity box does when it is opened on a
 * line already in the cart, where the number typed replaces what is there and
 * zero removes the line. Both are right; they are answers to different
 * questions ("how many more" against "how many, in total"), which is why the
 * two paths are kept apart rather than sharing a "quantity" function that
 * would have to guess which was meant.
 *
 * Returns the new list and where the affected line ended up, because the till
 * highlights it and may open a price prompt on it next.
 */
export function addToCart(cart = [], product, qty = 1) {
    // Match on both product ID and the current selling price.
    // If a cashier discounted a line and then scans the product again,
    // it will appear as a new row at full price rather than merging into
    // the discounted one.
    const index = cart.findIndex((i) => 
        i.id === product.id && 
        parseFloat(i.price) === parseFloat(product.price)
    );

    if (index >= 0) {
        const items = [...cart];
        items[index] = { ...items[index], qty: items[index].qty + qty };

        return { items, index };
    }

    return {
        // listPrice keeps the catalogue price after `price` has been haggled
        // down, so the modal and receipt can still show what the customer
        // would otherwise have paid.
        items: [...cart, { ...product, listPrice: product.price, qty, lineDiscount: 0 }],
        index: cart.length,
    };
}

/**
 * How many more of a product this sale can take.
 *
 * What is physically on this branch's shelf — reservations included, because
 * the goods are still here and a cashier may override one — less what the
 * sale already holds on its other lines. The same limit the server enforces,
 * so a quantity it would refuse is caught while the customer is still at the
 * counter rather than stranded in the queue: on 30/09/2026 a smart watch rung
 * as ₦324,000,000 sat on a till as "pending upload" and was counted into the
 * cashier's drawer.
 *
 * Infinity when the till does not know the stock (a catalogue cached before
 * branch stock was sent): no guess is better than a wrong refusal.
 */
export function roomFor(cart = [], product, exceptIndex = null) {
    const available = Number(product?.available_stock);

    if (product?.available_stock == null || !Number.isFinite(available)) return Infinity;

    const onShelf = available + (Number(product.reserved) || 0);
    const alreadyInSale = cart.reduce(
        (total, line, i) => (i !== exceptIndex && line.id === product.id ? total + (Number(line.qty) || 0) : total),
        0,
    );

    return Math.max(0, onShelf - alreadyInSale);
}

/**
 * Whether a negotiated price is far enough above normal to be a typing slip.
 *
 * Haggling goes down, not up. A price well over the normal one is almost
 * always extra zeros, so the till asks before believing it — it does not
 * refuse, because a real premium sale is possible.
 */
export const PRICE_CONFIRM_MULTIPLE = 2;

export function priceNeedsConfirming(price, listPrice) {
    const p = Number(price);
    const list = Number(listPrice);

    return Number.isFinite(p) && Number.isFinite(list) && list > 0 && p > list * PRICE_CONFIRM_MULTIPLE;
}
