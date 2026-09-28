<?php

namespace App\Reports\Generation;

use App\Models\FxRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;

/**
 * Latest rate on or before a date. Accepts the pair stored either way round
 * (EUR→USD or USD→EUR) and inverts when needed.
 */
class FxRateResolver
{
    /**
     * How many `$to` one `$from` is worth, or null when no rate is known.
     */
    public function rate(string $from, string $to, DateTimeInterface $date): ?BigDecimal
    {
        if ($from === $to) {
            return BigDecimal::one();
        }

        $day = $date->format('Y-m-d');

        $direct = FxRate::query()->where(['base' => $from, 'quote' => $to])
            ->where('rate_date', '<=', $day)->orderByDesc('rate_date')->first();
        $inverse = FxRate::query()->where(['base' => $to, 'quote' => $from])
            ->where('rate_date', '<=', $day)->orderByDesc('rate_date')->first();

        if ($direct && (! $inverse || $direct->rate_date->greaterThanOrEqualTo($inverse->rate_date))) {
            return BigDecimal::of($direct->rate);
        }

        if ($inverse && BigDecimal::of($inverse->rate)->isPositive()) {
            return BigDecimal::one()->dividedBy($inverse->rate, 8, RoundingMode::HalfUp);
        }

        return null;
    }
}
