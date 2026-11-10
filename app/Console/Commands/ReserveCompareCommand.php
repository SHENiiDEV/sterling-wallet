<?php

namespace App\Console\Commands;

use App\Enums\ReportStatus;
use App\Enums\ReserveEntryType;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\ReserveLedgerEntry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Madfin holds its rolling reserve from the processing amount before any fee. Ours is held from what is left after
 * our fees. This shows, from the finished reports, what is held now and what would be held on the processing
 * amount, and what that does to the payout (the conversion fee follows the net volume). Nothing is changed.
 */
#[Signature('reserve:compare {--bank=madfin : Bank provider code} {--mid= : Only this MID (merchant_mids.mid)} {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)} {--detail : One line per report}')]
#[Description('Compare the rolling reserve held now with a reserve taken from the processing amount before fees (read only)')]
class ReserveCompareCommand extends Command
{
    public function handle(): int
    {
        $mids = MerchantMid::query()
            ->with(['merchant:id,name,rolling_reserve_percent', 'bankProvider:id,code'])
            ->whereHas('bankProvider', fn ($q) => $q->where('code', $this->option('bank')))
            ->when($this->option('mid'), fn ($q, $mid) => $q->where('mid', $mid))
            ->orderBy('mid')
            ->get();

        if ($mids->isEmpty()) {
            $this->warn('No MIDs for this bank.');

            return self::SUCCESS;
        }

        $rows = [];
        $detail = [];
        $total = ['turnover' => BigDecimal::zero(), 'now' => BigDecimal::zero(), 'new' => BigDecimal::zero(), 'payout' => BigDecimal::zero()];

        foreach ($mids as $mid) {
            $sum = ['reports' => 0, 'turnover' => BigDecimal::zero(), 'now' => BigDecimal::zero(), 'new' => BigDecimal::zero(), 'payout' => BigDecimal::zero()];

            $reports = DailyReportTask::query()
                ->where('merchant_mid_id', $mid->id)
                ->where('status', ReportStatus::Completed)
                ->when($this->option('from'), fn ($q, $from) => $q->where('report_date', '>=', $from))
                ->when($this->option('to'), fn ($q, $to) => $q->where('report_date', '<=', $to))
                ->orderBy('report_date')
                ->get();

            foreach ($reports as $report) {
                $fee = $report->summary_data['merchant_fee'] ?? [];
                $percent = BigDecimal::of((string) ($fee['reserve_percent'] ?? $mid->merchant->rolling_reserve_percent ?? 0));
                $conversionPercent = BigDecimal::of((string) ($fee['conversion_percent'] ?? 0));

                $turnover = BigDecimal::of($report->getRawOriginal('turnover') ?? 0);
                $returns = BigDecimal::of($report->getRawOriginal('refunds_amount') ?? 0)
                    ->plus($report->getRawOriginal('chargebacks_amount') ?? 0)
                    ->plus($report->getRawOriginal('collabs_amount') ?? 0);
                $processing = BigDecimal::max(BigDecimal::zero(), $turnover->minus($returns));
                $merchantFee = BigDecimal::of($report->getRawOriginal('total_merchant_fee') ?? 0);

                $now = BigDecimal::of($report->getRawOriginal('reserve_amount') ?? 0);
                $new = $processing->multipliedBy($percent)->dividedBy(100, 8, RoundingMode::HalfUp)->toScale(2, RoundingMode::HalfUp);

                // The net volume is what is left after the fees and the reserve; the conversion fee follows it.
                $netNew = BigDecimal::max(BigDecimal::zero(), $processing->minus($merchantFee)->minus($new));
                $payoutNew = $netNew->minus($netNew->multipliedBy($conversionPercent)->dividedBy(100, 8, RoundingMode::HalfUp))->toScale(2, RoundingMode::HalfUp);
                $payoutNow = BigDecimal::of($report->getRawOriginal('net_payout') ?? 0);

                $sum['reports']++;
                $sum['turnover'] = $sum['turnover']->plus($turnover);
                $sum['now'] = $sum['now']->plus($now);
                $sum['new'] = $sum['new']->plus($new);
                $sum['payout'] = $sum['payout']->plus($payoutNew->minus($payoutNow));

                $detail[] = [
                    $mid->mid, $report->report_date->toDateString(), (string) $turnover->toScale(2), (string) $now->toScale(2), (string) $new,
                    $this->signed($new->minus($now)), $this->signed($payoutNew->minus($payoutNow)),
                ];
            }

            if ($sum['reports'] === 0) {
                continue;
            }

            $held = BigDecimal::of((string) ReserveLedgerEntry::query()->where('merchant_mid_id', $mid->id)->whereIn('type', [ReserveEntryType::Hold, ReserveEntryType::Adjustment, ReserveEntryType::Release, ReserveEntryType::Payout])->sum('amount'));
            $rows[] = [
                $mid->mid, $mid->merchant->name, $sum['reports'], (string) $sum['turnover']->toScale(2), (string) $sum['now']->toScale(2),
                (string) $held->toScale(2), (string) $sum['new']->toScale(2), $this->signed($sum['new']->minus($sum['now'])), $this->signed($sum['payout']),
            ];
            foreach (['turnover', 'now', 'new', 'payout'] as $key) {
                $total[$key] = $total[$key]->plus($sum[$key]);
            }
        }

        if ($this->option('detail')) {
            $this->table(['MID', 'Report', 'Processing', 'Reserve now', 'Reserve on processing', 'Reserve diff', 'Payout diff'], $detail);
        }

        $this->table(['MID', 'Merchant', 'Reports', 'Turnover', 'Reserve in the reports', 'Reserve in the ledger', 'Reserve on processing', 'Reserve diff', 'Payout diff'], $rows);
        $this->line(sprintf(
            'Total: turnover %s, reserve now %s, reserve on the processing amount %s (%s). The payout would change by %s in total (the reserve is held more, the conversion fee falls with it).',
            $total['turnover']->toScale(2), $total['now']->toScale(2), $total['new']->toScale(2), $this->signed($total['new']->minus($total['now'])), $this->signed($total['payout']),
        ));
        $this->line('Reserve in the ledger is what is held now (holds, reversals and releases). Caps and per-MID limits are not applied here. Nothing was changed.');

        return self::SUCCESS;
    }

    private function signed(BigDecimal $value): string
    {
        return ($value->isPositive() ? '+' : '').$value->toScale(2, RoundingMode::HalfUp);
    }
}
