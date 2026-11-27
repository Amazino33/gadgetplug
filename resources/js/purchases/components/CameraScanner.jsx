import { useCallback, useEffect, useRef, useState } from 'react';
import { CAMERA_CONSTRAINTS, refocus, tuneCamera, videoTrack } from '../lib/cameraFocus';
import { canDetectBarcodes } from '../lib/device';

const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'code_93', 'itf', 'codabar', 'qr_code'];

/** The same code seen again within this long is the same box, not another. */
const REPEAT_MS = 1800;

const CANNOT_READ = 'This browser cannot read barcodes from the camera. Type the code below instead.';

/** Colours per screen: orange for Purchases, teal for Stock Take. */
const TONES = {
    orange: { bar: 'bg-orange-600', frame: 'border-orange-400/90', focus: 'focus:border-orange-500', button: 'bg-orange-600' },
    teal:   { bar: 'bg-teal-700',   frame: 'border-teal-300/90',   focus: 'focus:border-teal-500',   button: 'bg-teal-700' },
};

/**
 * Scan with the phone's camera, left running: each code read is reported and
 * the camera keeps looking for the next, with a buzz to say it took.
 *
 * Two ways to read a code from the picture:
 * - the browser's own BarcodeDetector (Chrome on Android) — the till's way;
 * - otherwise ZXing (@zxing/browser), loaded only when needed, which is what
 *   lets an iPhone scan at all. Safari has no BarcodeDetector.
 *
 * Where there is no camera, it is refused, or neither can start, the code can
 * be typed instead — the box below the picture works in every case.
 *
 * Shared by Purchases and Stock Take. Purchases passes only `count`; the
 * other props let another screen word the header its own way.
 */
