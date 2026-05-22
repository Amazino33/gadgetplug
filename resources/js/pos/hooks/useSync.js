import { useEffect, useRef } from 'react';
import { db } from '../lib/db';
import api from '../lib/api';

/**
 * Background sync: every 30s, push any unsynced IndexedDB sales to the server.
 * Silently ignores failures — they'll retry next cycle.
 */
export function useSync(vendorId) {
    const timerRef = useRef(null);

    const sync = async () => {
        if (!navigator.onLine || !vendorId) return;

        const unsyncedSales = await db.offlineSales
            .where('synced').equals(0)
            .toArray();

        if (unsyncedSales.length === 0) return;

        try {
            const { data } = await api.post('/sync', {
                vendor_id: vendorId,
                sales: unsyncedSales,
            });

            for (const result of data.results) {
                if (result.status === 'synced' || result.status === 'duplicate') {
                    const record = unsyncedSales.find((s) => s.offline_id === result.offline_id);
                    if (record) await db.offlineSales.update(record.id, { synced: 1 });
                }
            }
        } catch {
            // Network error — retry next cycle
        }
    };

    useEffect(() => {
        sync();
        timerRef.current = setInterval(sync, 30_000);
        window.addEventListener('online', sync);

        return () => {
            clearInterval(timerRef.current);
            window.removeEventListener('online', sync);
        };
    }, [vendorId]); // eslint-disable-line react-hooks/exhaustive-deps

    return { syncNow: sync };
}
