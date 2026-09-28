<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MerchantStatus: string
{
    use HasOptions;

    case Onboarding = 'onboarding';
    case Review = 'review';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Onboarding',
            self::Review => 'Needs review',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }
}