export default function CameraScanner({ onCode, onClose, last, count, title = 'Scan with camera', subtitle, tone = 'orange', doneLabel = 'Done', manualLabel = 'Add' }) {
    const videoRef   = useRef(null);
    const streamRef  = useRef(null);
    const timerRef   = useRef(null);
    const zxingRef   = useRef(null);
    const seenRef    = useRef({ code: null, at: 0 });
    // Read through a ref so a new callback from the parent never restarts
    // the camera mid-scan.
    const onCodeRef = useRef(onCode);
    onCodeRef.current = onCode;
    const [error, setError]   = useState(navigator.mediaDevices?.getUserMedia ? '' : CANNOT_READ);
    const [manual, setManual] = useState('');
    const colours = TONES[tone] ?? TONES.orange;

    const stop = useCallback(() => {
        clearTimeout(timerRef.current);
        zxingRef.current?.stop();
        zxingRef.current = null;
        streamRef.current?.getTracks().forEach((t) => t.stop());
        streamRef.current = null;
    }, []);

    /** One code read from the picture, unless it is the same box still in view. */
    const report = useCallback((raw) => {
        const code = raw?.trim();
        if (!code) return;

        const now = Date.now();
        if (code === seenRef.current.code && now - seenRef.current.at < REPEAT_MS) {
            seenRef.current.at = now;
            return;
        }

        seenRef.current = { code, at: now };
        navigator.vibrate?.(60);
        onCodeRef.current(code);
    }, []);

    useEffect(() => {
        if (!navigator.mediaDevices?.getUserMedia) return undefined;

        let cancelled = false;
        const constraints = CAMERA_CONSTRAINTS;

        const cameraError = (e) => setError(e?.name === 'NotAllowedError'
            ? 'Camera access was refused. Allow the camera for this site, or type the code below.'
            : 'The camera could not be started. Type the code below instead.');

        const native = async () => {
            const stream = await navigator.mediaDevices.getUserMedia(constraints);

            if (cancelled) { stream.getTracks().forEach((t) => t.stop()); return; }

            streamRef.current = stream;
            videoRef.current.srcObject = stream;
            await videoRef.current.play();
            tuneCamera(stream.getVideoTracks()[0]);

            const supported = await window.BarcodeDetector.getSupportedFormats?.().catch(() => null);
            const detector = new window.BarcodeDetector({
                formats: supported ? FORMATS.filter((f) => supported.includes(f)) : FORMATS,
            });

            // A few reads a second is plenty and leaves the phone cool.
            const tick = async () => {
                if (cancelled) return;

                try {
                    if (videoRef.current?.readyState >= 2) {
                        const [hit] = await detector.detect(videoRef.current);
                        report(hit?.rawValue);
                    }
                } catch { /* a frame it could not read */ }

                timerRef.current = setTimeout(tick, 200);
            };

            tick();
        };

        const zxing = async () => {
            const { BrowserMultiFormatReader } = await import('@zxing/browser');
            if (cancelled) return;

            const reader = new BrowserMultiFormatReader(undefined, { delayBetweenScanAttempts: 200 });
            const controls = await reader.decodeFromConstraints(constraints, videoRef.current, (result) => {
                if (result && !cancelled) report(result.getText());
            });

            if (cancelled) { controls.stop(); return; }
            zxingRef.current = controls;
            tuneCamera(videoTrack(videoRef.current));
        };

        (canDetectBarcodes() ? native() : zxing()).catch((e) => {
            if (cancelled) return;
            // The ZXing bundle itself failed to load (offline, old browser).
            if (!canDetectBarcodes() && e?.name !== 'NotAllowedError' && e?.name !== 'NotFoundError') {
                setError(CANNOT_READ);
                return;
            }
            cameraError(e);
        });

        return () => { cancelled = true; stop(); };
    }, [stop, report]);

    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const submitManual = (e) => {
        e.preventDefault();
        const code = manual.trim();
        if (!code) return;
        onCode(code);
        setManual('');
    };

    const line = subtitle ?? (count !== undefined ? `${count} line${count === 1 ? '' : 's'} on this purchase` : null);

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-black" role="dialog" aria-label="Scan with camera">
            <div className={`flex shrink-0 items-center justify-between px-4 py-3 text-white ${colours.bar}`}>
                <div className="min-w-0">
                    <p className="truncate text-sm font-extrabold">{title}</p>
                    {line && <p className="truncate text-[11px] opacity-80">{line}</p>}
                </div>
                <button type="button" onClick={onClose} className="shrink-0 rounded-lg bg-white/20 px-4 py-2 text-sm font-bold">{doneLabel}</button>
            </div>

            <div className="relative min-h-0 flex-1 overflow-hidden">
                <video ref={videoRef} playsInline muted onClick={() => refocus(videoTrack(videoRef.current))} className="h-full w-full object-cover" />
                {!error && (
                    <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                        <div className={`h-40 w-72 max-w-[80%] rounded-2xl border-4 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)] ${colours.frame}`} />
                    </div>
                )}
                {error && (
                    <div className="absolute inset-0 flex items-center justify-center p-6 text-center text-sm font-semibold text-white">{error}</div>
                )}
                {last && (
                    <div key={last.at} role="status" className={`absolute left-1/2 top-4 max-w-[90%] -translate-x-1/2 truncate rounded-full px-4 py-2 text-sm font-bold text-white shadow-lg ${last.tone === 'warn' ? 'bg-amber-600' : last.tone === 'error' ? 'bg-red-600' : 'bg-green-600'}`}>
                        {last.tone === 'warn' || last.tone === 'error' ? '' : '✓ '}{last.name}
                    </div>
                )}
            </div>

            <form onSubmit={submitManual} className="flex shrink-0 gap-2 bg-gray-900 p-3">
                <input
                    value={manual}
                    onChange={(e) => setManual(e.target.value)}
                    inputMode="numeric"
                    placeholder="Or type the barcode"
                    aria-label="Type a barcode"
                    className={`min-w-0 flex-1 rounded-xl border border-gray-700 bg-gray-800 px-3 py-3 text-base text-white outline-none ${colours.focus}`}
                />
                <button type="submit" className={`rounded-xl px-5 text-sm font-bold text-white ${colours.button}`}>{manualLabel}</button>
            </form>
        </div>
    );
}
