<?php

namespace App\Settlements;

use App\Enums\ReportStatus;
use App\Enums\ReserveEntryType;
use App\Enums\SettlementLineType;
use App\Enums\SettlementStatus;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\ReserveLedgerEntry;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\User;
use App\Reports\Generation\FxRateResolver;
use App\Services\AuditLogger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One payout to a merchant: draft → approved → settled, or cancelled.
 * A daily report or a reserve release belongs to at most one active
 * (non-cancelled) settlement; cancelling frees them again.
 */
class SettlementService
{
    public function __construct(private FxRateResolver $fx) {}

    /**
     * Completed reports of the merchant that no active settlement pays yet.
     *
     * @return Collection<int, DailyReportTask>
     */
    public function availableReports(Merchant $merchant): Collection
    {
        return DailyReportTask::query()
            ->with('merchantMid:id,mid,currency')
            ->where('merchant_id', $merchant->id)
            ->where('status', ReportStatus::Completed)
            ->whereNotIn('id', $this->activeLineRefs(SettlementLineType::Report, 'daily_report_task_id'))
            ->orderBy('report_date')
            ->get();
    }

    /**
     * Reserve releases of the merchant that no active settlement pays yet.
     *
     * @return Collection<int, ReserveLedgerEntry>
     */
    public function availableReleases(Merchant $merchant): Collection
    {
        return ReserveLedgerEntry::query()
            ->with('merchantMid:id,mid')
            ->where('merchant_id', $merchant->id)
            ->where('type', ReserveEntryType::Release)
            ->whereNotIn('id', $this->activeLineRefs(SettlementLineType::ReserveRelease, 'reserve_ledger_entry_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>|null  $reportIds  null = every available report
     */
    public function createDraft(Merchant $merchant, ?User $by, ?array $reportIds = null, bool $withReleases = true): Settlement
    {
        return DB::transaction(function () use ($merchant, $by, $reportIds, $withReleases) {
            $settlement = Settlement::query()->create([
                'number' => 'SET-TMP-'.uniqid(),
                'merchant_id' => $merchant->id,
                'status' => SettlementStatus::Draft,
                'rates' => [],
                'wallet_id' => $merchant->wallets()->where('is_active', true)->value('id'),
                'created_by' => $by?->id,
            ]);
            $settlement->update(['number' => sprintf('SET-%s-%06d', now()->format('Y'), $settlement->id)]);

            $reports = $this->availableReports($merchant);
            if ($reportIds !== null) {
                $reports = $reports->whereIn('id', $reportIds);
            }
            $this->addReports($settlement, $reports);

            if ($withReleases) {
                foreach ($this->availableReleases($merchant) as $release) {
                    $this->addRelease($settlement, $release);
                }
            }

            AuditLogger::log('settlement.created', $settlement, ['lines' => $settlement->lines()->count()]);

            return $this->recalculate($settlement);
        });
    }

    /**
     * @param  iterable<DailyReportTask>  $reports
     */
    public function addReports(Settlement $settlement, iterable $reports): void
    {
        $this->assertEditable($settlement);
        $taken = $this->activeLineRefs(SettlementLineType::Report, 'daily_report_task_id')->pluck('daily_report_task_id')->all();

        foreach ($reports as $report) {
            if ($report->merchant_id !== $settlement->merchant_id || $report->status !== ReportStatus::Completed || in_array($report->id, $taken, true)) {
                continue;
            }
            $report->loadMissing('merchantMid');

            $settlement->lines()->create([
                'type' => SettlementLineType::Report,
                'daily_report_task_id' => $report->id,
                'description' => sprintf('Daily report %s · MID %s', $report->report_date->toDateString(), $report->merchantMid->mid),
                'currency' => $report->currency,
                'amount' => $report->getRawOriginal('net_payout'),
            ]);
        }
    }

    public function addRelease(Settlement $settlement, ReserveLedgerEntry $release): SettlementLine
    {
        $this->assertEditable($settlement);
        $release->loadMissing('merchantMid');

        return $settlement->lines()->create([
            'type' => SettlementLineType::ReserveRelease,
            'reserve_ledger_entry_id' => $release->id,
            'daily_report_task_id' => $release->daily_report_task_id,
            'description' => 'Rolling reserve release · MID '.($release->merchantMid->mid ?? '—').($release->note ? ' · '.$release->note : ''),
            'currency' => $release->currency,
            'amount' => (string) BigDecimal::of($release->amount)->abs(),
        ]);
    }

    /**
     * Manual credit (positive) or debit (negative), e.g. "Previous overpayment".
     */
    public function addAdjustment(Settlement $settlement, string $description, string $currency, string $amount): SettlementLine
    {
        $this->assertEditable($settlement);

        $line = $settlement->lines()->create([
            'type' => SettlementLineType::Adjustment,
            'description' => $description,
            'currency' => strtoupper($currency),
            'amount' => $amount,
        ]);
        $this->recalculate($settlement);

        return $line;
    }

    public function removeLine(SettlementLine $line): void
    {
        $settlement = $line->settlement;
        $this->assertEditable($settlement);
        $line->delete();
        $this->recalculate($settlement);
    }

    /**
     * @param  array<string, string|float|int>  $rates  currency => payout units per 1 currency unit
     */
    public function setRates(Settlement $settlement, array $rates): Settlement
    {
        $this->assertEditable($settlement);
        $settlement->update(['rates' => array_map(fn ($r) => (string) $r, array_change_key_case($rates, CASE_UPPER))]);

        return $this->recalculate($settlement);
    }

    /**
     * Fill missing rates with the latest known rate to the payout currency,
     * then convert every line and the total.
     */
    public function recalculate(Settlement $settlement): Settlement
    {
        $settlement->refresh();
        $rates = $settlement->rates ?? [];
        $today = CarbonImmutable::now();

        foreach ($settlement->lines()->distinct()->pluck('currency') as $currency) {
            if (! isset($rates[$currency])) {
                $rate = $this->fx->rate($currency, $settlement->payout_currency, $today)
                    // USDC is a USD stablecoin: 1:1 unless a rate says otherwise.
                    ?? ($currency === 'USD' && $settlement->payout_currency === 'USDC' ? BigDecimal::one() : null);
                if ($rate !== null) {
                    $rates[$currency] = (string) $rate->toScale(8, RoundingMode::HalfUp)->strippedOfTrailingZeros();
                }
            }
        }

        $total = BigDecimal::zero();
        foreach ($settlement->lines as $line) {
            $rate = isset($rates[$line->currency]) ? BigDecimal::of($rates[$line->currency]) : BigDecimal::zero();
            $payout = BigDecimal::of($line->amount)->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp);
            $line->update(['rate' => (string) $rate, 'amount_payout' => (string) $payout]);
            $total = $total->plus($payout);
        }

        $settlement->update(['rates' => $rates, 'total_payout' => (string) $total]);

        return $settlement->refresh();
    }

    /**
     * Currencies used by lines that still have no rate.
     *
     * @return list<string>
     */
    public function missingRates(Settlement $settlement): array
    {
        $rates = $settlement->rates ?? [];

        return array_values(array_filter(
            $settlement->lines()->distinct()->pluck('currency')->all(),
            fn (string $currency) => ! isset($rates[$currency]) || BigDecimal::of($rates[$currency])->isZero(),
        ));
    }

    public function approve(Settlement $settlement, User $by): Settlement
    {
        $this->assertEditable($settlement);

        if (! $settlement->lines()->exists()) {
            throw ValidationException::withMessages(['settlement' => 'A settlement needs at least one line.']);
        }
        if (($missing = $this->missingRates($settlement)) !== []) {
            throw ValidationException::withMessages(['rates' => 'Set a rate for: '.implode(', ', $missing).'.']);
        }
        if (! BigDecimal::of($settlement->total_payout)->isPositive()) {
            throw ValidationException::withMessages(['settlement' => 'The total payout must be positive.']);
        }
        if ($settlement->wallet_id === null) {
            throw ValidationException::withMessages(['wallet_id' => 'Choose the merchant wallet to pay to.']);
        }

        $settlement->update(['status' => SettlementStatus::Approved, 'approved_by' => $by->id, 'approved_at' => now()]);
        AuditLogger::log('settlement.approved', $settlement, ['total' => $settlement->total_payout]);

        return $settlement;
    }

    public function markSettled(Settlement $settlement, User $by, string $txHash, ?string $proofPath = null): Settlement
    {
        if ($settlement->status !== SettlementStatus::Approved) {
            throw ValidationException::withMessages(['settlement' => 'Only an approved settlement can be marked as paid.']);
        }

        $settlement->update([
            'status' => SettlementStatus::Settled,
            'settled_by' => $by->id,
            'settled_at' => now(),
            'tx_hash' => $txHash,
            'proof_path' => $proofPath ?? $settlement->proof_path,
        ]);
        AuditLogger::log('settlement.settled', $settlement, ['tx_hash' => $txHash]);

        return $settlement;
    }

    /**
     * Undo at any stage: the reports and releases become available again.
     */
    public function cancel(Settlement $settlement, User $by, string $reason): Settlement
    {
        if ($settlement->status === SettlementStatus::Cancelled) {
            return $settlement;
        }

        $previous = $settlement->status;
        $settlement->update([
            'status' => SettlementStatus::Cancelled,
            'cancelled_by' => $by->id,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);
        AuditLogger::log('settlement.cancelled', $settlement, ['from' => $previous->value, 'reason' => $reason]);

        return $settlement;
    }

    /**
     * A regenerated report changes its payout: keep a draft line in sync.
     */
    public function syncReport(DailyReportTask $report): void
    {
        $line = SettlementLine::query()
            ->where('type', SettlementLineType::Report)
            ->where('daily_report_task_id', $report->id)
            ->whereHas('settlement', fn (Builder $q) => $q->where('status', SettlementStatus::Draft))
            ->first();

        if ($line !== null) {
            $line->update(['amount' => $report->getRawOriginal('net_payout')]);
            $this->recalculate($line->settlement);
        }
    }

    /**
     * The latest draft of the merchant, or a new empty one.
     */
    public function openDraft(Merchant $merchant): Settlement
    {
        $draft = Settlement::query()
            ->where('merchant_id', $merchant->id)
            ->where('status', SettlementStatus::Draft)
            ->latest('id')
            ->first();

        return $draft ?? $this->createDraft($merchant, null, [], false);
    }

    /**
     * @return Builder<SettlementLine>
     */
    private function activeLineRefs(SettlementLineType $type, string $column): Builder
    {
        return SettlementLine::query()
            ->select($column)
            ->where('type', $type)
            ->whereNotNull($column)
            ->whereHas('settlement', fn (Builder $q) => $q->whereIn('status', SettlementStatus::active()));
    }

    private function assertEditable(Settlement $settlement): void
    {
        if (! $settlement->isEditable()) {
            throw ValidationException::withMessages(['settlement' => 'Only a draft settlement can be changed.']);
        }
    }
}
