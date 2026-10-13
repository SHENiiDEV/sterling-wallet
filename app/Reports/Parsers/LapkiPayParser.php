<?php

namespace App\Reports\Parsers;

use Carbon\CarbonImmutable;
use SplFileInfo;

/**
 * LapkiPay Business (C2B bank transfers), pushed by the bot as a JSON batch:
 * `{"transactions": [...]}` or a bare list. Only completed incoming
 * transfers are operations; pending ones arrive again once completed.
 *
 * The MID is the cabinet company plus the currency (`BASILDON LIMITED EUR`),
 * so one company can have a MID per currency. Times are in the provider's
 * time zone (UTC), `d/m/Y H:i:s`.
 */
class LapkiPayParser implements ReportParser
{
    public const FORMAT = 'lapkipay';

    public function format(): string
    {
        return self::FORMAT;
    }

    public function supports(string $format): bool
    {
        return $format === self::FORMAT;
    }

    public function reportDate(SplFileInfo $file): ?CarbonImmutable
    {
        return null;
    }

    public function rows(SplFileInfo $file, string $timezone): iterable
    {
        $data = json_decode((string) file_get_contents($file->getPathname()), true);
        if (! is_array($data)) {
            return;
        }

        foreach ($data['transactions'] ?? $data as $tx) {
            if (is_array($tx) && ($row = $this->row($tx, $timezone)) !== null) {
                yield $row;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tx
     */
    private function row(array $tx, string $timezone): ?OperationRow
    {
        if (strtolower((string) ($tx['status'] ?? '')) !== 'completed' || ! str_contains(strtolower((string) ($tx['type'] ?? 'incoming transfer')), 'incoming')) {
            return null;
        }

        $amount = Values::decimal($tx['amount'] ?? null);
        $currency = Values::currency($tx['currency'] ?? null);
        if ($amount === null) {
            return null;
        }

        $company = Values::text($tx['company'] ?? null);
        [$transactedAt, $transactedOn] = Values::datetime($tx['date'] ?? null, $timezone);

        return new OperationRow(
            mid: $company !== null ? strtoupper($company).' '.($currency ?? '') : null,
            merchantName: $company,
            paymentId: Values::text($tx['id'] ?? null),
            amount: $amount,
            currency: $currency,
            ips: 'transfer',
            issuerName: Values::text($tx['bicSwift'] ?? null),
            trnType: 'incoming', // the operations column is 16 characters wide
            resolution: 'completed',
            transactedAt: $transactedAt,
            transactedOn: $transactedOn,
            raw: $tx,
        );
    }
}
