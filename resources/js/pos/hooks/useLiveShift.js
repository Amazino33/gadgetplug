import { useEffect, useState } from 'react';
import { liveQuery } from 'dexie';
import { businessDate, shiftFor } from '../lib/shift';

/**
 * This cashier's day, kept current as the background sync rewrites it.
 *
 * Without this the closed-shift screen would hold whatever figures it was
 * rendered with: a cashier who finished offline would be looking at the till's
 * provisional arithmetic, and it would stay provisional on screen long after
 * the server had answered — until somebody happened to reload the app, which
 * nobody does at a counter.
 *
 * Dexie's own liveQuery rather than a poll or a new dependency: the sync writes
 * to the table, the table tells us, and there is nothing to keep in step.
 */
export function useLiveShift(cashierId) {
    const [shift, setShift] = useState(null);
    // Distinguishes "no shift today" from "we have not looked yet". Rendering
    // the float screen during the gap would ask a cashier mid-shift to open a
    // second one.
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        if (!cashierId) {
            setShift(null);
            setLoading(false);

            return;
        }

        setLoading(true);

        const subscription = liveQuery(() => shiftFor(cashierId, businessDate())).subscribe({
            next: (row) => { setShift(row ?? null); setLoading(false); },
            // A failed read must not leave the till stuck on a loading screen
            // for ever — better to show the start-of-day screen than nothing.
            error: () => setLoading(false),
        });

        return () => subscription.unsubscribe();
    }, [cashierId]);

    return { shift, loading, setShift };
}
