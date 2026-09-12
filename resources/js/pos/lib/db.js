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

// v4 caches the customer list, for the same reason products are cached: the
// till has to keep working with no signal.
//
// Selling on credit REQUIRES a named customer — an anonymous debt can never be
// collected — but searching for one hit the network, so the one sale that most
// needs a customer attached was the one that could not have it offline. Names
// and phones only; balances are deliberately not cached, because a stale figure
// about money owed is worse than no figure at all.
db.version(4).stores({
    customers: 'id, vendor_id, name, phone',
});

// v5 adds the cashier's day: the shift they opened, and the refunds they paid
// back out of it.
//
// shifts is both the local record and its own queue, the way pickingPayments
// is — one row per cashier per trading day, carrying its own sync flags for the
// open and the close separately. A shift has exactly one of each (the server
// enforces it on a unique key), so a separate queue table would only be a
// second place for the same row to disagree with itself.
//
// refunds exists because the till kept no record of one. ReturnModal posted to
// the server and wrote nothing locally, so a day's provisional cash figure
// counted every refund as money still in the drawer and would have told a
// cashier they were short by exactly what they had handed back. Only what the
// drawer needs: how much, on which tender, by whom, when.
db.version(5).stores({
    shifts:  '++id, business_date, cashier_id, status, [cashier_id+business_date]',
    refunds: '++id, cashier_id, created_at, offline_id',
});

export default db;
