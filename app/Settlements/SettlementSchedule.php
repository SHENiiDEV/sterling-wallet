<?php

namespace App\Settlements;

use App\Models\DailyReportTask;
use App\Reports\ReportDateResolver;
use Carbon\CarbonImmutable;

/**
 * When a daily report is due for payout: the merchant's T+N, counted in
 * business days (Saturday, Sunday and bank holidays are skipped) from the
 * first day of the report's period. The Friday–Sunday bundle therefore counts
 * from Friday:
 *
 *   T+3:  Mon → Thu · Tue → Fri · Wed → Mon · Thu → Tue · Fri–Sun → Wed
 */
class SettlementSchedule
{
    public const NOT_DUE = 'not_due';

    public const DUE = 'due';

    public const OVERDUE = 'overdue';

    public function __construct(private ReportDateResolver $dates) {}

    public function today(): CarbonImmutable
    {
        return $this->day(CarbonImmutable::now(config('sterling.timezone'))->format('Y-m-d'));
    }

    /**
     * The day to pay the report, or null when its merchant has no schedule.
     */
    public function dueDate(DailyReportTask $report): ?CarbonImmutable
    {
        $report->loadMissing('merchant:id,settlement_lag_days');
        $lag = $report->merchant?->settlement_lag_days;

        return $lag === null ? null : $this->businessDaysAfter($this->day($report->period_from->toDateString()), (int) $lag);
    }

    /**
     * `due` today, `overdue` before today, `not_due` yet; null without a schedule.
     */
    public function state(DailyReportTask $report, ?CarbonImmutable $today = null): ?string
    {
        $due = $this->dueDate($report);
        if ($due === null) {
            return null;
        }

        $today ??= $this->today();

        return match (true) {
            $due->lessThan($today) => self::OVERDUE,
            $due->equalTo($today) => self::DUE,
            default => self::NOT_DUE,
        };
    }

    public function businessDaysAfter(CarbonImmutable $day, int $days): CarbonImmutable
    {
        for ($left = $days; $left > 0;) {
            $day = $day->addDay();
            if (! $this->dates->isClosed($day)) {
                $left--;
            }
        }

        return $day;
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
    }
}
