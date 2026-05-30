{{--
    Barcode Scanner Modal Component
    Triggered by: window.dispatchEvent(new CustomEvent('open-barcode-scanner', { detail: { targetId: 'livewire-field-id' } }))
    On scan:      dispatches 'barcode-scanned' event with { barcode: '...' } to window
    Uses BarcodeDetector API (Chrome/Edge) — falls back to keyboard input for unsupported browsers
--}}
<div
    x-data="{
        open:       false,
        scanning:   false,
        error:      '',
        detected:   '',
        stream:     null,
        detector:   null,
        animFrame:  null,
        supported:  'BarcodeDetector' in window,

        async startCamera() {
            this.error    = '';
            this.detected = '';
            this.scanning = true;
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
                });
                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play();
                if (this.supported) {
                    this.detector = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'qr_code', 'code_128', 'code_39', 'upc_a', 'upc_e'] });
                    this.scan();
                }
            } catch (e) {
                this.error   = 'Camera access denied. Please allow camera permissions and try again.';
                this.scanning = false;
            }
        },

        scan() {
            if (!this.scanning) return;
            this.animFrame = requestAnimationFrame(async () => {
                try {
                    if (this.$refs.video.readyState === this.$refs.video.HAVE_ENOUGH_DATA) {
                        const barcodes = await this.detector.detect(this.$refs.video);
                        if (barcodes.length > 0) {
                            this.detected = barcodes[0].rawValue;
                            this.stopCamera();
                            window.dispatchEvent(new CustomEvent('barcode-scanned', { detail: { barcode: this.detected } }));
                            await new Promise(r => setTimeout(r, 800));
                            this.open = false;
                            return;
                        }
                    }
                } catch (_) {}
                if (this.scanning) this.scan();
            });
        },

        stopCamera() {
            this.scanning = false;
            if (this.animFrame) { cancelAnimationFrame(this.animFrame); this.animFrame = null; }
            if (this.stream)    { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
            if (this.$refs.video) this.$refs.video.srcObject = null;
        },

        submitManual() {
            const val = this.$refs.manualInput?.value?.trim();
            if (!val) return;
            window.dispatchEvent(new CustomEvent('barcode-scanned', { detail: { barcode: val } }));
            this.$refs.manualInput.value = '';
            this.open = false;
        },
    }"
    x-on:open-barcode-scanner.window="open = true; $nextTick(() => supported ? startCamera() : null)"
    x-on:keydown.escape.window="open && (stopCamera(), open = false)"
>
    {{-- Backdrop --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-[200] flex items-end sm:items-center justify-center bg-black/70 p-4"
        style="display:none"
        @click.self="stopCamera(); open = false"
    >
        {{-- Modal --}}
        <div class="w-full max-w-sm bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden">

            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h2.25M3 9h2.25M3 13.5h2.25M3 18h2.25M7.5 4.5H9M7.5 9H9M7.5 13.5H9M7.5 18H9M12 4.5h2.25M12 9h2.25M12 13.5h2.25M12 18h2.25M16.5 4.5H18M16.5 9H18M16.5 13.5H18M16.5 18H18M21 4.5h-2.25M21 9h-2.25M21 13.5h-2.25M21 18h-2.25"/>
                    </svg>
                    <span class="font-semibold text-sm text-gray-800 dark:text-gray-100">Scan Barcode</span>
                </div>
                <button @click="stopCamera(); open = false"
                    class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Camera view (BarcodeDetector supported) --}}
            <div x-show="supported" class="relative bg-black aspect-video">
                <video x-ref="video" class="w-full h-full object-cover" muted playsinline></video>

                {{-- Scan overlay --}}
                <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div class="w-56 h-32 border-2 border-primary-400 rounded-xl opacity-80 relative">
                        <span class="absolute -top-1 -left-1 w-4 h-4 border-t-2 border-l-2 border-primary-500 rounded-tl"></span>
                        <span class="absolute -top-1 -right-1 w-4 h-4 border-t-2 border-r-2 border-primary-500 rounded-tr"></span>
                        <span class="absolute -bottom-1 -left-1 w-4 h-4 border-b-2 border-l-2 border-primary-500 rounded-bl"></span>
                        <span class="absolute -bottom-1 -right-1 w-4 h-4 border-b-2 border-r-2 border-primary-500 rounded-br"></span>
                        {{-- Scan line animation --}}
                        <div class="absolute left-0 right-0 h-0.5 bg-primary-400 opacity-70 animate-[scanline_2s_linear_infinite]"></div>
                    </div>
                </div>

                {{-- Detected flash --}}
                <div x-show="detected" class="absolute inset-0 flex items-center justify-center bg-green-500/20">
                    <span class="bg-green-600 text-white text-sm font-bold px-4 py-2 rounded-xl shadow-lg" x-text="'✓ ' + detected"></span>
                </div>
            </div>

            {{-- Error message --}}
            <div x-show="error" class="px-4 py-3 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-700">
                <p class="text-red-600 dark:text-red-400 text-sm" x-text="error"></p>
            </div>

            {{-- Manual entry fallback (always shown; required when BarcodeDetector unavailable) --}}
            <div class="px-4 py-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                    <span x-show="supported">Or type / paste the barcode manually:</span>
                    <span x-show="!supported">Camera scanning not supported in this browser. Type the barcode:</span>
                </p>
                <div class="flex gap-2">
                    <input
                        x-ref="manualInput"
                        type="text"
                        inputmode="numeric"
                        placeholder="e.g. 8807006013816"
                        @keydown.enter="submitManual()"
                        class="flex-1 border border-gray-300 dark:border-gray-600 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-primary-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                    >
                    <button
                        @click="submitManual()"
                        class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-semibold rounded-xl transition-colors">
                        Use
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
@keyframes scanline {
    0%   { top: 10%; }
    50%  { top: 80%; }
    100% { top: 10%; }
}
</style>
