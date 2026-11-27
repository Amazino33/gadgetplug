/**
 * Sharper barcode reading on phones.
 *
 * Asked for from the camera: a full-HD picture (more pixels on a barcode held
 * a little further away). Focus and zoom are set once it runs, never in the
 * request itself, so no phone can refuse to start the camera over them.
 *
 * Applied once the camera runs:
 *   - continuous autofocus where the phone offers it (many default to one
 *     focus at start and then stay blurred);
 *   - a little zoom where the phone offers it, so a barcode fills the frame
 *     from a distance the lens can focus at — held too close, it cannot.
 * Tapping the picture asks for a fresh focus.
 *
 * Every step is optional: a phone or browser without it carries on as before.
 */
export const CAMERA_CONSTRAINTS = {
    video: {
        facingMode: 'environment',
        width: { ideal: 1920 },
        height: { ideal: 1080 },
    },
    audio: false,
};

const ZOOM = 1.6;

export async function tuneCamera(track) {
    if (!track?.getCapabilities) return;

    let caps;
    try { caps = track.getCapabilities(); } catch { return; }

    const advanced = {};
    if (caps.focusMode?.includes('continuous')) advanced.focusMode = 'continuous';
    if (caps.zoom && caps.zoom.max >= ZOOM && caps.zoom.min <= ZOOM) advanced.zoom = ZOOM;

    if (Object.keys(advanced).length) {
        try { await track.applyConstraints({ advanced: [advanced] }); } catch { /* not on this phone */ }
    }
}

/** A tap on the picture: focus again now, then keep focusing. */
export async function refocus(track) {
    if (!track?.getCapabilities) return;

    let modes;
    try { modes = track.getCapabilities().focusMode ?? []; } catch { return; }

    try {
        if (modes.includes('single-shot')) {
            await track.applyConstraints({ advanced: [{ focusMode: 'single-shot' }] });
            setTimeout(() => {
                if (modes.includes('continuous')) track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(() => {});
            }, 1200);
        } else if (modes.includes('continuous')) {
            // Switching mode off and on again makes many phones hunt for focus.
            await track.applyConstraints({ advanced: [{ focusMode: 'manual' }] }).catch(() => {});
            await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] });
        }
    } catch { /* not on this phone */ }
}

/** The video track playing in a <video>. */
export const videoTrack = (video) => video?.srcObject?.getVideoTracks?.()[0] ?? null;
