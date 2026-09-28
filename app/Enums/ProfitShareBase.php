<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ProfitShareBase: string
{
    use HasOptions;

    case NetProfit = 'net_profit';
    case Turnover = 'turnover';

    public function label(): string
    {
        return match ($this) {
            self::NetProfit => 'Net profit',
            self::Turnover => 'Turnover',
        };
    }
}
