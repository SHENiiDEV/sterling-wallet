<?php

namespace App\Reports\Ingestion;

use App\Enums\MerchantStatus;
use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Jobs\GenerateDailyReportJob;
use App\Models\BotRun;
use App\Models\DailyReportSource;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Reports\Parsers\OperationRow;
use App\Reports\Parsers\ParserRegistry;
use App\Reports\ReportDateResolver;
use App\Reports\ReportPeriod;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SplFileInfo;

/**
 * Takes one provider file, whatever the provider: parses it, splits rows by
 * MID, upserts operations, records the file on each affected daily report
 * and queues generation once a report has every source its MID needs.
 */
class ReportIngestionService
{
    /** @var array<string, MerchantMid|null> */
    private array $midCache = [];

    public function __construct(
        private ParserRegistry $parsers,
        private ReportDateResolver $dates,
    ) {}

    /**
     * @param  CarbonImmutable|null  $hintDate  report date the caller expects (bot task), used when the file doesn't say
     * @param  iterable<MerchantMid>  $coveredMids  MIDs this file is known to cover: they are marked as received even
     *                                              with zero rows, and rows without a MID go to the single covered MID
     */
    public function ingest(Provider $provider, SplFileInfo $file, ?CarbonImmutable $hintDate = null, ?BotRun $run = null, iterable $coveredMids = []): IngestionResult
    {
        if ($provider->type === ProviderType::Crypto) {
            throw new InvalidArgumentException('Crypto providers do not send operation reports.');
        }

        $this->midCache = [];
        $parser = $this->parsers->for($provider);
        $timezone = $provider->timezone ?: config('sterling.timezone');
        $fileDate = $parser->reportDate($file);
        $anchor = $fileDate ?? $hintDate;
        $filePeriod = $anchor ? $this->dates->periodFor($anchor) : null;

        $result = new IngestionResult($this->store($provider, $file, $filePeriod), $filePeriod?->reportDate);
        $covered = collect($coveredMids)->keyBy('id');

        /** @var array<string, array{mid: MerchantMid, period: ReportPeriod, rows: int}> $touched */
        $touched = [];

        DB::transaction(function () use ($provider, $parser, $file, $timezone, $filePeriod, $covered, $result, $run, &$touched) {
            foreach ($parser->rows($file, $timezone) as $row) {
                $mid = $this->resolveMid($provider, $row, $covered, $result);
                $period = $filePeriod ?? ($row->transactedOn ? $this->dates->periodFor($row->transactedOn) : null);

                if ($mid === null || $period === null) {
                    $result->skippedRows++;

                    continue;
                }

                $this->upsertOperation($provider, $mid, $period, $row);
                $result->rows++;

                $key = $mid->id.'|'.$period->reportDate->toDateString();
                $touched[$key] ??= ['mid' => $mid, 'period' => $period, 'rows' => 0];
                $touched[$key]['rows']++;
            }

            if ($filePeriod !== null) {
                foreach ($covered as $mid) {
                    $touched[$mid->id.'|'.$filePeriod->reportDate->toDateString()] ??= ['mid' => $mid, 'period' => $filePeriod, 'rows' => 0];
                }
            }

            foreach ($touched as $entry) {
                $task = $this->recordSource($provider, $entry['mid'], $entry['period'], $result->storedPath, $entry['rows'], $run);
                $result->tasks[$task->id] = $task;
            }
        });

        if ($result->skippedRows > 0) {
            $result->warnings[] = "{$result->skippedRows} row(s) had no MID or date and were skipped.";
        }

        foreach ($result->tasks as $task) {
            $this->advance($task);
        }

        return $result;
    }

    /**
     * The provider confirmed there is nothing for these MIDs in this period
     * (e.g. a Corefy export with 0 rows): mark the source as received.
     *
     * @param  iterable<MerchantMid>  $mids
     * @return list<DailyReportTask>
     */
    public function markEmpty(Provider $provider, CarbonImmutable $date, iterable $mids, ?BotRun $run = null): array
    {
        $period = $this->dates->periodFor($date);
        $tasks = [];

        foreach ($mids as $mid) {
            $tasks[] = $task = $this->recordSource($provider, $mid, $period, null, 0, $run);
            $this->advance($task);
        }

        return $tasks;
    }

    /**
     * Queue generation when every required source is in, otherwise mark partial.
     */
    public function advance(DailyReportTask $task): void
    {
        $task->loadMissing('merchantMid');

        if ($task->missingProviderIds() === []) {
            if ($task->status !== ReportStatus::Completed) {
                $task->update(['status' => ReportStatus::Pending, 'error_log' => null]);
            }
            GenerateDailyReportJob::dispatch($task->id);

            return;
        }

        if ($task->status !== ReportStatus::Completed) {
            $task->update(['status' => ReportStatus::Partial]);
        }
    }

    private function store(Provider $provider, SplFileInfo $file, ?ReportPeriod $period): string
    {
        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename();
        $name = now()->format('His').'_'.Str::limit(preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'report', 100, '');
        $directory = sprintf('reports/raw/%s/%s', $provider->code, $period?->reportDate->toDateString() ?? 'undated');

        return Storage::disk(config('sterling.reports.disk'))
            ->putFileAs($directory, new File($file->getPathname()), $name) ?: $directory.'/'.$name;
    }

