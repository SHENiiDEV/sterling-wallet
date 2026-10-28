<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/**
 * When an acquirer's file for a report day is due. Cardaq clears T+2 in business days: the file of a day comes two
 * business days later, and the Friday–Sunday bundle (dated Sunday) comes on Tuesday. The gateway's file comes the
 * next day, so for two days the gateway side has sales with nothing to pair with yet: that is waiting, not an error.
 */
class BankFileSchedule
{
    public function __construct(private ReportDateResolver $dates) {}

    public function lagDays(): int
    {
        return (int) config('sterling.bank_file_lag_days', 2);
    }

    /**
     * The business day on which the acquirer's file for this report day should be there.
     */
    public function dueOn(string $reportDate): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $reportDate, 'UTC');

        for ($left = $this->lagDays(); $left > 0;) {
            $day = $day->addDay();
            if (! $this->dates->isClosed($day)) {
                $left--;
            }
        }

        return $day;
    }

    /**
     * Past its due day, so the file counts as missing.
     */
    public function isOverdue(string $reportDate, ?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::createFromFormat('!Y-m-d', CarbonImmutable::now(config('sterling.timezone'))->toDateString(), 'UTC');

        return $this->dueOn($reportDate)->lessThan($today);
    }
}
