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
    const index = cart.findIndex((i) => i.id === product.id);

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
