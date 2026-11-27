/**
 * An id for one Save, made on the phone. Sent again on Retry, so the server can
 * tell "the same save, its answer was lost" from "a second count".
 *
 * crypto.randomUUID exists only on HTTPS pages; getRandomValues everywhere.
 */
export function newSaveId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i += 1) bytes[i] = Math.floor(Math.random() * 256);
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // variant

    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * The id for this attempt: the same as the last attempt when nothing changed
 * (a Retry), a new one when the counter changed what they are saving.
 */
export function saveIdFor(previous, payload) {
    const fingerprint = JSON.stringify(payload);
    if (previous && previous.fingerprint === fingerprint) return previous;
    return { id: newSaveId(), fingerprint };
}
