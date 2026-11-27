/**
 * The counting keypad's arithmetic: what one key press does to the number
 * typed so far for one unit. Plain strings, the same form the unit boxes used
 * ("12", "3½"), so the units helpers read them unchanged.
 */

/** Longest quantity the keypad builds: 99999 is more than any shelf holds. */
export const MAX_DIGITS = 5;

/**
 * @param {string} value what is typed so far ('' for nothing)
 * @param {string} key '0'–'9', '½', 'back' or 'clear'
 * @param {boolean} allowHalves whether this shop counts half units
 */
export function press(value, key, allowHalves) {
    const now = String(value ?? '');

    if (key === 'clear') return '';
    if (key === 'back') return now.slice(0, -1);

    if (key === '½') {
        if (!allowHalves || now.endsWith('½')) return now;
        return now === '0' ? '½' : `${now}½`;
    }

    if (!/^[0-9]$/.test(key)) return now;
    if (now.endsWith('½')) return now;       // nothing comes after a half
    if (now === '0') return key;             // "07" is 7
    if (now.length >= MAX_DIGITS) return now;

    return now + key;
}

/** One more of a unit (tap-to-count): "3" → "4", "" → "1", "2½" → "3½". */
export function plusOne(value) {
    const now = String(value ?? '').trim();
    if (now === '') return '1';
    if (now.endsWith('½')) return `${Number(now.slice(0, -1) || 0) + 1}½`;

    const n = Number(now);
    return Number.isFinite(n) ? String(n + 1) : '1';
}
