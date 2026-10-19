<?php

namespace App\Reports\Parsers;

use Carbon\CarbonImmutable;
use SplFileInfo;

/**
 * FinBridges (C2B bank transfers): transactions as the public API returns
 * them, `{"count": n, "list": [Transaction …]}` (a bare list works too).
 * Only COMPLETED transactions count (pending ones arrive again once completed):
 * DEPOSIT is a sale, PAYMENT_RETURN a refund, CHARGEBACK a chargeback. Payments
 * out, withdrawals, fees and the other wallet movements are skipped.
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
        if (strtoupper((string) ($tx['status'] ?? '')) !== 'COMPLETED') {
            return null;
        }

        // Customer money only: payments in, returns and chargebacks. The wallet's own
        // movements (payments out, withdrawals, fees, exchanges …) are not clearing data.
        $trnType = match (strtoupper((string) ($tx['transactionType'] ?? ''))) {
            'DEPOSIT' => 'incoming',
            'PAYMENT_RETURN' => 'refund',
            'CHARGEBACK' => 'chargeback',
            default => null,
        };
        if ($trnType === null) {
            return null;
        }

        $amount = Values::decimal($tx['amountOrigCcy'] ?? null);
        if ($amount !== null && $trnType !== 'incoming') {
            $amount = ltrim($amount, '-');
            $amount = '-'.$amount; // returns and chargebacks reduce the turnover
        }
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
            merchantName: null, // firstName / lastName are the sender's, not the merchant's
            paymentId: Values::text($tx['transactionNumber'] ?? $tx['externalTransactionId'] ?? null),
            amount: $amount,
            currency: Values::currency($tx['origCcy'] ?? null),
            ips: 'transfer',
            issuerName: Values::text($tx['remitterBIC'] ?? $tx['remitterBank'] ?? null),
            trnType: $trnType, // the operations column is 16 characters wide
            resolution: 'completed',
            transactedAt: $at,
            transactedOn: $on,
            raw: $tx,
            reference: $this->reference(Values::text($tx['details'] ?? null)),
        );
    }

    /**
     * The number the payer put in the payment description ("Referenznummer: 890661" or just "891153").
     */
    private function reference(?string $details): ?string
    {
        if ($details === null) {
            return null;
        }

        if (preg_match('/(?:referenz\w*|reference|ref\.?)\D{0,6}(\d{3,})/i', $details, $match) === 1
            || preg_match('/^\D{0,3}(\d{3,20})\D{0,3}$/', $details, $match) === 1
            || preg_match('/\b(\d{5,})\b/', $details, $match) === 1) {
            return $match[1];
        }

        return $details;
    }
}
