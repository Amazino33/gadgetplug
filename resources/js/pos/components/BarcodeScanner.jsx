import { useState, useRef, useEffect, useCallback } from 'react';
import { db } from '../lib/db';
import api from '../lib/api';

// Camera-based barcode scan — mirrors the same interaction pattern as the
// admin panel's barcode-scanner Blade component (BarcodeDetector API with a
// manual-entry fallback for unsupported browsers), reimplemented in React
// since POS is a separate app that can't share a Blade component.
export default function BarcodeScanner({ vendorId, onProductFound }) {
    const [open, setOpen]         = useState(false);
    const [scanning, setScanning] = useState(false);
    const [error, setError]       = useState('');
    const [notFound, setNotFound] = useState('');
    const videoRef                = useRef(null);
    const manualInputRef          = useRef(null);
    const streamRef               = useRef(null);
    const detectorRef             = useRef(null);
    const animFrameRef            = useRef(null);

    const supported = 'BarcodeDetector' in window;

    const stopCamera = useCallback(() => {
        setScanning(false);
        if (animFrameRef.current) cancelAnimationFrame(animFrameRef.current);
        if (streamRef.current) streamRef.current.getTracks().forEach((t) => t.stop());
        streamRef.current = null;
        if (videoRef.current) videoRef.current.srcObject = null;
    }, []);

    const lookup = useCallback(async (barcode) => {
        setError('');
        setNotFound('');

        const local = await db.products.where('barcode').equals(barcode).first();
        if (local) {
            onProductFound(local);
            setOpen(false);
            return;
        }

        if (navigator.onLine) {
            try {
                const { data } = await api.get('/products/search', { params: { vendor_id: vendorId, q: barcode } });
                const exact = data.find((p) => p.barcode === barcode);
                if (exact) {
                    onProductFound(exact);
                    setOpen(false);
                    return;
                }
            } catch { /* stay offline-friendly, fall through to not-found */ }
        }

        setNotFound(`No product found for "${barcode}".`);
    }, [vendorId, onProductFound]);

    const scanLoop = useCallback(() => {
        animFrameRef.current = requestAnimationFrame(async () => {
            const video = videoRef.current;
            if (!video || video.readyState !== video.HAVE_ENOUGH_DATA) {
                if (streamRef.current) scanLoop();
                return;
            }
            try {
                const barcodes = await detectorRef.current.detect(video);
                if (barcodes.length > 0) {
                    stopCamera();
                    lookup(barcodes[0].rawValue);
                    return;
                }
            } catch { /* keep scanning */ }
            if (streamRef.current) scanLoop();
        });
    }, [lookup, stopCamera]);

    const startCamera = useCallback(async () => {
        setError('');
        setNotFound('');
        setScanning(true);
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
            });
            streamRef.current = stream;
            if (videoRef.current) {
                videoRef.current.srcObject = stream;
                await videoRef.current.play();
            }
            if (supported) {
                detectorRef.current = new window.BarcodeDetector({
                    formats: ['ean_13', 'ean_8', 'qr_code', 'code_128', 'code_39', 'upc_a', 'upc_e'],
                });
                scanLoop();
            }
        } catch {
            setError('Camera access denied. Please allow camera permissions and try again.');
            setScanning(false);
        }
    }, [supported, scanLoop]);

    useEffect(() => {
        if (open) {
            if (supported) startCamera();
        } else {
            stopCamera();
        }
        return () => stopCamera();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submitManual = () => {
        const val = manualInputRef.current?.value?.trim();
        if (!val) return;
        lookup(val);
        manualInputRef.current.value = '';
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                title="Scan barcode"
                aria-label="Scan barcode"
                className="flex items-center justify-center w-10 h-10 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-500 dark:text-gray-400 hover:border-[#068B03] hover:text-[#068B03] transition-colors shrink-0"
            >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                        d="M3 4.5h2.25M3 9h2.25M3 13.5h2.25M3 18h2.25M7.5 4.5H9M7.5 9H9M7.5 13.5H9M7.5 18H9M12 4.5h2.25M12 9h2.25M12 13.5h2.25M12 18h2.25M16.5 4.5H18M16.5 9H18M16.5 13.5H18M16.5 18H18M21 4.5h-2.25M21 9h-2.25M21 13.5h-2.25M21 18h-2.25" />
                </svg>
            </button>

            {open && (
                <div
                    className="fixed inset-0 z-[200] flex items-end sm:items-center justify-center bg-black/70 p-4"
                    onClick={(e) => { if (e.target === e.currentTarget) { stopCamera(); setOpen(false); } }}
                >
                    <div className="w-full max-w-sm bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden">
                        <div className="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                            <span className="font-semibold text-sm text-gray-800 dark:text-gray-100">Scan Barcode</span>
                            <button onClick={() => { stopCamera(); setOpen(false); }}
                                className="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-500">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {supported && (
                            <div className="relative bg-black aspect-video">
                                <video ref={videoRef} className="w-full h-full object-cover" muted playsInline />
                                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                                    <div className="w-56 h-32 border-2 border-[#068B03]/70 rounded-xl opacity-80" />
                                </div>
                            </div>
                        )}

                        {error && (
                            <div className="px-4 py-3 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-700">
                                <p className="text-red-600 dark:text-red-400 text-sm">{error}</p>
                            </div>
                        )}

                        {notFound && (
                            <div className="px-4 py-3 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-700">
                                <p className="text-amber-700 dark:text-amber-400 text-sm">{notFound}</p>
                            </div>
                        )}

                        <div className="px-4 py-4">
                            <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">
                                {supported ? 'Or type / paste the barcode manually:' : 'Camera scanning not supported in this browser. Type the barcode:'}
                            </p>
                            <div className="flex gap-2">
                                <input
                                    ref={manualInputRef}
                                    type="text"
                                    inputMode="numeric"
                                    placeholder="e.g. 8807006013816"
                                    onKeyDown={(e) => { if (e.key === 'Enter') submitManual(); }}
                                    className="flex-1 border border-gray-300 dark:border-gray-600 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[#068B03] bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                                />
                                <button
                                    onClick={submitManual}
                                    className="px-4 py-2 bg-[#068B03] hover:bg-[#057002] text-white text-sm font-semibold rounded-xl transition-colors"
                                >
                                    Use
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
