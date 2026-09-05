import { useEffect, useRef } from 'react';
import { db } from '../lib/db';
import api from '../lib/api';
import { markSaleSynced } from '../lib/salesHistory';
import { markPickingPaymentSynced, pendingPickingPayments, prunePickingPayments } from '../lib/pickings';

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
                        // The local history copy still knows this sale only by
                        // its offline id. Without the server id its receipt can
                        // never be fetched, and it would show twice once the
                        // server's own copy arrived beside it.
                        await markSaleSynced(record.offline_id, {
                            id: result.id ?? null,
                            reference: result.reference ?? null,
                        });
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

        await syncPickingPayments();
        await reportStuck();
    };

    /**
     * Money taken from pickers while the till was offline.
     *
     * Sent one at a time and carrying its own offline id: the server answers a
     * repeated id as a duplicate rather than an error, so a payment that went up
     * but whose answer never came back is settled once and marked here on the
     * retry, instead of being applied twice or retried for ever.
     */
    const syncPickingPayments = async () => {
        const pending = await pendingPickingPayments();

        for (const payment of pending) {
            try {
                const { data } = await api.post('/pickings/payment', {
                    vendor_id: payment.vendor_id,
                    picker_id: payment.picker_id,
                    amount: payment.amount,
                    item_ids: payment.item_ids,
                    payment_method: payment.payment_method ?? 'cash',
                    reference: payment.offline_id,
                });

                await markPickingPaymentSynced(payment.offline_id, data);
            } catch (e) {
                // A refusal is final — the money was understood and could not be
                // applied, so retrying forever would only hide it. Anything else
                // is a network problem and goes again next cycle.
                if (e?.response?.status === 422 || e?.response?.status === 404) {
                    await db.pickingPayments
                        .where('offline_id').equals(payment.offline_id)
                        .modify({ synced: 1, sync_status: 'rejected', sync_message: e?.response?.data?.message ?? null });
                }
            }
        }

        await prunePickingPayments();
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
