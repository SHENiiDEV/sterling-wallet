<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/**
 * The days one daily report covers. `reportDate` is always `to`.
 */
final readonly class ReportPeriod
{
    public function __construct(
        public CarbonImmutable $reportDate,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public function contains(CarbonImmutable $day): bool
    {
        return $day->betweenIncluded($this->from, $this->to);
    }

    /**
     * @return array{report_date: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'report_date' => $this->reportDate->toDateString(),
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }
}
