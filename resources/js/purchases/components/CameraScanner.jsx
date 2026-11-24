import { useCallback, useEffect, useRef, useState } from 'react';
import { canDetectBarcodes } from '../lib/device';

const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'code_93', 'itf', 'codabar', 'qr_code'];

/** The same code seen again within this long is the same box, not another. */
const REPEAT_MS = 1800;

/**
 * Scan with the phone's camera — the till's approach (pos BarcodeScanner:
 * the browser's own BarcodeDetector, rear camera), but left running: a
 * delivery is unpacked one box after another, so each code read is added and
 * the camera keeps looking for the next, with a buzz to say it took.
 *
 * Where the browser cannot read barcodes (Safari, mostly), or the camera is
 * refused, the code can be typed instead — the box below the picture works in
 * every case.
 */
export default function CameraScanner({ onCode, onClose, last, count }) {
    const videoRef  = useRef(null);
    const streamRef = useRef(null);
    const timerRef  = useRef(null);
    const seenRef   = useRef({ code: null, at: 0 });
    // Read through a ref so a new callback from the parent never restarts
    // the camera mid-delivery.
    const onCodeRef = useRef(onCode);
    onCodeRef.current = onCode;
    const [error, setError]   = useState(canDetectBarcodes() ? '' : 'This browser cannot read barcodes from the camera. Type the code below instead.');
    const [manual, setManual] = useState('');

    const stop = useCallback(() => {
        clearTimeout(timerRef.current);
        streamRef.current?.getTracks().forEach((t) => t.stop());
        streamRef.current = null;
    }, []);

    useEffect(() => {
        if (!canDetectBarcodes() || !navigator.mediaDevices?.getUserMedia) return undefined;

        let cancelled = false;

        (async () => {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: false,
                });

                if (cancelled) { stream.getTracks().forEach((t) => t.stop()); return; }

                streamRef.current = stream;
                videoRef.current.srcObject = stream;
                await videoRef.current.play();

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
                            const code = hit?.rawValue?.trim();
                            const now = Date.now();

                            if (code && !(code === seenRef.current.code && now - seenRef.current.at < REPEAT_MS)) {
                                seenRef.current = { code, at: now };
                                navigator.vibrate?.(60);
                                onCodeRef.current(code);
                            } else if (code) {
                                seenRef.current.at = now; // still the same box in view
                            }
                        }
                    } catch { /* a frame it could not read */ }

                    timerRef.current = setTimeout(tick, 200);
                };

                tick();
            } catch (e) {
                setError(e?.name === 'NotAllowedError'
                    ? 'Camera access was refused. Allow the camera for this site, or type the code below.'
                    : 'The camera could not be started. Type the code below instead.');
            }
        })();

        return () => { cancelled = true; stop(); };
    }, [stop]);

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

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-black" role="dialog" aria-label="Scan with camera">
            <div className="flex shrink-0 items-center justify-between bg-orange-600 px-4 py-3 text-white">
                <div>
                    <p className="text-sm font-extrabold">Scan with camera</p>
                    <p className="text-[11px] opacity-80">{count} line{count === 1 ? '' : 's'} on this purchase</p>
                </div>
                <button type="button" onClick={onClose} className="rounded-lg bg-white/20 px-4 py-2 text-sm font-bold">Done</button>
            </div>

            <div className="relative min-h-0 flex-1 overflow-hidden">
                <video ref={videoRef} playsInline muted className="h-full w-full object-cover" />
                {!error && (
                    <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                        <div className="h-40 w-72 max-w-[80%] rounded-2xl border-4 border-orange-400/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]" />
                    </div>
                )}
                {error && (
                    <div className="absolute inset-0 flex items-center justify-center p-6 text-center text-sm font-semibold text-white">{error}</div>
                )}
                {last && (
                    <div key={last.at} role="status" className="absolute left-1/2 top-4 max-w-[90%] -translate-x-1/2 truncate rounded-full bg-green-600 px-4 py-2 text-sm font-bold text-white shadow-lg">
                        ✓ {last.name}
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
                    className="min-w-0 flex-1 rounded-xl border border-gray-700 bg-gray-800 px-3 py-3 text-base text-white outline-none focus:border-orange-500"
                />
                <button type="submit" className="rounded-xl bg-orange-600 px-5 text-sm font-bold text-white">Add</button>
            </form>
        </div>
    );
}
