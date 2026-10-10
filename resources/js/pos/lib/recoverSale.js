import { findUnit, lineFields } from './units';

/**
 * Turns a refused sale's lines back into cart lines.
 *
 * A sale records only what was sold — name, price, quantity. A cart line
 * carries the whole product, and the price limits live on that. Handing the
 * bare sale lines back produced a cart the till could not reprice: the line had
 * no floor and no list price to measure a new price against, so the one thing
 * the cashier needed to do was the one thing they could not.
 *
 * @param  items      the refused sale's lines
 * @param  catalogue  today's products for this branch, however many were found
 */
export function cartLinesFromSale(items = [], catalogue = []) {
    const byId = new Map(catalogue.filter(Boolean).map((p) => [p.id, p]));

    return items.map((item) => {
        const product = byId.get(item.product_id);

        // Sold in a unit (a crate): the line comes back as that unit, with
        // unit_qty crates at the crate price — not 24 bottles at it.
        const unitFieldsFromSale = item.product_unit_id
            ? {
                unit_id:    item.product_unit_id,
                unit_name:  item.unit_name ?? null,
                factor:     item.unit_qty ? Math.max(1, Math.round(item.quantity / item.unit_qty)) : 1,
                price_type: item.price_type ?? 'retail',
            }
            : {};
        const qty = item.product_unit_id ? (item.unit_qty ?? item.quantity) : item.quantity;

        // Gone from this branch's catalogue since it was sold. Keep what the
        // sale recorded, so the cashier can at least see it and take it out.
        if (! product) {
            return {
                id:           item.product_id,
                name:         item.product_name,
                sku:          item.product_sku ?? null,
                price:        item.unit_price,
                listPrice:    item.unit_price,
                qty,
                lineDiscount: item.discount_amount || 0,
                ...unitFieldsFromSale,
            };
        }

        const unit = item.product_unit_id ? findUnit(product, item.product_unit_id) : null;

        if (unit) {
            const fields = lineFields(unit, item.price_type ?? 'retail');

            return {
                ...product,
                ...fields,
                price:        Math.max(item.unit_price, fields.min_price),
                qty,
                lineDiscount: item.discount_amount || 0,
            };
        }

        const floor = product.min_price ?? product.price;

        return {
            ...product,
            listPrice: product.price,
            // Lifted to the floor when that is what got the sale refused. A
            // price that was legitimately negotiated stays as it was — only the
            // one that could never have been accepted is corrected.
            price:        Math.max(item.unit_price, floor),
            qty:          item.quantity,
            lineDiscount: item.discount_amount || 0,
        };
    });
}
