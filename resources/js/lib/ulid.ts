// Crockford's base32, as the server's ULIDs use: no I, L, O or U.
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

/**
 * A ULID: 26 uppercase characters of Crockford base32, the first 10 from the
 * time in milliseconds and the last 16 from 80 random bits. The editor gives
 * one to every block and card that carries an ID; the server checks the shape
 * (DocumentSchema::NODE_ID). No package: this is all of it.
 */
export function ulid(now: number = Date.now()): string {
    let time = '';
    let remaining = now;

    for (let i = 0; i < 10; i++) {
        time = ALPHABET[remaining % 32] + time;
        remaining = Math.floor(remaining / 32);
    }

    const bytes = new Uint8Array(10);
    crypto.getRandomValues(bytes);

    let random = '';
    let buffer = 0;
    let bits = 0;

    for (const byte of bytes) {
        buffer = (buffer << 8) | byte;
        bits += 8;

        while (bits >= 5) {
            bits -= 5;
            random += ALPHABET[(buffer >> bits) & 31];
        }

        buffer &= (1 << bits) - 1;
    }

    return time + random;
}
