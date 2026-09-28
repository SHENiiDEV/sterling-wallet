<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Currency: string
{
    use HasOptions;

    case Usd = 'USD';
    case Eur = 'EUR';
    case Gbp = 'GBP';

    public function symbol(): string
    {
        return match ($this) {
            self::Usd => '$',
            self::Eur => '€',
            self::Gbp => '£',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Usd => 'US dollar',
            self::Eur => 'Euro',
            self::Gbp => 'British pound',
        };
    }
}
