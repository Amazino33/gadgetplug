import { useEffect } from 'react';

/**
 * Keep the screen on while counting (B7). The browser lets go of the lock
 * whenever the app is in the background, so it is asked for again on return.
 * Where there is no Wake Lock API (older iPhones), nothing happens and the
 * phone's own screen timeout applies.
 */
export function useWakeLock(active) {
    useEffect(() => {
        if (!active || !navigator.wakeLock?.request) return undefined;

        let lock = null;
        let gone = false;

        const take = async () => {
            if (gone || document.visibilityState !== 'visible') return;
            try {
                lock = await navigator.wakeLock.request('screen');
            } catch { /* refused (battery saver): the screen just times out */ }
        };

        const onVisible = () => { if (document.visibilityState === 'visible') take(); };

        take();
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            gone = true;
            document.removeEventListener('visibilitychange', onVisible);
            lock?.release?.().catch?.(() => {});
        };
    }, [active]);
}
