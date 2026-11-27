import { describe, expect, it, vi } from 'vitest';
import { CAMERA_CONSTRAINTS, refocus, tuneCamera } from './cameraFocus';

const fakeTrack = (caps) => ({ getCapabilities: () => caps, applyConstraints: vi.fn(async () => {}) });

describe('camera focus', () => {
    it('asks for continuous focus and a little zoom where the phone has them', async () => {
        const track = fakeTrack({ focusMode: ['manual', 'single-shot', 'continuous'], zoom: { min: 1, max: 8 } });
        await tuneCamera(track);
        expect(track.applyConstraints).toHaveBeenCalledWith({ advanced: [{ focusMode: 'continuous', zoom: 1.6 }] });
    });

    it('leaves a camera without those settings alone', async () => {
        const track = fakeTrack({ zoom: { min: 1, max: 1 } });
        await tuneCamera(track);
        await tuneCamera(null);
        expect(track.applyConstraints).not.toHaveBeenCalled();
    });

    it('a tap focuses once, then goes back to focusing continuously', async () => {
        vi.useFakeTimers();
        const track = fakeTrack({ focusMode: ['single-shot', 'continuous'] });
        await refocus(track);
        expect(track.applyConstraints).toHaveBeenLastCalledWith({ advanced: [{ focusMode: 'single-shot' }] });
        vi.advanceTimersByTime(1300);
        expect(track.applyConstraints).toHaveBeenLastCalledWith({ advanced: [{ focusMode: 'continuous' }] });
        vi.useRealTimers();
    });

    it('never puts focus or zoom in the start request, so no phone refuses the camera', () => {
        expect(JSON.stringify(CAMERA_CONSTRAINTS)).not.toMatch(/focusMode|zoom|advanced/);
    });
});
