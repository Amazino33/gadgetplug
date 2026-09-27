/**
 * The queued sales the signed-in cashier may send up.
 *
 * Only their own. The queue lives on the device, not the login, so it can hold
 * sales rung by whoever used this till before — and the server records a synced
 * sale against the login that sent it. Sending someone else's would put their
 * takings in this cashier's drawer; theirs wait until they sign in again.
 *
 * A sale queued before the till recorded who rang it has no cashier_id. Those
 * go up with whoever is signed in, as they always did — there is nobody else
 * to wait for.
 *
 * Sales the server already refused stay out: they need a person, not a retry.
 */
export function salesToSync(unsynced, cashierId) {
    return unsynced.filter((sale) =>
        sale.sync_status !== 'rejected'
        && sale.sync_status !== 'error'
        && (sale.cashier_id == null || sale.cashier_id === cashierId)
    );
}
