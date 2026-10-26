<?php

namespace App\Reports\Ingestion;

use App\Enums\OperationType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Reports\Parsers\Values;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use SplFileInfo;
use Throwable;

/**
 * Mastercard Collaboration cases the acquirer mails as a CSV (`mc_collab`): one row per case with the
 * amount requested back, the deadline to answer and the original payment. Each becomes a `collab`
 * operation on the day the case was opened, charged to the merchant on that day's report.
 */
class CollabImporter
{
    private const REQUIRED = ['MERCHANT_ID', 'COLLABORATION_ID', 'COLLAB_AMOUNT'];

    public function __construct(private ReportIngestionService $ingestion) {}

    /**
     * Whether the file is a Collab list: its header names the collaboration columns.
     */
    public static function looksLikeCollab(SplFileInfo $file): bool
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
     * @return array{rows: int, skipped: int, unknown_mids: list<string>, tasks: int}
     */
    public function import(Provider $provider, SplFileInfo $file): array
    {
        $timezone = $provider->timezone ?: config('sterling.timezone');
        $handle = fopen($file->getPathname(), 'rb');
        $header = array_map(fn ($h) => strtoupper(trim((string) $h, "\xEF\xBB\xBF \t")), (array) fgetcsv($handle));

        $result = ['rows' => 0, 'skipped' => 0, 'unknown_mids' => [], 'tasks' => 0];
        $tasks = [];

        DB::transaction(function () use ($handle, $header, $provider, $timezone, &$result, &$tasks) {
            while (($cells = fgetcsv($handle)) !== false) {
                // The file ends with "-----mc_collab:footer-----" and a summary line.
                if (count($cells) < count($header) || str_starts_with((string) ($cells[0] ?? ''), '-----')) {
                    continue;
                }

                $row = array_combine($header, array_slice(array_pad($cells, count($header), null), 0, count($header)));
                // The footer lines ("rows=12,timestamp=…") carry no case.
                if (trim((string) $row['COLLABORATION_ID']) === '' || ! is_numeric($row['COLLAB_AMOUNT'])) {
                    continue;
                }

                $mid = MerchantMid::query()->where('bank_provider_id', $provider->id)->where('mid', trim((string) $row['MERCHANT_ID']))->first();
                if ($mid === null) {
                    $result['skipped']++;
                    $result['unknown_mids'][] = (string) $row['MERCHANT_ID'];

                    continue;
                }

                $created = $this->time($row['COLLAB_CREATE_DATE'] ?? null, $timezone);
                $day = ($created ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();
                $task = $this->ingestion->ensureReport($provider, $mid, CarbonImmutable::createFromFormat('!Y-m-d', $day->toDateString(), 'UTC'));
                $tasks[$task->id] = $task;

                [$mask, $bin, $last4] = Values::card($row['CARD'] ?? null);

                MerchantOperation::query()->updateOrCreate(
                    ['provider_id' => $provider->id, 'payment_id' => 'collab:'.trim((string) $row['COLLABORATION_ID']), 'trn_type' => 'collab', 'amount' => $row['COLLAB_AMOUNT']],
                    [
                        'merchant_id' => $mid->merchant_id,
                        'merchant_mid_id' => $mid->id,
                        'role' => $provider->type,
                        'mid' => $mid->mid,
                        'merchant_name' => Values::text($row['MERCHANT_NAME'] ?? null),
                        'reference' => Values::text($row['PAYMENT_ID'] ?? null),
                        'arn' => Values::text($row['ACQ_REF_NR'] ?? null),
                        'rrn' => Values::text($row['TR_RET_REF_NR'] ?? null),
                        'card_mask' => $mask,
                        'card_bin' => $bin,
                        'card_last4' => $last4,
                        'ips' => Values::ips($row['CARD_TYPE'] ?? null, $bin),
                        'operation_type' => OperationType::Collab,
                        'report_date' => $task->report_date,
                        'transaction_at' => $created,
                        'processing_at' => $this->time($row['COLLAB_RESPONSE_DEADLINE'] ?? null, $timezone),
                        'currency' => strtoupper(trim((string) ($row['COLLAB_CCY'] ?? ''))) ?: $mid->currency->value,
                        // Kept as the file gave it: claim and collaboration ids, the original payment and its amount.
                        'raw' => $row,
                    ],
                );
                $result['rows']++;
            }
        });
        fclose($handle);

        // A report that was already built has to be built again to carry the charge.
        foreach ($tasks as $task) {
            $task->loadMissing('merchantMid');
            $this->ingestion->advance($task);
        }

        $result['tasks'] = count($tasks);
        $result['unknown_mids'] = array_values(array_unique($result['unknown_mids']));

        return $result;
    }

    private function time(mixed $value, string $timezone): ?CarbonImmutable
    {
        $text = Values::text($value);
        if ($text === null) {
            return null;
        }

        try {
            $parsed = DateTimeImmutable::createFromFormat('!Y.m.d H:i:s', $text, new DateTimeZone($timezone));

            return $parsed !== false ? CarbonImmutable::instance($parsed)->utc() : CarbonImmutable::parse($text, $timezone)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
