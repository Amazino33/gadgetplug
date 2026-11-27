import { describe, expect, it } from 'vitest';
import { MAX_DIGITS, plusOne, press } from './keypad';
import { cameraByDefault, cameraMode } from './camera';

describe('the counting keypad', () => {
    it('builds a number key by key', () => {
        let v = '';
        for (const k of ['1', '2']) v = press(v, k, false);
        expect(v).toBe('12');
        expect(press(v, 'back', false)).toBe('1');
        expect(press(v, 'clear', false)).toBe('');
    });

    it('drops a leading zero and stops at five digits', () => {
        expect(press('0', '7', false)).toBe('7');
        expect(press('0', '0', false)).toBe('0');
        expect(press('9'.repeat(MAX_DIGITS), '1', false)).toBe('99999');
    });

    it('adds a half only where the shop counts halves, once, last', () => {
        expect(press('2', '½', false)).toBe('2');
        expect(press('2', '½', true)).toBe('2½');
        expect(press('2½', '½', true)).toBe('2½');
        expect(press('2½', '3', true)).toBe('2½');
        expect(press('', '½', true)).toBe('½');
        expect(press('0', '½', true)).toBe('½');
        expect(press('2½', 'back', true)).toBe('2');
    });

    it('one more, for tap-to-count', () => {
        expect(plusOne('')).toBe('1');
        expect(plusOne('6')).toBe('7');
        expect(plusOne('2½')).toBe('3½');
    });
});

describe('which camera reader to use', () => {
    const camera = { mediaDevices: { getUserMedia: () => {} } };

    it('uses the browser\'s own reader where there is one', () => {
        expect(cameraMode({ navigator: camera, BarcodeDetector: class {} }, false)).toBe('native');
        expect(cameraMode({ navigator: camera, BarcodeDetector: class {} }, true)).toBe('native');
    });

    it('downloads ZXing only on a phone or tablet without one (an iPhone)', () => {
        expect(cameraMode({ navigator: camera }, true)).toBe('zxing');
    });

    it('never on a desktop without one: it counts with a hand-held scanner', () => {
        expect(cameraMode({ navigator: camera }, false)).toBe('none');
    });

    it('none where there is no camera at all', () => {
        expect(cameraMode({ navigator: {} }, true)).toBe('none');
    });

    it('starts the camera by itself on a phone, not on a desktop', () => {
        expect(cameraByDefault(true)).toBe(true);
        expect(cameraByDefault(false)).toBe(false);
    });
});
