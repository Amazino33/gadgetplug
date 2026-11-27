/**
 * The server's clock, as seen from this phone.
 *
 * When each item was counted decides what the shelf is compared with, so it
 * must be the server's time, not whatever this phone is set to. The offset is
 * measured on every pack download and every sync: server_time minus the
 * middle of the request (the best guess at when the server read its clock).
 */
export function offsetFrom(serverTimeIso, sentAt, receivedAt) {
    const server = Date.parse(serverTimeIso);
    if (Number.isNaN(server)) return null;
    return Math.round(server - (sentAt + receivedAt) / 2);
}

/** Now, on the server's clock. */
export function serverNow(offset = 0, deviceNow = Date.now()) {
    return deviceNow + (offset ?? 0);
}

export const iso = (ms) => new Date(ms).toISOString();
