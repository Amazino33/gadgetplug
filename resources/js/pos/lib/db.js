import Dexie from 'dexie';

// IndexedDB schema — offline-first store
export const db = new Dexie('GadgetPlugPOS');

db.version(1).stores({
    products:        '++id, barcode, sku, name',
    offlineSales:    '++id, offline_id, synced, completed_at',
    suspendedCarts:  'slot',
    settings:        'key',
});

// v2 adds the till's own record of what it sold.
//
// offlineSales is a QUEUE, not a history: a sale lands there only when the
// network failed, and it is marked synced and never read for display again. So
// a cashier who traded all morning offline could not see any of it once it had
// gone up — the history screen asks the server, and the server is exactly what
// they do not have.
//
// Indexed on cashier_id because this table deliberately survives logout: a
// cashier returning to a shared till gets their own history back, and reads
// filter on the signed-in cashier so they never see the previous one's takings.
// reference and offline_id are indexed to reconcile a queued sale with its
// server identity once it syncs.
db.version(2).stores({
    sales: '++id, cashier_id, completed_at, reference, offline_id, server_id',
});

// v3 adds vendor pickings: the traders holding the vendor's goods, and the
// money they bring back for them.
//
// pickingPayments is a QUEUE, like offlineSales. It holds only what the cashier
// did — this picker paid this much against these lines — and never what that
// settles. The server works that out on arrival, against live prices and
// whatever is still outstanding, so two tills that both took money offline
// cannot double-settle: whoever syncs second simply finds less owing.
//
// pickingCache is the last list the server gave us, kept so a cashier with no
// connection can still see who is holding what and take money for it. It goes
// stale the moment somebody else takes a payment, which is exactly why the till
// is not allowed to decide what a payment settles.
db.version(3).stores({
    pickingPayments: '++id, offline_id, synced, created_at',
    pickingCache:    'vendor_id',
});

export default db;
