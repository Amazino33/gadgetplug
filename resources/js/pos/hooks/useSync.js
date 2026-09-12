import { useEffect, useRef } from 'react';
import { db } from '../lib/db';
import api from '../lib/api';
import { markSaleSynced } from '../lib/salesHistory';
import { markPickingPaymentSynced, pendingPickingPayments, prunePickingPayments } from '../lib/pickings';
import {
    applyServerShift, clearSyncFailure, markCloseSynced, markOpenSynced,
    noteSyncFailure, pendingShifts, STATUS_PENDING_REVIEW,
} from '../lib/shift';

/**
 * Background sync: every 30s, push any unsynced IndexedDB sales to the server.
 * A genuine network failure retries next cycle. A sale the server actually
 * rejected (insufficient stock, price below floor, etc.) is NOT retried
 * blindly forever — it's marked and reported via onStuckSalesChange so a
 * human can see it and fix the underlying cause.
 */
export function useSync(vendorId, onStuckSalesChange) {
    const timerRef = useRef(null);
    const catalogueTimerRef = useRef(null);

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
        await syncShifts();
        await reportStuck();
    };

    /**
     * The cashier's day: the float they opened with, and the counts they closed
     * with.
     *
     * Sent one shift at a time, each carrying the key it was created with, so a
     * request that went up but whose answer never came back is recognised on the
     * retry rather than opening a second day or posting a second count.
     *
     * The open is sent before the close, always: the server has nothing to
     * attach a count to otherwise. A shift that has not managed to open yet
     * therefore waits, rather than having its close rejected and marked refused.
     *
     * Sales go first (they are earlier in sync()), which matters: the server
     * computes what the drawer should hold from the sales it has, so a close
     * that overtook its own day's sales would be measured against a day that had
     * barely happened and would read as an enormous shortage.
     */
    const syncShifts = async () => {
        const pending = await pendingShifts();

        for (const shift of pending) {
            if (shift.open_synced !== 1) {
                const opened = await pushOpen(shift);

                if (!opened) continue;
            }

            if (shift.status === STATUS_PENDING_REVIEW && shift.close_synced !== 1) {
                await pushClose(shift);
            }
        }
    };

    const pushOpen = async (shift) => {
        try {
            const { data } = await api.post('/cash-up/open', {
                vendor_id: shift.vendor_id,
                opening_float: shift.opening_float,
                terminal_id: shift.terminal_id ?? undefined,
                idempotency_key: shift.open_key,
            });

            await markOpenSynced(shift.id, data?.session);
            await clearSyncFailure(shift.id);

            return true;
        } catch (e) {
            const status = e?.response?.status;

            // 409 means the server already has this day closed. Nothing this
            // device can do will change that, and retrying for ever would only
            // hide it — so the local row adopts the server's copy and stops.
            if (status === 409) {
                await markOpenSynced(shift.id, e.response?.data?.session);
                await clearSyncFailure(shift.id);

                return true;
            }

            // 422 is a refusal the till cannot fix by asking again — no branch
            // assigned, most likely. Marked so it stops, and left visible.
            if (status === 422) {
                await db.shifts.update(shift.id, {
                    open_synced: 1,
                    sync_status: 'rejected',
                    sync_message: e?.response?.data?.message ?? null,
                });

                return false;
            }

            // Network, or the server having a bad moment. Backs off rather than
            // asking again in thirty seconds for the rest of the afternoon.
            await noteSyncFailure(shift.id);

            return false;
        }
    };

    const pushClose = async (shift) => {
        if (!shift.server_id) return;

        try {
            const { data } = await api.post(`/cash-up/${shift.server_id}/close`, {
                vendor_id: shift.vendor_id,
                counted_cash: shift.counted_cash,
                counted_terminal: shift.counted_terminal,
                notes: shift.notes ?? undefined,
                idempotency_key: shift.close_key,
            });

            // The authoritative figures, which replace this device's provisional
            // ones on screen. The server saw sales this till never did.
            await applyServerShift(shift.id, data?.session, data?.breakdown ?? null);
            await markCloseSynced(shift.id);
            await clearSyncFailure(shift.id);
        } catch (e) {
            const status = e?.response?.status;

            // Already closed there. Whatever the server holds is the record, so
            // take it rather than keep offering a second count.
            if (status === 409) {
                await applyServerShift(shift.id, e.response?.data?.session);
                await markCloseSynced(shift.id);
                await clearSyncFailure(shift.id);

                return;
            }

            // A refused count is not something the till can put right by
            // sending it again — the cashier is shown it instead.
            if (status === 422 || status === 404) {
                await db.shifts.update(shift.id, {
                    sync_status: 'rejected',
                    sync_message: e?.response?.data?.message ?? null,
                });

                return;
            }

            await noteSyncFailure(shift.id);
        }
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

    /**
     * Pulls the branch catalogue down again.
     *
     * It was previously written once, at login, and never again — so a
     * product added or restocked during a shift did not exist as far as the
     * till was concerned until the cashier logged out and back in. Nobody
     * knows to do that, so the answer to "why can't I sell this?" was a
     * logout nobody thought to try.
     *
     * Replaced wholesale rather than merged: a product that has been
     * unpublished, moved to another branch or sold out has to leave the till
     * as surely as a new one has to arrive, and only the server knows which.
     * The swap happens in one transaction so a search running at that moment
     * cannot read a half-empty catalogue.
     */
    const refreshCatalogue = async () => {
        if (!navigator.onLine || !vendorId) return;

        try {
            const { data } = await api.get('/products', { params: { vendor_id: vendorId } });

            if (!Array.isArray(data) || data.length === 0) return;

            await db.transaction('rw', db.products, async () => {
                await db.products.clear();
                await db.products.bulkPut(data);
            });
        } catch {
            // Offline or refused — the catalogue already on the device stays
            // exactly as it is, which is what keeps the till sellable.
        }
    };

    useEffect(() => {
        sync();
        refreshCatalogue();

        timerRef.current = setInterval(sync, 30_000);
        // Far less often than the sale queue: this is a whole catalogue, and
        // the search box already asks the server directly on every search, so
        // this is about the till being usable offline with something current
        // rather than about finding a product right now.
        catalogueTimerRef.current = setInterval(refreshCatalogue, 10 * 60_000);

        const onOnline = () => { sync(); refreshCatalogue(); };
        window.addEventListener('online', onOnline);

        return () => {
            clearInterval(timerRef.current);
            clearInterval(catalogueTimerRef.current);
            window.removeEventListener('online', onOnline);
        };
    }, [vendorId]); // eslint-disable-line react-hooks/exhaustive-deps

    return { syncNow: sync, refreshCatalogue };
}
