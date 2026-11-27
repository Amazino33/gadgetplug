import { describe, expect, it } from 'vitest';
import { wavBytes } from './feedback';

describe('the beep clips', () => {
    it('are real WAV audio, small, with sound in them', () => {
        const bytes = new Uint8Array(wavBytes([[1320, 110, 0]]));
        const text = (from, n) => String.fromCharCode(...bytes.slice(from, from + n));

        expect(text(0, 4)).toBe('RIFF');
        expect(text(8, 4)).toBe('WAVE');
        expect(bytes.length).toBeLessThan(10_000);

        const pcm = new Int16Array(bytes.buffer.slice(44));
        const loudest = pcm.reduce((m, s) => Math.max(m, Math.abs(s)), 0);
        expect(loudest).toBeGreaterThan(10_000);
    });
});
