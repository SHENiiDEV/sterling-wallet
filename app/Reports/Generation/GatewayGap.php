<?php

namespace App\Reports\Generation;

use App\Enums\OperationType;
use App\Models\MerchantOperation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Successful sales the gateway saw against the sales the acquirer completed, for one report. They are the same
 * payments, so a real difference means payments that the gateway accepted and the bank did not complete (or
 * the other way round): money we expected and did not get. Small gaps are normal, a gap that is big in both
 * share and amount is worth a look.
 *
 * A payment belongs to its pair, not to a day. The two sides do not cut the day at the same moment (Madfin counts
 * UTC days, a Cardaq batch ends after midnight, the gateway is booked by Riga days), so the same payment can sit on
 * neighbouring days at the two sides. The gateway side of a report is therefore the gateway sales paired with the
 * report's bank sales, wherever their own day is, plus the gateway sales of the report nobody paired.
 */
class GatewayGap
{
    /**
     * @param  Collection<int, MerchantOperation>  $bankOps
     * @param  Collection<int, MerchantOperation>  $gateOps
     * @return array{gate_sales: int, gate_amount: string, bank_sales: int, bank_amount: string, diff_count: int, diff_amount: string, diff_percent: float, direction: string, level: string}
     */
    public static function check(Collection $bankOps, Collection $gateOps): array
    {
        $bank = $bankOps->where('operation_type', OperationType::Sale);
        $gate = self::gateSide($bank, $gateOps->where('operation_type', OperationType::Sale));

        $gateAmount = self::sum($gate);
        $bankAmount = self::sum($bank);
        $diff = $gateAmount->minus($bankAmount);
        $diffCount = $gate->count() - $bank->count();
        $base = BigDecimal::max($gateAmount, $bankAmount);
        $percent = $base->isZero() ? 0.0 : round($diff->abs()->dividedBy($base, 6, RoundingMode::HalfUp)->multipliedBy(100)->toFloat(), 1);

        $high = $percent >= (float) config('sterling.gap_alert.percent', 3)
            && $diff->abs()->toFloat() >= (float) config('sterling.gap_alert.min_amount', 100)
            && abs($diffCount) >= (int) config('sterling.gap_alert.min_count', 3);

        return [
            'gate_sales' => $gate->count(),
            'gate_amount' => (string) $gateAmount->toScale(2, RoundingMode::HalfUp),
            'bank_sales' => $bank->count(),
            'bank_amount' => (string) $bankAmount->toScale(2, RoundingMode::HalfUp),
            'diff_count' => $diffCount,
            'diff_amount' => (string) $diff->toScale(2, RoundingMode::HalfUp),
            'diff_percent' => $percent,
            'direction' => $diff->isZero() ? 'none' : ($diff->isPositive() ? 'gateway_more' : 'bank_more'),
            'level' => $high ? 'high' : 'ok',
        ];
    }

    /**
     * The gateway sales that stand against these bank sales: the partners of the bank sales (on any day) and the
     * gateway sales of the report whose bank side is missing or is not a sale.
     *
     * @param  Collection<int, MerchantOperation>  $bank
     * @param  Collection<int, MerchantOperation>  $gate  the gateway sales booked on the report's days
     * @return Collection<int, MerchantOperation>
     */
    private static function gateSide(Collection $bank, Collection $gate): Collection
    {
        $partners = MerchantOperation::query()
            ->whereIn('id', $bank->pluck('matched_operation_id')->filter()->unique()->all())
            ->where('operation_type', OperationType::Sale)
            ->get()->keyBy('id');

        // Of the report's own gateway sales, the ones with no bank sale beside them stand alone. One paired with a bank
        // sale of another day is that day's: it is not counted here, and it is not alone.
        $bankSides = MerchantOperation::query()
            ->whereIn('id', $gate->pluck('matched_operation_id')->filter()->unique()->all())
            ->get()->keyBy('id');
        $alone = $gate->filter(fn (MerchantOperation $op) => ($bankSides->get($op->matched_operation_id)?->operation_type ?? null) !== OperationType::Sale);

        return $partners->values()->merge($alone->values())->unique('id')->values();
    }

    /**
     * @param  Collection<int, MerchantOperation>  $ops
     */
    private static function sum(Collection $ops): BigDecimal
    {
        return $ops->reduce(fn (BigDecimal $c, $op) => $c->plus(BigDecimal::of($op->amount)->abs()), BigDecimal::zero());
    }
}
