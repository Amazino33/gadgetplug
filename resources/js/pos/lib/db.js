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

export default db;
