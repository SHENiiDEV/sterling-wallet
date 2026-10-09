<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SettlementLineType: string
{
    use HasOptions;

    case Report = 'report';
    case ReserveRelease = 'reserve_release';
    case Adjustment = 'adjustment';
    case Fee = 'fee';

    public function label(): string
    {
        return match ($this) {
            self::Report => 'Daily report',
            self::ReserveRelease => 'Reserve release',
            self::Adjustment => 'Adjustment',
            self::Fee => 'Settlement charge',
        };
    }
}
