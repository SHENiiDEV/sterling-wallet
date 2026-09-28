<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ReserveEntryType: string
{
    use HasOptions;

    case Hold = 'hold';
    case Release = 'release';
    case Payout = 'payout';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Hold => 'Hold',
            self::Release => 'Release',
            self::Payout => 'Payout',
            self::Adjustment => 'Adjustment',
        };
    }
}
