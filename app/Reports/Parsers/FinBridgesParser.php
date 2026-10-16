<?php

namespace App\Reports\Parsers;

use Carbon\CarbonImmutable;
use SplFileInfo;

/**
 * FinBridges (C2B bank transfers): transactions as the public API returns
 * them, `{"count": n, "list": [Transaction …]}` (a bare list works too).
 * Only completed incoming transfers (DEPOSIT / COMPLETED) are operations;
 * pending ones arrive again once completed. Returns, withdrawals, fees and
 * the other transaction types are not clearing data and are skipped.
 *
 * The MID is the wallet id (one wallet per currency); `date` is a Unix
 * timestamp, in seconds or milliseconds.
 */
class FinBridgesParser implements ReportParser
{
    public const FORMAT = 'finbridges';

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

        foreach ($data['list'] ?? $data['transactions'] ?? $data as $tx) {
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
        if (strtoupper((string) ($tx['transactionType'] ?? '')) !== 'DEPOSIT' || strtoupper((string) ($tx['status'] ?? '')) !== 'COMPLETED') {
            return null;
        }

        $amount = Values::decimal($tx['amountOrigCcy'] ?? null);
        $wallet = Values::text($tx['walletId'] ?? null);
        if ($amount === null || $wallet === null) {
            return null;
        }

        $at = $on = null;
        if (is_numeric($tx['date'] ?? null) && (float) $tx['date'] > 0) {
            $seconds = (float) $tx['date'] > 100_000_000_000 ? (int) round((float) $tx['date'] / 1000) : (int) $tx['date'];
            $local = CarbonImmutable::createFromTimestampUTC($seconds)->setTimezone($timezone);
            $at = $local->utc();
            $on = $local->startOfDay();
        }

        return new OperationRow(
            mid: $wallet,
            paymentId: Values::text($tx['transactionNumber'] ?? $tx['externalTransactionId'] ?? null),
            amount: $amount,
            currency: Values::currency($tx['origCcy'] ?? null),
            ips: 'transfer',
            issuerName: Values::text($tx['remitterBIC'] ?? $tx['remitterBank'] ?? null),
            trnType: 'incoming', // the operations column is 16 characters wide
            resolution: 'completed',
            transactedAt: $at,
            transactedOn: $on,
            raw: $tx,
        );
    }
}
