<?php

namespace App\Reports\Parsers;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Small, format-agnostic cleaners shared by every parser.
 */
final class Values
{
    private const NUMERIC_CURRENCIES = ['978' => 'EUR', '840' => 'USD', '826' => 'GBP'];

    private const DATETIME_FORMATS = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i',
        'd.m.Y H:i:s', 'd.m.Y H:i', 'Y.m.d H:i:s', 'Y.m.d H:i',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i',
    ];

    private const DATE_FORMATS = ['Y-m-d', 'd.m.Y', 'Y.m.d', 'd/m/Y', 'd-m-Y', 'Ymd'];

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(str_replace("\u{00A0}", ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * "1 234,56", "1,234.56", "(12.00)", "-5" → decimal string, or null.
     */
    public static function decimal(mixed $value): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = preg_replace('/[^\d,.\-]/', '', $value) ?? '';
        if (str_starts_with($value, '-')) {
            $negative = true;
            $value = ltrim($value, '-');
        }
        if ($value === '' || ! preg_match('/\d/', $value)) {
            return null;
        }

        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            $decimalSeparator = $comma > $dot ? ',' : '.';
        } elseif ($comma !== false) {
            $decimalSeparator = substr_count($value, ',') === 1 && strlen($value) - $comma - 1 !== 3 ? ',' : '';
        } else {
            $decimalSeparator = substr_count($value, '.') === 1 ? '.' : '';
        }

        $thousands = $decimalSeparator === ',' ? '.' : ',';
        $value = str_replace($thousands, '', $value);
        if ($decimalSeparator === ',') {
            $value = str_replace(',', '.', $value);
        } elseif ($decimalSeparator === '') {
            $value = str_replace(['.', ','], '', $value);
        }

        if (! preg_match('/^\d*\.?\d*$/', $value) || $value === '.') {
            return null;
        }
        if (str_starts_with($value, '.')) {
            $value = '0'.$value;
        }
        $value = rtrim($value, '.');

        return ($negative && (float) $value != 0.0 ? '-' : '').$value;
    }

    public static function currency(mixed $value): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            return null;
        }
        if (isset(self::NUMERIC_CURRENCIES[$value])) {
            return self::NUMERIC_CURRENCIES[$value];
        }
        $value = strtoupper(preg_replace('/[^A-Za-z]/', '', $value) ?? '');

        return strlen($value) === 3 ? $value : null;
    }

    /**
     * "411111******1111" / "4111 11XX XXXX 1111" → [mask, bin, last4].
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    public static function card(mixed $mask, mixed $bin = null, mixed $last4 = null): array
    {
        $mask = self::text($mask);
        $bin = self::text($bin);
        $last4 = self::text($last4);

        if ($mask !== null) {
            $compact = preg_replace('/\s+/', '', $mask) ?? '';
            if ($bin === null && preg_match('/^(\d{6,8})/', $compact, $m)) {
                $bin = substr($m[1], 0, 6);
            }
            if ($last4 === null && preg_match('/(\d{4})$/', $compact, $m)) {
                $last4 = $m[1];
            }
        }

        $bin = $bin !== null ? substr(preg_replace('/\D/', '', $bin) ?? '', 0, 6) : null;
        $last4 = $last4 !== null ? substr(preg_replace('/\D/', '', $last4) ?? '', -4) : null;

        return [$mask, $bin ?: null, $last4 ?: null];
    }

    /**
     * Card scheme as `visa` / `mastercard` / other lowercase name; falls back
     * to the BIN range when the file has no usable brand column.
     */
    /**
     * Apple Pay / Google Pay marker of a "wallet" or "payment method" cell.
     */
    public static function wallet(mixed $value): ?string
    {
        $value = strtolower(self::text($value) ?? '');

        return match (true) {
            str_contains($value, 'apple') => 'apple_pay',
            str_contains($value, 'google'), str_contains($value, 'gpay') => 'google_pay',
            default => null,
        };
    }

    public static function ips(mixed $value, ?string $bin = null): ?string
    {
        $value = strtolower(self::text($value) ?? '');
        if (str_contains($value, 'visa')) {
            return 'visa';
        }
        if (str_contains($value, 'master') || $value === 'mc') {
            return 'mastercard';
        }

        if ($bin !== null && $bin !== '') {
            if ($bin[0] === '4') {
                return 'visa';
            }
            $two = (int) substr($bin, 0, 2);
            $four = (int) substr($bin, 0, 4);
            if (($two >= 51 && $two <= 55) || ($four >= 2221 && $four <= 2720)) {
                return 'mastercard';
            }
        }

        return $value !== '' && ! in_array($value, ['card', 'cards', 'bank card'], true) ? $value : null;
    }

    /**
     * `eu` or `non_eu`, from an explicit region column or the issuer country.
     */
    public static function region(mixed $value, ?string $issuerCountry = null): ?string
    {
        $value = strtolower(preg_replace('/[^A-Za-z]/', '', (string) $value) ?? '');
        if ($value !== '') {
            if (in_array($value, ['noneu', 'noneea', 'intl', 'international', 'interregional', 'nonsepa', 'row', 'outsideeu'], true)) {
                return 'non_eu';
            }
            if (in_array($value, ['eu', 'eea', 'intra', 'intraregional', 'domestic', 'sepa', 'europe'], true)) {
                return 'eu';
            }
        }

        if ($issuerCountry !== null) {
            return in_array(strtoupper($issuerCountry), config('sterling.eu_countries', []), true) ? 'eu' : 'non_eu';
        }

        return null;
    }

    public static function country(mixed $value): ?string
    {
        $value = strtoupper(self::text($value) ?? '');

        return preg_match('/^[A-Z]{2}$/', $value) ? $value : null;
    }

    /**
     * Parses a date-time in `$timezone` (unless it carries an offset) and
     * returns [UTC instant or null when there is no time part, local day].
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public static function datetime(mixed $value, string $timezone): array
    {
        $text = self::text($value);
        if ($text === null) {
            return [null, null];
        }

        // Spreadsheet serial date (days since 1899-12-30, fraction = time).
        if (is_numeric($text) && (float) $text > 20000 && (float) $text < 80000) {
            $seconds = (int) round(((float) $text - 25569) * 86400);
            $local = CarbonImmutable::createFromTimestampUTC($seconds)->shiftTimezone($timezone);
            $hasTime = fmod((float) $text, 1.0) !== 0.0;

            return [$hasTime ? $local->utc() : null, $local->startOfDay()];
        }

        foreach (self::DATETIME_FORMATS as $format) {
            if (($parsed = self::exact($format, $text, $timezone)) !== null) {
                return [$parsed->utc(), $parsed->startOfDay()];
            }
        }

        foreach (self::DATE_FORMATS as $format) {
            if (($parsed = self::exact($format, $text, $timezone)) !== null) {
                return [null, $parsed];
            }
        }

        try {
            $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $text);
            $parsed = $hasOffset ? CarbonImmutable::parse($text) : CarbonImmutable::parse($text, $timezone);

            return [$parsed->utc(), $parsed->setTimezone($timezone)->startOfDay()];
        } catch (Throwable) {
            return [null, null];
        }
    }

    private static function exact(string $format, string $text, string $timezone): ?CarbonImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $text, new \DateTimeZone($timezone));

        return $parsed !== false && $parsed->format($format) === $text ? CarbonImmutable::instance($parsed) : null;
    }

    /**
     * Finds a date or date range in free text ("Processing date(s)
     * 2026.08.07-2026.08.09", "07.08.2026", …).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null [from, to]
     */
    public static function dateRange(string $text): ?array
    {
        $ymd = '(\d{4})[.\-\/](\d{2})[.\-\/](\d{2})';
        $dmy = '(\d{2})[.\-\/](\d{2})[.\-\/](\d{4})';
        $separator = '\s*(?:-|–|—|to)\s*';

        if (preg_match("/{$ymd}{$separator}{$ymd}/i", $text, $m)) {
            return [self::day($m[1], $m[2], $m[3]), self::day($m[4], $m[5], $m[6])];
        }
        if (preg_match("/{$dmy}{$separator}{$dmy}/i", $text, $m)) {
            return [self::day($m[3], $m[2], $m[1]), self::day($m[6], $m[5], $m[4])];
        }
        if (preg_match("/{$ymd}/", $text, $m)) {
            $day = self::day($m[1], $m[2], $m[3]);

            return [$day, $day];
        }
        if (preg_match("/{$dmy}/", $text, $m)) {
            $day = self::day($m[3], $m[2], $m[1]);

            return [$day, $day];
        }

        return null;
    }

    private static function day(string $year, string $month, string $day): CarbonImmutable
    {
        return CarbonImmutable::create((int) $year, (int) $month, (int) $day)->startOfDay();
    }
}
