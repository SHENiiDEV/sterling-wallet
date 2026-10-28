<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Time-based one-time passwords (RFC 6238): the six digits an authenticator app shows.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Reads what an admin pastes: a base32 secret as the platform shows it (spaces allowed), or the otpauth:// link
     * behind its QR code, which can also carry the digits, period and algorithm.
     *
     * @return array{secret: string, digits: int, period: int, algorithm: string, issuer: string|null, label: string|null}
     */
    public static function parse(string $input): array
    {
        $input = trim($input);
        $parsed = ['digits' => 6, 'period' => 30, 'algorithm' => 'sha1', 'issuer' => null, 'label' => null];

        if (stripos($input, 'otpauth://') === 0) {
            $url = parse_url($input);
            parse_str($url['query'] ?? '', $query);

            $input = (string) ($query['secret'] ?? '');
            $label = urldecode(ltrim((string) ($url['path'] ?? ''), '/'));
            $parsed['label'] = $label !== '' ? $label : null;
            $parsed['issuer'] = isset($query['issuer']) ? (string) $query['issuer'] : null;
            $parsed['digits'] = in_array((int) ($query['digits'] ?? 6), [6, 7, 8], true) ? (int) ($query['digits'] ?? 6) : 6;
            $parsed['period'] = max(1, (int) ($query['period'] ?? 30));
            $algorithm = strtolower((string) ($query['algorithm'] ?? 'sha1'));
            $parsed['algorithm'] = in_array($algorithm, ['sha1', 'sha256', 'sha512'], true) ? $algorithm : 'sha1';
        }

        $secret = strtoupper(preg_replace('/[\s\-=]+/', '', $input) ?? '');
        if ($secret === '' || preg_match('/^[A-Z2-7]+$/', $secret) !== 1 || strlen($secret) < 8) {
            throw new InvalidArgumentException('That is not an authenticator secret: it is letters A–Z and digits 2–7 (or an otpauth:// link).');
        }

        return ['secret' => $secret, ...$parsed];
    }

    public static function code(string $secret, int $digits = 6, int $period = 30, string $algorithm = 'sha1', ?int $at = null): string
    {
        $counter = intdiv($at ?? time(), $period);
        $hash = hash_hmac($algorithm, pack('J', $counter), self::decode($secret), true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Seconds the current code is still valid.
     */
    public static function remaining(int $period = 30, ?int $at = null): int
    {
        return $period - (($at ?? time()) % $period);
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }
}
