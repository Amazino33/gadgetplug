/**
 * Selling units at the till: a bottle, a half crate, a crate of one product.
 *
 * Stock is always counted in the product's base unit (bottles). A cart line
 * sold in a unit keeps `qty` as the number of units sold and `price` as the
 * price of one unit, so `price × qty` is still the line's money — a crate at
 * ₦9,700 is ₦9,700, never 24 bottle prices. `factor` says how many bottles
 * one of them takes off the shelf.
 *
 * A product from a catalogue cached before units existed has no `units`, and
 * every function here leaves it exactly as it was.
 */

/** The units this product can be sold in, base unit first. */
export function unitsOf(product) {
    return Array.isArray(product?.units) ? product.units.filter((u) => u && u.is_active !== false) : [];
}

export function findUnit(product, unitId) {
    if (unitId == null) return null;

    return unitsOf(product).find((u) => Number(u.id) === Number(unitId)) ?? null;
}

/** The unit a product goes into the cart as when nobody said otherwise. */
export function defaultUnit(product) {
    const units = unitsOf(product);

    return findUnit(product, product?.default_unit_id) ?? units.find((u) => u.is_base) ?? units[0] ?? null;
}

/**
 * What one unit sells for at a price type. A unit with no wholesale price
 * sells at retail, and says so, rather than at nothing.
 */
export function unitPrice(unit, priceType = 'retail') {
    if (priceType === 'wholesale' && unit?.wholesale_price != null) {
        return {
            price:            Number(unit.wholesale_price),
            type:             'wholesale',
            floor:            Number(unit.wholesale_min_price ?? unit.wholesale_price),
            wholesaleMissing: false,
        };
    }

    return {
        price:            Number(unit?.price ?? 0),
        type:             'retail',
        floor:            Number(unit?.min_price ?? unit?.price ?? 0),
        wholesaleMissing: priceType === 'wholesale',
    };
}

/** The fields a cart line takes from the unit it is sold in. */
export function lineFields(unit, priceType = 'retail') {
    const { price, type, floor, wholesaleMissing } = unitPrice(unit, priceType);

    return {
        // What the cashier asked for, kept apart from what the unit could
        // give: a wholesale cart that switches a line from the half crate (no
        // wholesale price) to the crate (has one) should land on wholesale.
        requested_price_type: priceType,
        unit_id:          unit.id,
        unit_name:        unit.name,
        factor:           Math.max(1, Number(unit.conversion_factor ?? unit.factor ?? 1)),
        price_type:       type,
        price,
        listPrice:        price,
        // The floor of ONE unit at this price type, which is what the price
        // modal and the cart floor compare a negotiated price against.
        min_price:        floor,
        can_negotiate:    floor < price,
        wholesaleMissing,
    };
}

/**
 * A product made ready for the cart in its unit: the one it was scanned as,
 * the one already chosen, or its default.
 */
export function prepareLine(product, priceType = 'retail') {
    const units = unitsOf(product);

    if (units.length === 0) return product;

    const unit = findUnit(product, product.unit_id ?? product.scanned_unit_id) ?? defaultUnit(product);

    return unit ? { ...product, ...lineFields(unit, product.requested_price_type ?? priceType) } : product;
}

/**
 * Which unit a scanned code means, or undefined when the product does not
 * carry it. A base-unit code adds the product's default unit; a unit's own
 * code adds that unit.
 */
export function unitForBarcode(product, code) {
    const wanted = String(code ?? '').trim();

    if (! wanted) return undefined;

    const match = (product?.barcodes ?? []).find((b) => String(b.barcode) === wanted);

    if (match) {
        return match.unit_id ?? defaultUnit(product)?.id ?? null;
    }

    if (product?.barcode != null && String(product.barcode) === wanted) {
        return defaultUnit(product)?.id ?? null;
    }

    return undefined;
}

/** True when this product carries the code, as its main barcode or any other. */
export function carriesBarcode(product, code) {
    return unitForBarcode(product, code) !== undefined;
}

function plural(name, count) {
    if (count === 1 || /s$/i.test(name)) return name;

    return `${name}s`;
}

/**
 * Stock as people say it: "2 Crates + 12 Bottles (60)", using the biggest
 * unit on sale. Just the number when the product has no bigger unit.
 */
export function stockText(product) {
    // on_hand is signed, so stock sold below zero reads as owed; a catalogue
    // cached before it existed falls back to available_stock.
    const source    = product?.on_hand ?? product?.available_stock;
    const available = Number(source);

    if (source == null || ! Number.isFinite(available)) return '';

    const units   = unitsOf(product);
    const base    = units.find((u) => u.is_base) ?? units.find((u) => Number(u.conversion_factor) === 1);
    const biggest = units
        .filter((u) => Number(u.conversion_factor) > 1)
        .sort((a, b) => Number(b.conversion_factor) - Number(a.conversion_factor))[0];

    if (! biggest || available <= 0) {
        return available < 0 ? `${available} (owed)` : String(available);
    }

    const factor = Number(biggest.conversion_factor);
    const whole  = Math.floor(available / factor);
    const rest   = available % factor;
    const baseName = base?.name ?? product.base_unit ?? 'Piece';

    const parts = [];
    if (whole > 0) parts.push(`${whole} ${plural(biggest.name, whole)}`);
    if (rest > 0) parts.push(`${rest} ${plural(baseName, rest)}`);

    return `${parts.join(' + ')} (${available})`;
}

/**
 * Base units this sale takes of a product beyond what the shelf holds, or 0.
 * Only meaningful where selling below zero is allowed — elsewhere the cart
 * never lets a line get that far.
 */
export function shortfallFor(cart = [], item) {
    const available = Number(item?.available_stock);

    if (item?.available_stock == null || ! Number.isFinite(available)) return 0;

    const onShelf = available + (Number(item.reserved) || 0);
    const inSale  = cart.reduce(
        (total, line) => (line.id === item.id ? total + (Number(line.qty) || 0) * (Number(line.factor) || 1) : total),
        0,
    );

    return Math.max(0, inSale - Math.max(0, onShelf));
}
