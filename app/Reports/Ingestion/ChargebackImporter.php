<?php

namespace App\Reports\Ingestion;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Reports\Parsers\Values;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SplFileInfo;

/**
 * The acquirer's chargeback list (Madfin / Visa: VROL number, ARN, transaction date, card, amount, reason, transaction UID).
 * Each row is found among the payments the bank reported (by its transaction UID, else the ARN) and becomes a
 * `chargeback` operation of that payment's MID: the amount is taken from the merchant, plus the chargeback fee.
 * It lands on the report of the original payment's day; when that report is already in a settlement (being paid or
 * paid) the amounts there are fixed, so it moves on to the next report that is not, up to today's. The rows that
 * match no payment are listed, not guessed.
 */
class ChargebackImporter
{
    private const REQUIRED = ['VROL NUMBER', 'TRANSACTION UID', 'TRX AMOUNT'];

    public function __construct(private ReportIngestionService $ingestion, private CollabImporter $placement) {}

    /**
     * Whether the file is a chargeback list: its header names the VROL number and the transaction UID.
     */
    public static function looksLikeChargebacks(SplFileInfo $file): bool
    {
        if (strtolower($file->getExtension()) === 'xlsx' || ! is_readable($file->getPathname())) {
            return false;
        }

        $handle = fopen($file->getPathname(), 'rb');
        $header = $handle ? fgetcsv($handle) : false;
        $handle && fclose($handle);

        return is_array($header) && array_diff(self::REQUIRED, array_map(fn ($h) => strtoupper(trim((string) $h, "\xEF\xBB\xBF \t")), $header)) === [];
    }

    /**
     * @return array{rows: int, tasks: int, not_found: list<string>, placed: list<array{vrol: string, report_date: string, amount: string, currency: string}>}
     */
    public function import(Provider $provider, SplFileInfo $file): array
    {
        $timezone = $provider->timezone ?: config('sterling.timezone');
        $handle = fopen($file->getPathname(), 'rb');
        $header = array_map(fn ($h) => strtoupper(trim((string) $h, "\xEF\xBB\xBF \t")), (array) fgetcsv($handle));

        $result = ['rows' => 0, 'tasks' => 0, 'not_found' => [], 'placed' => []];
        $tasks = [];

        DB::transaction(function () use ($handle, $header, $provider, $timezone, &$result, &$tasks) {
            while (($cells = fgetcsv($handle)) !== false) {
                if (count($cells) < count($header)) {
                    continue;
                }
                $row = array_combine($header, array_slice($cells, 0, count($header)));
                $vrol = trim((string) ($row['VROL NUMBER'] ?? ''));
                $amount = Values::decimal($row['TRX AMOUNT'] ?? null);
                if ($vrol === '' || $amount === null) {
                    continue;
                }

                $original = $this->original($provider, trim((string) ($row['TRANSACTION UID'] ?? '')), trim((string) ($row['ARN'] ?? '')));
                if ($original === null) {
                    $result['not_found'][] = $vrol;

                    continue;
                }

                $mid = $original->merchantMid;
                $key = ['provider_id' => $provider->id, 'payment_id' => 'chargeback:'.$vrol, 'trn_type' => 'chargeback', 'amount' => $amount];

                // A chargeback that is already booked stays where it is: a second upload must not move it.
                $booked = MerchantOperation::query()->where($key)->first();
                $task = $booked?->report_date !== null
                    ? $this->ingestion->ensureReport($provider, $mid, CarbonImmutable::createFromFormat('!Y-m-d', $booked->report_date->toDateString(), 'UTC'))
                    : $this->placement->reportFor($provider, $mid, $original->transaction_at, $timezone);
                $tasks[$task->id] = $task;

                MerchantOperation::query()->updateOrCreate($key, [
                    'merchant_id' => $mid->merchant_id,
                    'merchant_mid_id' => $mid->id,
                    'role' => ProviderType::Bank,
                    'mid' => $mid->mid,
                    'merchant_name' => $original->merchant_name,
                    'reference' => $original->payment_id,
                    'arn' => $original->arn,
                    'rrn' => $original->rrn,
                    'approval_code' => $original->approval_code,
                    'card_mask' => $original->card_mask,
                    'card_bin' => $original->card_bin,
                    'card_last4' => $original->card_last4,
                    'ips' => $original->ips,
                    'region' => $original->region,
                    'issuer_country' => $original->issuer_country,
                    'operation_type' => OperationType::Chargeback,
                    'resolution' => Values::text($row['REASON CODE'] ?? null),
                    'report_date' => $task->report_date,
                    'transaction_at' => $original->transaction_at,
                    'currency' => strtoupper(trim((string) ($row['CURRENCY'] ?? ''))) ?: $mid->currency->value,
                    // Kept as the file gave it, with the payment it was found on.
                    'raw' => [...$row, 'original_operation_id' => $original->id, 'original_report_date' => $original->report_date?->toDateString()],
                ]);

                $result['rows']++;
                $result['placed'][] = ['vrol' => $vrol, 'report_date' => $task->report_date->toDateString(), 'amount' => $amount, 'currency' => (string) ($row['CURRENCY'] ?? '')];
            }
        });
        fclose($handle);

        // A report that was already built has to be built again to carry the charge.
        foreach ($tasks as $task) {
            $task->loadMissing('merchantMid');
            $this->ingestion->advance($task);
        }

        $result['tasks'] = count($tasks);

        return $result;
    }

    /**
     * The payment a chargeback is about: the acquirer's own transaction id first, the ARN when that finds nothing.
     */
    private function original(Provider $provider, string $uid, string $arn): ?MerchantOperation
    {
        $find = fn (string $column, string $value) => $value === '' ? null : MerchantOperation::query()
            ->with('merchantMid')
            ->where('provider_id', $provider->id)->where('role', ProviderType::Bank)
            ->where('operation_type', OperationType::Sale)
            ->where($column, $value)
            ->orderBy('id')->first();

        return $find('payment_id', $uid) ?? $find('arn', $arn);
    }
}
