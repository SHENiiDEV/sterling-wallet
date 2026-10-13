<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a merchant is offered. Card acquiring keeps its tariff on the
 * merchant itself; every other product (C2B bank transfers, later Open
 * Banking …) has its own row in `merchant_tariffs`. A provider belongs to
 * one product, and so does every MID served by it.
 */
enum TariffProduct: string
{
    use HasOptions;

    case Card = 'card';
    case C2b = 'c2b';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card acquiring',
            self::C2b => 'Bank transfers (C2B)',
        };
    }

    /**
     * Products priced by their own tariff row, in the order they are shown.
     *
     * @return list<self>
     */
    public static function separate(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => $p !== self::Card));
    }
}
