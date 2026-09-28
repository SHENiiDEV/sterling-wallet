<?php

namespace App\Settlements;

use App\Enums\ReserveEntryType;
use App\Models\DailyReportTask;
use App\Models\ReserveLedgerEntry;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gives back rolling reserve whose hold period is over: one `release` entry
 * per daily report, added to the merchant's open draft settlement.
 */
class ReserveReleaseService
{
    public function __construct(private SettlementService $settlements) {}

    /**
     * @return list<ReserveLedgerEntry> the releases created
     */
    public function releaseDue(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(config('sterling.timezone'));

        $taskIds = ReserveLedgerEntry::query()
            ->where('type', ReserveEntryType::Hold)
            ->whereNotNull('daily_report_task_id')
            ->where('release_on', '<=', $today->toDateString())
            ->whereNotIn('daily_report_task_id', ReserveLedgerEntry::query()
                ->select('daily_report_task_id')
                ->where('type', ReserveEntryType::Release)
                ->whereNotNull('daily_report_task_id'))
            ->distinct()
            ->pluck('daily_report_task_id');

        $released = [];
        foreach ($taskIds as $taskId) {
            $release = DB::transaction(function () use ($taskId) {
                $entries = ReserveLedgerEntry::query()->where('daily_report_task_id', $taskId)->lockForUpdate()->get();
                $held = $entries->whereIn('type', [ReserveEntryType::Hold, ReserveEntryType::Adjustment])
                    ->reduce(fn (BigDecimal $c, ReserveLedgerEntry $e) => $c->plus($e->amount), BigDecimal::zero());

                if (! $held->isPositive() || $entries->contains('type', ReserveEntryType::Release)) {
                    return null;
                }

                /** @var ReserveLedgerEntry $hold */
                $hold = $entries->where('type', ReserveEntryType::Hold)->sortByDesc('id')->first();
                $task = DailyReportTask::query()->find($taskId);

                $release = ReserveLedgerEntry::query()->create([
                    'merchant_id' => $hold->merchant_id,
                    'merchant_mid_id' => $hold->merchant_mid_id,
                    'daily_report_task_id' => $taskId,
                    'currency' => $hold->currency,
                    'type' => ReserveEntryType::Release,
                    'amount' => (string) $held->negated(),
                    'note' => 'Held for report '.($task?->report_date->toDateString() ?? '#'.$taskId),
                ]);

                $settlement = $this->settlements->openDraft($hold->merchantMid->merchant);
                $this->settlements->addRelease($settlement, $release);
                $this->settlements->recalculate($settlement);

                return $release;
            });

            if ($release !== null) {
                $released[] = $release;
            }
        }

        return $released;
    }
}
