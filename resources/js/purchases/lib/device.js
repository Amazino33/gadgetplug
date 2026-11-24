import { useEffect, useState } from 'react';

/**
 * A finger, not a mouse: a phone or tablet. Used to stop the terminal pulling
 * the caret back into the scan box after every action — on a phone that pops
 * the keyboard up over the very list being worked on.
 */
export function isTouch() {
    try {
        return window.matchMedia?.('(pointer: coarse)').matches ?? false;
    } catch {
        return false;
    }
}

/** Whether the camera can read barcodes here (Chrome on Android, mostly). */
export const canDetectBarcodes = () => typeof window !== 'undefined' && 'BarcodeDetector' in window;

/**
 * True from 768px up: wide enough for a table row per line. Below it, lines
 * are cards. Live, so turning a tablet round switches between them.
 */
export function useWide(query = '(min-width: 768px)') {
    const read = () => {
        try {
            return window.matchMedia ? window.matchMedia(query).matches : window.innerWidth >= 768;
        } catch {
            return true;
        }
    };

    const [wide, setWide] = useState(read);

    useEffect(() => {
        const mq = window.matchMedia?.(query);
        if (!mq) return undefined;

        const onChange = () => setWide(mq.matches);
        mq.addEventListener?.('change', onChange);

        return () => mq.removeEventListener?.('change', onChange);
    }, [query]);

    return wide;
}
