<?php

namespace App\Reports\Parsers;

use Carbon\CarbonImmutable;
use SplFileInfo;

/**
 * Base for tabular reports: finds the header row, maps columns through the
 * aliases in `config('sterling.parsers.{format}')` and builds OperationRows.
 * Subclasses only name their format and, if needed, tweak one row.
 */
abstract class HeaderMappedParser implements ReportParser
{
    /** Rows scanned for the header line (reports often start with a title block). */
    protected const HEADER_SCAN_ROWS = 40;

    public function __construct(protected TabularReader $reader) {}

    abstract public function format(): string;

    public function supports(string $format): bool
    {
        return $format === $this->format();
    }

    public function reportDate(SplFileInfo $file): ?CarbonImmutable
    {
        return null;
    }

    public function rows(SplFileInfo $file, string $timezone): iterable
    {
        $columns = null;
        $headers = [];
        $scanned = 0;

        foreach ($this->reader->rows($file) as $cells) {
            if ($columns === null) {
                if (++$scanned > static::HEADER_SCAN_ROWS) {
                    return;
                }
                $columns = $this->matchHeader($cells);
                $headers = $columns === null ? [] : array_map(fn ($h) => Values::text($h) ?? '', $cells);

                continue;
            }

            if (array_filter($cells, fn ($cell) => Values::text($cell) !== null) === []) {
                continue;
            }

            $row = $this->buildRow($cells, $columns, $headers, $timezone);
            if ($row !== null) {
                yield $row;
            }
        }
    }

    /**
     * @param  list<string|null>  $cells
     * @return array<string, int>|null field => column index, when this looks like the header
     */
    protected function matchHeader(array $cells): ?array
    {
        $normalized = array_map(fn ($cell) => self::normalizeHeader((string) $cell), $cells);
        $columns = [];

        foreach ($this->aliases() as $field => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search(self::normalizeHeader($alias), $normalized, true);
                if ($index !== false && ! in_array($index, $columns, true)) {
                    $columns[$field] = $index;

                    break;
                }
            }
        }

        return isset($columns['amount']) && count($columns) >= 3 ? $columns : null;
    }

    /**
     * @param  list<string|null>  $cells
     * @param  array<string, int>  $columns
     * @param  list<string>  $headers
     */
    protected function buildRow(array $cells, array $columns, array $headers, string $timezone): ?OperationRow
    {
        $get = fn (string $field) => isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null;

        $amount = Values::decimal($get('amount'));
        if ($amount === null) {
            return null; // totals, sub-headers and notes have no amount
        }

        $raw = [];
        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $raw[$header] = $cells[$index] ?? null;
            }
        }

        [$cardMask, $cardBin, $cardLast4] = Values::card($get('card'), $get('card_bin'), $get('card_last4'));
        [$transactedAt, $transactedOn] = Values::datetime($get('transacted_at'), $timezone);
        [$processedAt, $processedOn] = Values::datetime($get('processed_at'), $timezone);
        $issuerCountry = Values::country($get('issuer_country'));

        $fees = [];
        foreach (['eu_fee', 'non_eu_fee', 'ic_fee', 'ic_interchange', 'ic_scheme_fee', 'approve_fee', 'decline_fee', 'refund_fee'] as $fee) {
            if (isset($columns[$fee])) {
                $fees[$fee] = Values::decimal($get($fee));
            }
        }

        $attributes = [
            'mid' => Values::text($get('mid')),
            'merchantName' => Values::text($get('merchant_name')),
            'paymentId' => Values::text($get('payment_id')),
            'amount' => $amount,
            'currency' => Values::currency($get('currency')),
            'providerLogin' => Values::text($get('provider_login')),
            'arn' => Values::text($get('arn')),
            'rrn' => Values::text($get('rrn')),
            'approvalCode' => Values::text($get('approval_code')),
            'cardMask' => $cardMask,
            'cardBin' => $cardBin,
            'cardLast4' => $cardLast4,
            'ips' => Values::ips($get('ips'), $cardBin),
            'region' => Values::region($get('region'), $issuerCountry),
            'issuerCountry' => $issuerCountry,
            'issuerName' => Values::text($get('issuer_name')),
            'trnType' => Values::text($get('trn_type')),
            'resolution' => Values::text($get('resolution')),
            'processingCode' => Values::text($get('processing_code')),
            'email' => ($email = Values::text($get('email'))) !== null && str_contains($email, '@') ? strtolower($email) : null,
            'transactedAt' => $transactedAt,
            'transactedOn' => $transactedOn ?? $processedOn,
            'processedAt' => $processedAt ?? $processedOn?->utc(),
            'fees' => $fees,
            'raw' => $raw,
        ];

        $attributes = $this->adjust($attributes, $get);

        return $attributes === null ? null : new OperationRow(...$attributes);
    }

    /**
     * Format-specific touch-ups of one row; return null to drop it.
     *
     * @param  array<string, mixed>  $attributes  OperationRow constructor arguments
     * @param  callable(string): (string|null)  $get  raw cell by field name
     * @return array<string, mixed>|null
     */
    protected function adjust(array $attributes, callable $get): ?array
    {
        return $attributes;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function aliases(): array
    {
        return config("sterling.parsers.{$this->format()}", []);
    }

    public static function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        return preg_replace('/[^a-z0-9+]/', '', mb_strtolower($header)) ?? '';
    }
}
