<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ProfitShareBase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $profit_partner_id
 * @property int|null $merchant_id
 * @property ProfitShareBase $base
 * @property string $percent
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property string|null $notes
 * @property-read ProfitPartner $partner
 * @property-read Merchant|null $merchant
 */
#[Fillable(['profit_partner_id', 'merchant_id', 'base', 'percent', 'valid_from', 'valid_to', 'notes'])]
class ProfitShareRule extends Model
{
    protected function casts(): array
    {
        return [
            'base' => ProfitShareBase::class,
            'percent' => 'decimal:4',
            'valid_from' => DateOnly::class,
            'valid_to' => DateOnly::class,
        ];
    }

    /**
     * @return BelongsTo<ProfitPartner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(ProfitPartner::class, 'profit_partner_id');
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Whether the rule is in force for any day of the month.
     */
    public function activeIn(CarbonImmutable $monthStart): bool
    {
        $monthEnd = $monthStart->endOfMonth()->startOfDay();

        return $this->valid_from->lessThanOrEqualTo($monthEnd)
            && ($this->valid_to === null || $this->valid_to->greaterThanOrEqualTo($monthStart));
    }
}
