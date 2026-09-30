import crypto from 'node:crypto';

/** RFC 6238 TOTP (SHA-1, 30 s, 6 digits) from a base32 secret. */
export function totp(secret, now = Date.now()) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const clean = String(secret)
        .replace(/[\s=-]/g, '')
        .toUpperCase();
    let bits = '';
    for (const char of clean) {
        const value = alphabet.indexOf(char);
        if (value < 0) throw new Error('TOTP secret is not valid base32');
        bits += value.toString(2).padStart(5, '0');
    }
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));

    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(now / 1000 / 30)));
    const hmac = crypto.createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return String(code).padStart(6, '0');
}

/**
 * A code with at least `minSeconds` left in its 30 s window, so it is not
 * rejected as expired while it is being typed and submitted.
 */
export async function freshTotp(secret, minSeconds = 6) {
    const left = 30 - (Math.floor(Date.now() / 1000) % 30);
    if (left < minSeconds) {
        await new Promise((resolve) => setTimeout(resolve, (left + 1) * 1000));
    }

    return totp(secret);
}

/** Waits for the next 30 s window (a code can be used only once). */
export async function nextTotpWindow() {
    const left = 30 - (Math.floor(Date.now() / 1000) % 30);
    await new Promise((resolve) => setTimeout(resolve, (left + 1) * 1000));
}
