import { isTouch } from '../../purchases/lib/device';

/**
 * How this device reads barcodes from its camera, if at all:
 *
 * - 'native': the browser's own BarcodeDetector (Chrome on Android).
 * - 'zxing': a phone or tablet without one (an iPhone): ZXing, downloaded
 *   only then.
 * - 'none': no camera API, or a desktop without BarcodeDetector. A desktop
 *   counts with a hand-held scanner, so the ZXing download (114 KB gzipped)
 *   never happens there.
 */
export function cameraMode(win = typeof window === 'undefined' ? undefined : window, touch = isTouch()) {
    if (!win?.navigator?.mediaDevices?.getUserMedia) return 'none';
    if ('BarcodeDetector' in win) return 'native';

    return touch ? 'zxing' : 'none';
}

/** Whether to start the camera by itself: on a phone or tablet, yes; on a desktop, only when asked. */
export const cameraByDefault = (touch = isTouch()) => touch;
