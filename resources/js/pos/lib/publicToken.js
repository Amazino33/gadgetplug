/**
 * The token a sale's customer-facing receipt is addressed by.
 *
 * The till mints it, not the server, because the QR on the paper has to be
 * printed before the server has ever heard of the sale. It matches what the
 * server makes (PosSale::generatePublicToken, Str::random(16)): sixteen letters
 * and digits, and the server rejects anything else.
 *
 * Drawn from crypto.getRandomValues, never Math.random — the token is the only
 * thing standing between a receipt and anyone who guesses its address.
 */
const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
export const TOKEN_LENGTH = 16;

// Bytes at or above this are thrown away. 256 is not a multiple of 62, so using
// `byte % 62` directly would make the first 8 characters slightly likelier.
const LIMIT = 256 - (256 % ALPHABET.length);

export function newPublicToken(random = globalThis.crypto) {
    if (typeof random?.getRandomValues !== 'function') {
        // Better no QR than a guessable one.
        throw new Error('No secure random source available for the receipt token.');
    }

    let token = '';

    while (token.length < TOKEN_LENGTH) {
        for (const byte of random.getRandomValues(new Uint8Array(TOKEN_LENGTH * 2))) {
            if (byte < LIMIT && token.length < TOKEN_LENGTH) {
                token += ALPHABET[byte % ALPHABET.length];
            }
        }
    }

    return token;
}

export const isPublicToken = (value) => typeof value === 'string' && /^[A-Za-z0-9]{16}$/.test(value);
