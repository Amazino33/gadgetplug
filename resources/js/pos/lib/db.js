import Dexie from 'dexie';

// IndexedDB schema — offline-first store
export const db = new Dexie('GadgetPlugPOS');

db.version(1).stores({
    products:        '++id, barcode, sku, name',
    offlineSales:    '++id, offline_id, synced, completed_at',
    suspendedCarts:  'slot',
    settings:        'key',
});

export default db;
