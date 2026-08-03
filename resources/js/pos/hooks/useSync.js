import { useEffect, useRef } from 'react';
import { db } from '../lib/db';
import api from '../lib/api';

/**
 * Background sync: every 30s, push any unsynced IndexedDB sales to the server.
 * A genuine network failure retries next cycle. A sale the server actually
 * rejected (insufficient stock, price below floor, etc.) is NOT retried
 * blindly forever — it's marked and reported via onStuckSalesChange so a
 * human can see it and fix the underlying cause.
 */
export function useSync(vendorId, onStuckSalesChange) {
    const timerRef = useRef(null);

    const reportStuck = async () => {
        if (!onStuckSalesChange) return;
        const all = await db.offlineSales.where('synced').equals(0).toArray();
        onStuckSalesChange(all.filter((s) => s.sync_status === 'rejected' || s.sync_status === 'error'));
    };

    const sync = async () => {
        if (!navigator.onLine || !vendorId) return;

        const unsyncedSales = await db.offlineSales
            .where('synced').equals(0)
            .toArray();

        const pendingSales = unsyncedSales.filter(
            (s) => s.sync_status !== 'rejected' && s.sync_status !== 'error'
        );

        if (pendingSales.length > 0) {
            try {
                const { data } = await api.post('/sync', {
                    vendor_id: vendorId,
                    sales: pendingSales,
                });

                for (const result of data.results) {
                    const record = pendingSales.find((s) => s.offline_id === result.offline_id);
                    if (!record) continue;

                    if (result.status === 'synced' || result.status === 'duplicate') {
                        await db.offlineSales.update(record.id, { synced: 1 });
                    } else if (result.status === 'rejected' || result.status === 'error') {
                        await db.offlineSales.update(record.id, {
                            sync_status: result.status,
                            sync_error: result.message,
                        });
                    }
                }
            } catch {
                // Network error — retry next cycle
            }
        }

        await reportStuck();
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
