/**
 * What the server told this till about its vendor, kept for when it cannot ask.
 *
 * Written once at login (see PosAuthController) and read from localStorage
 * rather than fetched, because the moment it is most needed — printing a
 * receipt for a sale rung with no signal — is exactly when a fetch cannot
 * answer. It carries the VAT settings the till already used for its own
 * arithmetic, plus the receipt layout and the branch's details, which the
 * browser otherwise has no way of knowing.
 */
export function vendorSettings() {
    try {
        return JSON.parse(localStorage.getItem('pos_vendor_settings') ?? '{}') ?? {};
    } catch {
        // Corrupt or cleared storage must not stop a receipt printing. Every
        // consumer treats a missing field as "leave that line off".
        return {};
    }
}

/** The signed-in cashier's name, for the Cashier line. */
export function cashierName() {
    try {
        return JSON.parse(localStorage.getItem('pos_user') ?? '{}')?.name ?? null;
    } catch {
        return null;
    }
}

export default vendorSettings;
