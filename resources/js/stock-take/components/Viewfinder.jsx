import { useEffect, useRef, useState } from 'react';
import { CAMERA_CONSTRAINTS, refocus, tuneCamera, videoTrack } from '../../purchases/lib/cameraFocus';
import { good, setSound, soundOn } from '../lib/feedback';

const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'code_93', 'itf', 'codabar', 'qr_code'];

/** How often the picture is read. About seven a second: quick, and the phone stays cool. */
const TICK_MS = 150;

/**
 * The camera at the top of the counting screen, on for as long as the screen
 * is open: started once, never stopped and restarted between products
 * (`getUserMedia` is called once).
 *
 * Reading is paused, not the camera: while `readable()` says no (the counter
 * is typing, a form is open), what the camera sees is not acted on. It is
 * still watched, so a barcode that stayed in view the whole time does not
 * fire the moment reading resumes.
 *
 * The same code is reported once while it stays in view, and again only
 * after it has been out of view for `repeatMs` (1.5 s, the camera's double
 * reads; shorter in tap-to-count).
 *
 * `mode` comes from cameraMode(): 'native' uses the browser's BarcodeDetector;
 * 'zxing' downloads ZXing (phones without one, i.e. iPhones).
 */
export default function Viewfinder({ mode, onCode, readable, repeatMs = 1500, onStatus }) {
    const videoRef    = useRef(null);
    const onCodeRef   = useRef(onCode);
    const readableRef = useRef(readable);
    const repeatRef   = useRef(repeatMs);
    const statusRef   = useRef(onStatus);
    onCodeRef.current   = onCode;
    readableRef.current = readable;
    repeatRef.current   = repeatMs;
    statusRef.current   = onStatus;

    const [error, setError] = useState('');
    const [live, setLive]   = useState(false);
    // Torch (B7): null where the camera has none, so the button stays hidden.
    const [torch, setTorch] = useState(null);
    const [sound, setSoundState] = useState(soundOn);

    const track = () => videoRef.current?.srcObject?.getVideoTracks?.()[0] ?? null;

    const toggleTorch = async () => {
        const t = track();
        if (!t) return;
        try {
            await t.applyConstraints({ advanced: [{ torch: !torch }] });
            setTorch(!torch);
        } catch {
            setTorch(null);
        }
    };

    const toggleSound = () => {
        setSound(!sound);
        setSoundState(!sound);
        if (!sound) good(); // turned on: a beep, so they know they will hear it
    };

    useEffect(() => {
        let cancelled = false;
        let timer = null;
        let stream = null;
        let controls = null;
        const seen = { code: null, at: 0 };

        const canRead = () => readableRef.current?.() !== false && document.visibilityState !== 'hidden';

        /** A code in the picture: acted on once, unless it is the same box still in view. */
        const report = (raw) => {
            const code = String(raw ?? '').trim();
            if (!code) return;

            const now = performance.now();

            // Paused: the code already acted on is still watched (so it doesn't
            // fire when reading resumes); a new one is left to be read then.
            if (!canRead()) {
                if (code === seen.code) seen.at = now;
                return;
            }

            if (code === seen.code && now - seen.at < repeatRef.current) {
                seen.at = now;
                return;
            }

            seen.code = code;
            seen.at = now;
            onCodeRef.current?.(code, now);
        };

        const started = () => {
            if (cancelled) return;
            setLive(true);
            statusRef.current?.('live');
            tuneCamera(videoTrack(videoRef.current));
            try {
                const caps = videoRef.current?.srcObject?.getVideoTracks?.()[0]?.getCapabilities?.();
                if (caps?.torch) setTorch(false);
            } catch { /* no torch */ }
        };

        const failed = (e) => {
            if (cancelled) return;
            setError(e?.name === 'NotAllowedError'
                ? 'Camera access was refused. Allow the camera for this site, or type the code below.'
                : 'The camera could not be started. Type the code below instead.');
            statusRef.current?.('error');
        };

        const constraints = CAMERA_CONSTRAINTS;

        const native = async () => {
            stream = await navigator.mediaDevices.getUserMedia(constraints);
            if (cancelled) return;

            const video = videoRef.current;
            video.srcObject = stream;
            await video.play?.()?.catch?.(() => {});
            started();

            const supported = await window.BarcodeDetector.getSupportedFormats?.().catch(() => null);
            const detector = new window.BarcodeDetector({
                formats: supported ? FORMATS.filter((f) => supported.includes(f)) : FORMATS,
            });

            const read = async () => {
                if (cancelled) return;
                if (document.visibilityState !== 'hidden' && videoRef.current?.readyState >= 2) {
                    try {
                        const [hit] = await detector.detect(videoRef.current);
                        if (!cancelled) report(hit?.rawValue);
                    } catch { /* a frame it could not read */ }
                }
                timer = setTimeout(read, TICK_MS);
            };

            read();
        };

        const zxing = async () => {
            const { BrowserMultiFormatReader } = await import('@zxing/browser');
            if (cancelled) return;

            const reader = new BrowserMultiFormatReader(undefined, { delayBetweenScanAttempts: TICK_MS });
            controls = await reader.decodeFromConstraints(constraints, videoRef.current, (result) => {
                if (result && !cancelled) report(result.getText());
            });

            if (cancelled) { controls.stop(); return; }
            started();
        };

        (mode === 'native' ? native() : zxing()).catch(failed);

        return () => {
            cancelled = true;
            clearTimeout(timer);
            controls?.stop();
            stream?.getTracks().forEach((t) => t.stop());
        };
    }, [mode]);

    return (
        <div className="relative h-full w-full overflow-hidden bg-black">
            <video ref={videoRef} playsInline muted aria-label="Camera" onClick={() => refocus(videoTrack(videoRef.current))} className="h-full w-full object-cover" />
            {!error && (
                <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div className="relative h-[min(7rem,72%)] w-[68%] max-w-72 rounded-2xl border-[3px] border-teal-300/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.3)]">
                        {live && <div className="st-scanline absolute inset-x-2 h-0.5 rounded bg-red-500/90" />}
                    </div>
                </div>
            )}
            <div className="absolute right-2 top-1/2 flex -translate-y-1/2 flex-col gap-1.5">
                {torch !== null && (
                    <button type="button" onClick={toggleTorch} aria-pressed={torch} aria-label={torch ? 'Torch off' : 'Torch on'}
                        className={`flex h-10 w-10 items-center justify-center rounded-full text-xl shadow ${torch ? 'bg-yellow-300 text-black' : 'bg-black/55 text-white'}`}>
                        🔦
                    </button>
                )}
                <button type="button" onClick={toggleSound} aria-pressed={sound} aria-label={sound ? 'Sound off' : 'Sound on'}
                    className="flex h-10 w-10 items-center justify-center rounded-full bg-black/55 text-xl text-white shadow">
                    {sound ? '🔊' : '🔇'}
                </button>
            </div>
            {!live && !error && (
                <p className="absolute inset-x-0 top-3 text-center text-xs font-semibold text-white/80">Starting the camera…</p>
            )}
            {error && (
                <p className="absolute inset-0 flex items-center justify-center p-5 text-center text-sm font-semibold text-white">{error}</p>
            )}
        </div>
    );
}
