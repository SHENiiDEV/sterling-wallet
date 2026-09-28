<?php

namespace App\Models;

use App\Enums\ProfitShareBase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $profit_partner_id
 * @property string $partner_name
 * @property int|null $merchant_id
 * @property string $merchant_name
 * @property ProfitShareBase $base
 * @property string $base_amount
 * @property string $percent
 * @property string $share
 */
#[Fillable(['monthly_statement_id', 'profit_partner_id', 'partner_name', 'merchant_id', 'merchant_name', 'profit_share_rule_id', 'base', 'base_amount', 'percent', 'share'])]
class MonthlyStatementLine extends Model
{
    protected function casts(): array
    {
        return [
            'base' => ProfitShareBase::class,
            'base_amount' => 'decimal:4',
            'percent' => 'decimal:4',
            'share' => 'decimal:4',
        ];
    }
}