    /**
     * @param  Collection<int, MerchantMid>  $covered
     */
    private function resolveMid(Provider $provider, OperationRow $row, Collection $covered, IngestionResult $result): ?MerchantMid
    {
        if ($row->mid === null) {
            return $covered->count() === 1 ? $covered->first() : null;
        }

        $cacheKey = $row->mid.'|'.$row->currency;
        if (array_key_exists($cacheKey, $this->midCache)) {
            return $this->midCache[$cacheKey];
        }

        $isGate = $provider->type === ProviderType::Gate;
        $candidates = MerchantMid::query()
            ->where($isGate ? 'gate_provider_id' : 'bank_provider_id', $provider->id)
            ->where(fn ($q) => $isGate
                ? $q->where('gate_mid', $row->mid)->orWhere('mid', $row->mid)
                : $q->where('mid', $row->mid))
            ->get();

        // One gateway account can front several MIDs, one per currency.
        $mid = $candidates->count() > 1 && $row->currency
            ? ($candidates->first(fn (MerchantMid $m) => $m->currency->value === $row->currency) ?? $candidates->first())
            : $candidates->first();

        if ($mid === null && ! $isGate && ! MerchantMid::query()->where('mid', $row->mid)->exists()) {
            $mid = $this->createUnknownMid($provider, $row);
            $result->unknownMids[] = $row->mid;
        } elseif ($mid === null) {
            $result->warnings[] = "MID {$row->mid} is not linked to {$provider->name}; its rows were skipped.";
        }

        return $this->midCache[$cacheKey] = $mid;
    }

    /**
     * A MID nobody set up yet: park it with its merchant in `review` so the
     * data is kept, the dashboard warns, and no report is calculated.
     */
    private function createUnknownMid(Provider $provider, OperationRow $row): MerchantMid
    {
        $merchant = Merchant::query()->create([
            'name' => $row->merchantName ?? "Unknown merchant ({$row->mid})",
            'status' => MerchantStatus::Review,
            ...config('sterling.merchant_defaults'),
        ]);

        /** @var MerchantMid $mid */
        $mid = $merchant->mids()->create([
            'mid' => $row->mid,
            'provider_login' => $row->providerLogin,
            'currency' => $row->currency ?? config('sterling.base_currency'),
            'status' => MidStatus::Review,
            'bank_provider_id' => $provider->id,
            'notes' => 'Created automatically from a '.$provider->name.' report.',
        ]);

        AuditLogger::log('mid.auto_created', $mid, ['provider' => $provider->code]);

        return $mid;
    }

    private function upsertOperation(Provider $provider, MerchantMid $mid, ReportPeriod $period, OperationRow $row): void
    {
        $attributes = [
            'merchant_id' => $mid->merchant_id,
            'merchant_mid_id' => $mid->id,
            'role' => $provider->type,
            'mid' => $row->mid ?? $mid->mid,
            'merchant_name' => $row->merchantName,
            'arn' => $row->arn,
            'rrn' => $row->rrn,
            'approval_code' => $row->approvalCode,
            'card_mask' => $row->cardMask ? Str::limit($row->cardMask, 32, '') : null,
            'card_bin' => $row->cardBin,
            'card_last4' => $row->cardLast4,
            'customer_email' => $row->email,
            'ips' => $row->ips,
            'region' => $row->region,
            'issuer_country' => $row->issuerCountry,
            'issuer_name' => $row->issuerName,
            'operation_type' => MerchantOperation::classify([
                'trn_type' => $row->trnType,
                'amount' => $row->amount,
                'decline_fee' => $row->fees['decline_fee'] ?? null,
                'processing_code' => $row->processingCode,
                'resolution' => $row->resolution,
            ]),
            'processing_code' => $row->processingCode,
            'resolution' => $row->resolution,
            'report_date' => $period->reportDate,
            'transaction_at' => $row->transactedAt,
            'processing_at' => $row->processedAt,
            'currency' => $row->currency ?? $mid->currency->value,
            'raw' => $row->raw,
            ...array_filter($row->fees, fn ($fee) => $fee !== null),
        ];

        MerchantOperation::query()->updateOrCreate([
            'provider_id' => $provider->id,
            'payment_id' => $this->paymentId($row),
            'trn_type' => $row->trnType,
            'amount' => $row->amount,
        ], $attributes);
    }

    /**
     * Dedupe key part: the provider's id, else ARN/RRN, else a hash of the row.
     */
    private function paymentId(OperationRow $row): string
    {
        return $row->paymentId ?? $row->arn ?? $row->rrn ?? 'h:'.substr(sha1((string) json_encode($row->raw)), 0, 32);
    }

    private function recordSource(Provider $provider, MerchantMid $mid, ReportPeriod $period, ?string $path, int $rows, ?BotRun $run): DailyReportTask
    {
        /** @var DailyReportTask $task */
        $task = DailyReportTask::query()->firstOrCreate(
            ['merchant_mid_id' => $mid->id, 'report_date' => $period->reportDate->toDateString()],
            [
                'merchant_id' => $mid->merchant_id,
                'period_from' => $period->from,
                'period_to' => $period->to,
                'currency' => $mid->currency->value,
                'status' => ReportStatus::Pending,
            ],
        );

        $source = DailyReportSource::query()->firstOrNew([
            'daily_report_task_id' => $task->id,
            'provider_id' => $provider->id,
        ]);

        // Re-uploads of the same day add up only within one file; a new file replaces the count.
        $source->fill([
            'role' => $mid->roleOf($provider) ?? $provider->type,
            'file_path' => $path ?? $source->file_path,
            'received_at' => now(),
            'rows_count' => $path !== null && $source->exists && $source->file_path === $path ? $source->rows_count + $rows : $rows,
            'bot_run_id' => $run->id ?? $source->bot_run_id,
        ])->save();

        return $task->setRelation('merchantMid', $mid);
    }
}
