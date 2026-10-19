<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Reports\Ingestion\ReportIngestionService;
use App\Reports\ReportDateResolver;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bank-transfer operations from a list (the provider portal while there is no
 * API): booked to the MID's acquirer, one row per payment. Columns:
 * payment_id, reference, amount, currency, transaction_at, processing_at,
 * customer_name, customer_account, customer_bic.
 */
#[Signature('operations:import-manual {file : CSV file with a header row} {--mid= : MID number} {--merchant= : Merchant public id, checked against the MID} {--timezone=UTC : Time zone of the times in the file} {--update : Update rows whose payment ID already exists} {--dry-run : Show what would happen, change nothing}')]
#[Description('Add (or update) bank-transfer operations of a MID from a CSV list')]
class ImportManualOperationsCommand extends Command
{
    public function handle(ReportIngestionService $ingestion, ReportDateResolver $dates): int
    {
        $mid = MerchantMid::query()->with('merchant', 'bankProvider')->where('mid', (string) $this->option('mid'))->first();
        if ($mid === null || $mid->bankProvider === null) {
            $this->error('Give --mid=<MID number> of a MID that has an acquirer.');

            return self::FAILURE;
        }
        if ($this->option('merchant') && $mid->merchant?->public_id !== $this->option('merchant')) {
            $this->error("MID {$mid->mid} does not belong to merchant {$this->option('merchant')}.");

            return self::FAILURE;
        }

        $rows = $this->rows((string) $this->argument('file'));
        if ($rows === null) {
            return self::FAILURE;
        }

        $provider = $mid->bankProvider;
        $timezone = (string) $this->option('timezone');
        $dry = (bool) $this->option('dry-run');
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $days = [];

        foreach ($rows as $line => $row) {
            try {
                $at = CarbonImmutable::parse((string) ($row['transaction_at'] ?? ''), $timezone)->utc();
                $processed = filled($row['processing_at'] ?? null) ? CarbonImmutable::parse($row['processing_at'], $timezone)->utc() : null;
            } catch (Throwable) {
                $this->warn("Row {$line}: unreadable time, skipped.");
                $summary['skipped']++;

                continue;
            }
            if (! is_numeric($row['amount'] ?? null) || blank($row['payment_id'] ?? null) || blank($row['transaction_at'] ?? null)) {
                $this->warn("Row {$line}: payment_id and a numeric amount are required, skipped.");
                $summary['skipped']++;

                continue;
            }

            $day = $at->setTimezone($provider->timezone ?: config('sterling.timezone'))->startOfDay();
            $period = $dates->periodFor($day);
            $payer = array_filter([
                'remitterName' => $row['customer_name'] ?? null,
                'remitterAccount' => $row['customer_account'] ?? null,
                'remitterBIC' => $row['customer_bic'] ?? null,
            ], 'filled');

            $existing = MerchantOperation::query()->where('provider_id', $provider->id)->where('payment_id', $row['payment_id'])->first();
            if ($existing !== null && ! $this->option('update')) {
                $this->line("Row {$line}: {$row['payment_id']} already exists (use --update to change it).");
                $summary['skipped']++;

                continue;
            }

            $attributes = [
                'reference' => filled($row['reference'] ?? null) ? trim((string) $row['reference']) : null,
                'amount' => $row['amount'],
                'currency' => strtoupper(($row['currency'] ?? null) ?: $mid->currency->value),
                'transaction_at' => $at,
                'processing_at' => $processed,
                'report_date' => $period->reportDate,
                'issuer_name' => ($row['customer_bic'] ?? null) ?: null,
            ];

            $this->line(sprintf('Row %d: %s %s %s ref %s → %s', $line, $existing ? 'update' : 'add', $row['payment_id'], $attributes['amount'].' '.$attributes['currency'], $attributes['reference'] ?? '—', $period->reportDate->toDateString()));
            if ($dry) {
                continue;
            }

            DB::transaction(function () use ($existing, $mid, $provider, $attributes, $row, $payer, &$summary) {
                if ($existing !== null) {
                    $raw = [...($existing->raw ?? []), ...$payer];
                    $raw['edits'] = [...($raw['edits'] ?? []), ['by' => 'operations:import-manual', 'at' => now()->toIso8601String(), 'reason' => 'Updated from list']];
                    $existing->update([...$attributes, 'raw' => $raw]);
                    $summary['updated']++;
                    $operation = $existing;
                } else {
                    $operation = MerchantOperation::query()->create([
                        ...$attributes,
                        'merchant_id' => $mid->merchant_id,
                        'merchant_mid_id' => $mid->id,
                        'provider_id' => $provider->id,
                        'role' => $provider->type,
                        'mid' => $mid->mid,
                        'payment_id' => $row['payment_id'],
                        'operation_type' => OperationType::Sale,
                        'trn_type' => 'incoming',
                        'ips' => 'transfer',
                        'resolution' => 'completed',
                        'raw' => [...$payer, 'manual' => true, 'added_by' => 'operations:import-manual', 'added_at' => now()->toIso8601String()],
                    ]);
                    $summary['created']++;
                }
                AuditLogger::log($existing ? 'operation.updated' : 'operation.created', $operation, ['source' => 'operations:import-manual', 'payment_id' => $operation->payment_id]);
            });

            $days[$period->reportDate->toDateString()] = $day;
        }

        if (! $dry) {
            foreach ($days as $date => $day) {
                $task = $ingestion->ensureReport($provider, $mid, $day);
                $this->line("Report {$date}: #{$task->id} ({$task->fresh()->status->value})");
            }
        }

        $this->info(sprintf('%s%d added, %d updated, %d skipped.', $dry ? 'Dry run: ' : '', $summary['created'], $summary['updated'], $summary['skipped']));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string|null>>|null line number => row
     */
    private function rows(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return null;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');
        if (! is_array($header)) {
            $this->error('The file is empty.');

            return null;
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header);

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            $rows[$line] = array_combine($header, array_slice(array_pad(array_map(fn ($c) => $c === null ? null : trim($c), $cells), count($header), null), 0, count($header)));
        }
        fclose($handle);

        return $rows;
    }
}
