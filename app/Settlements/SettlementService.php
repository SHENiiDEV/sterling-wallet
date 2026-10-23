<?php

namespace App\Settlements;

use App\Enums\ReportStatus;
use App\Enums\ReserveEntryType;
use App\Enums\SettlementLineType;
use App\Enums\SettlementStatus;
use App\Enums\TariffProduct;
use App\Models\Client;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\MerchantCryptoWallet;
use App\Models\ReserveLedgerEntry;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\User;
use App\Reports\Generation\FxRateResolver;
use App\Services\AuditLogger;
use App\Support\ProductScope;
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
    public function __construct(private FxRateResolver $fx, private SettlementSchedule $schedule) {}

    /**
     * Completed reports of the merchant that no active settlement pays yet.
     *
     * @return Collection<int, DailyReportTask>
     */
    public function availableReports(Merchant $merchant, ?TariffProduct $product = null): Collection
    {
        return $this->availableReportsFor([$merchant->id], $product);
    }

    /**
     * Reports the settlement could still pay: those of every merchant in its scope.
     *
     * @return Collection<int, DailyReportTask>
     */
    public function availableReportsIn(Settlement $settlement): Collection
    {
        return $this->availableReportsFor($settlement->merchantIds(), $settlement->product);
    }

    /**
     * @param  list<int>|null  $merchantIds  null = every merchant
     * @return Collection<int, DailyReportTask>
     */
    public function availableReportsFor(?array $merchantIds, ?TariffProduct $product = null): Collection
    {
        return DailyReportTask::query()
            ->when($product, fn (Builder $q, TariffProduct $p) => $q->whereHas('merchantMid.bankProvider', fn (Builder $b) => $b->where('product', $p->value)))
            ->with(['merchantMid:id,mid,currency', 'merchant:id,name,company_id,settlement_lag_days', 'merchant.company:id,name'])
            ->when($merchantIds !== null, fn (Builder $q) => $q->whereIn('merchant_id', $merchantIds))
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
    public function availableReleases(Merchant $merchant, ?TariffProduct $product = null): Collection
    {
        return $this->availableReleasesFor([$merchant->id], $product);
    }

    /**
     * @return Collection<int, ReserveLedgerEntry>
     */
    public function availableReleasesIn(Settlement $settlement): Collection
    {
        return $this->availableReleasesFor($settlement->merchantIds(), $settlement->product);
    }

    /**
     * @param  list<int>  $merchantIds
     * @return Collection<int, ReserveLedgerEntry>
     */
    private function availableReleasesFor(array $merchantIds, ?TariffProduct $product = null): Collection
    {
        return ReserveLedgerEntry::query()
            ->when($product, fn (Builder $q, TariffProduct $p) => $q->whereHas('merchantMid.bankProvider', fn (Builder $b) => $b->where('product', $p->value)))
            ->with('merchantMid:id,mid,merchant_id')
            ->whereIn('merchant_id', $merchantIds)
            ->where('type', ReserveEntryType::Release)
            ->whereNotIn('id', $this->activeLineRefs(SettlementLineType::ReserveRelease, 'reserve_ledger_entry_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * One draft for a whole client: every company's reports, paid in one go.
     * The settlement charge follows the terms of the first merchant that has
     * something to pay (or the first merchant at all).
     */
    public function createClientDraft(Client $client, ?User $by, ?string $payoutCurrency = null, bool $withReleases = true, ?TariffProduct $product = null, bool $dueOnly = false): Settlement
    {
        $merchants = Merchant::query()->whereHas('company', fn ($q) => $q->where('client_id', $client->id))->orderBy('id')->get();
        if ($merchants->isEmpty()) {
            throw ValidationException::withMessages(['client' => 'This client has no merchants yet.']);
        }

        $pending = DailyReportTask::query()
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->where('status', ReportStatus::Completed)
            ->whereNotIn('id', $this->activeLineRefs(SettlementLineType::Report, 'daily_report_task_id'))
            ->pluck('merchant_id');
        $primary = $merchants->first(fn (Merchant $m) => $pending->contains($m->id)) ?? $merchants->first();

        return $this->createDraft($primary, $by, null, $withReleases, $client, $payoutCurrency, $product, $dueOnly);
    }

    /**
     * @param  list<int>|null  $reportIds  null = every available report
     */
    public function createDraft(Merchant $merchant, ?User $by, ?array $reportIds = null, bool $withReleases = true, ?Client $client = null, ?string $payoutCurrency = null, ?TariffProduct $product = null, bool $dueOnly = false): Settlement
    {
        // One settlement pays one product: a C2B partner can only make C2B ones.
        $product ??= ProductScope::current() ?? TariffProduct::Card;

        return DB::transaction(function () use ($merchant, $by, $reportIds, $withReleases, $client, $payoutCurrency, $product, $dueOnly) {
            $settlement = Settlement::query()->create([
                'number' => 'SET-TMP-'.uniqid(),
                'merchant_id' => $merchant->id,
                'client_id' => $client?->id,
                'product' => $product,
                'status' => SettlementStatus::Draft,
                'payout_currency' => $this->payoutCurrency($payoutCurrency),
                'rates' => [],
                'created_by' => $by?->id,
            ]);
            $settlement->update([
                'number' => sprintf('SET-%s-%06d', now()->format('Y'), $settlement->id),
                'wallet_id' => $this->defaultWallet($settlement)?->id,
            ]);

            $reports = $this->availableReportsIn($settlement);
            // Reports that are not due yet by the merchant's T+N stay out of a "due" draft.
            if ($dueOnly) {
                $reports = $reports->reject(fn (DailyReportTask $r) => $this->schedule->state($r) === SettlementSchedule::NOT_DUE);
            }
            if ($reportIds !== null) {
                $reports = $reports->whereIn('id', $reportIds);
            }
            $this->addReports($settlement, $reports);

            if ($withReleases) {
                foreach ($this->availableReleasesIn($settlement) as $release) {
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
        $scope = $settlement->merchantIds();

        foreach ($reports as $report) {
            if (! in_array($report->merchant_id, $scope, true) || $report->status !== ReportStatus::Completed || in_array($report->id, $taken, true)) {
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
        if ($line->type === SettlementLineType::Fee) {
            throw ValidationException::withMessages(['settlement' => 'The settlement charge follows the merchant terms; waive it with a positive adjustment.']);
        }
        $line->delete();
        $this->recalculate($settlement);
    }

    /**
     * Change what the settlement is paid in. Rates are for the old currency, so they start over.
     */
    public function setPayoutCurrency(Settlement $settlement, string $currency): Settlement
    {
        $this->assertEditable($settlement);
        $currency = $this->payoutCurrency($currency);
        if ($currency === $settlement->payout_currency) {
            return $settlement;
        }

        $settlement->update(['payout_currency' => $currency, 'rates' => []]);
        $wallet = $settlement->wallet;
        if ($wallet === null || strtoupper((string) $wallet->currency) !== $currency) {
            $settlement->update(['wallet_id' => $this->defaultWallet($settlement)?->id ?? $settlement->wallet_id]);
        }

        return $this->recalculate($settlement);
    }

    /**
     * Wallets the settlement can be paid to: active wallets of every merchant in its scope.
     *
     * @return Collection<int, MerchantCryptoWallet>
     */
    public function wallets(Settlement $settlement): Collection
    {
        return MerchantCryptoWallet::query()
            ->with('merchant:id,name')
            ->whereIn('merchant_id', $settlement->merchantIds())
            ->where('is_active', true)
            ->orderByRaw('merchant_id = ? desc', [$settlement->merchant_id])
            ->orderBy('id')
            ->get();
    }

    /**
     * The wallet in the payout currency if there is one, else the first active wallet.
     */
    private function defaultWallet(Settlement $settlement): ?MerchantCryptoWallet
    {
        $wallets = $this->wallets($settlement);

        return $wallets->first(fn (MerchantCryptoWallet $w) => strtoupper((string) $w->currency) === $settlement->payout_currency) ?? $wallets->first();
    }

    private function payoutCurrency(?string $currency): string
    {
        $currency = strtoupper((string) $currency);

        return array_key_exists($currency, config('sterling.payout_currencies')) ? $currency : 'USDC';
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
        $this->syncFee($settlement);
        $rates = $settlement->rates ?? [];
        $today = CarbonImmutable::now();

        foreach ($settlement->lines()->distinct()->pluck('currency') as $currency) {
            if (! isset($rates[$currency])) {
                $rate = $this->fx->rate($currency, $settlement->payout_currency, $today) ?? $this->pegRate($currency, $settlement->payout_currency, $today);
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
     * A stablecoin is worth the currency it is pegged to: 1:1 with it, and
     * any other currency goes through that one (EUR → USDT = EUR → USD).
     */
    private function pegRate(string $from, string $payout, CarbonImmutable $date): ?BigDecimal
    {
        $peg = config('sterling.payout_pegs')[$payout] ?? null;
        if ($peg === null) {
            return null;
        }

        return $from === $peg ? BigDecimal::one() : $this->fx->rate($from, $peg, $date);
    }

    /**
     * The merchant's fixed charge per settlement: one negative line while
     * the draft pays anything out, none for an empty draft.
     */
    private function syncFee(Settlement $settlement): void
    {
        if (! $settlement->isEditable()) {
            return;
        }

        // The settlement charge is part of the card terms; C2B has none yet.
        $charge = $settlement->product === TariffProduct::Card ? BigDecimal::of($settlement->merchant->fee_settlement_fixed ?? 0) : BigDecimal::zero();
        $paysOut = $settlement->lines()->whereIn('type', [SettlementLineType::Report, SettlementLineType::ReserveRelease])->exists();
        $line = $settlement->lines()->where('type', SettlementLineType::Fee)->first();

        if (! $charge->isPositive() || ! $paysOut) {
            $line?->delete();

            return;
        }

        $values = [
            'description' => 'Settlement charge',
            'currency' => config('sterling.settlement_currency'),
            'amount' => (string) $charge->negated(),
        ];
        $line ? $line->update($values) : $settlement->lines()->create(['type' => SettlementLineType::Fee, ...$values]);
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

    /**
     * Report lines whose day is not yet due for payout by the merchant's schedule.
     *
     * @return Collection<int, DailyReportTask>
     */
    public function earlyReports(Settlement $settlement): Collection
    {
        return DailyReportTask::query()
            ->with('merchant:id,settlement_lag_days')
            ->whereIn('id', $settlement->lines()->where('type', SettlementLineType::Report)->whereNotNull('daily_report_task_id')->pluck('daily_report_task_id'))
            ->get()
            ->filter(fn (DailyReportTask $r) => $this->schedule->state($r) === SettlementSchedule::NOT_DUE)
            ->values();
    }

    public function approve(Settlement $settlement, User $by, bool $confirmEarly = false): Settlement
    {
        $this->assertEditable($settlement);

        $early = $this->earlyReports($settlement);
        if ($early->isNotEmpty() && ! $confirmEarly) {
            throw ValidationException::withMessages(['early' => sprintf(
                '%d report(s) are not due for payout yet (%s). Confirm to pay them early.',
                $early->count(),
                $early->map(fn (DailyReportTask $r) => $r->report_date->toDateString())->implode(', '),
            )]);
        }

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
     * The latest draft of the merchant for the product, or a new empty one.
     */
    public function openDraft(Merchant $merchant, TariffProduct $product = TariffProduct::Card): Settlement
    {
        $clientId = $merchant->company?->client_id;

        $draft = Settlement::query()
            ->where(fn (Builder $q) => $q->where('merchant_id', $merchant->id)->when($clientId, fn (Builder $q) => $q->orWhere('client_id', $clientId)))
            ->where('status', SettlementStatus::Draft)
            ->where('product', $product->value)
            ->latest('id')
            ->first();

        return $draft ?? $this->createDraft($merchant, null, [], false, product: $product);
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
