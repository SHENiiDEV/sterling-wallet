<?php

namespace App\Reports\Parsers;

use Carbon\CarbonImmutable;

/**
 * One operation from any provider report, normalised. Parsers only fill it;
 * business rules (classification, fees, matching) live elsewhere.
 *
 * Amounts are decimal strings. `transactedAt` is UTC and set only when the
 * file gives a time; `transactedOn` is the calendar day in provider time.
 */
final readonly class OperationRow
{
    /**
     * @param  array<string, string|null>  $fees  eu_fee, non_eu_fee, ic_fee, … as decimal strings
     * @param  array<string, mixed>  $raw  the source row, header => value
     */
    public function __construct(
        public ?string $mid,
        public ?string $merchantName,
        public ?string $paymentId,
        public string $amount,
        public ?string $currency,
        public ?string $providerLogin = null,
        public ?string $arn = null,
        public ?string $rrn = null,
        public ?string $approvalCode = null,
        public ?string $cardMask = null,
        public ?string $cardBin = null,
        public ?string $cardLast4 = null,
        public ?string $ips = null,
        public ?string $wallet = null,
        public ?string $region = null,
        public ?string $issuerCountry = null,
        public ?string $issuerName = null,
        public ?string $trnType = null,
        public ?string $resolution = null,
        public ?string $processingCode = null,
        public ?string $email = null,
        public ?CarbonImmutable $transactedAt = null,
        public ?CarbonImmutable $transactedOn = null,
        public ?CarbonImmutable $processedAt = null,
        public array $fees = [],
        public array $raw = [],
    ) {}
}
