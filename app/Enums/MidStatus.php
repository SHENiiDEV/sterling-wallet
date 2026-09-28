<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MidStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Inactive = 'inactive';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Review => 'Needs review',
        };
    }
}
