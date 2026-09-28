<?php

namespace App\Reports\Ingestion;

use App\Models\DailyReportTask;
use Carbon\CarbonImmutable;

final class IngestionResult
{
    /** @var array<int, DailyReportTask> keyed by task id */
    public array $tasks = [];

    /** @var list<string> MIDs seen for the first time; created in `review` */
    public array $unknownMids = [];

    /** @var list<string> */
    public array $warnings = [];

    public int $rows = 0;

    public int $skippedRows = 0;

    public function __construct(
        public ?string $storedPath = null,
        public ?CarbonImmutable $reportDate = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->storedPath,
            'report_date' => $this->reportDate?->toDateString(),
            'rows' => $this->rows,
            'skipped_rows' => $this->skippedRows,
            'unknown_mids' => $this->unknownMids,
            'warnings' => $this->warnings,
            'reports' => array_values(array_map(fn (DailyReportTask $task) => [
                'id' => $task->id,
                'mid' => $task->merchantMid->mid,
                'report_date' => $task->report_date->toDateString(),
                'status' => $task->status->value,
            ], $this->tasks)),
        ];
    }
}
