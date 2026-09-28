<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Admin console areas a staff member can be given access to.
 * Super admins always have all of them; an admin has exactly the listed ones.
 */
enum Module: string
{
    use HasOptions;

    case Merchants = 'merchants';
    case Operations = 'operations';
    case Reports = 'reports';
    case Settlements = 'settlements';
    case Profit = 'profit';
    case Providers = 'providers';
    case Documents = 'documents';
    case Offers = 'offers';
    case Bots = 'bots';
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Merchants => 'Merchants, MIDs & companies',
            self::Operations => 'Operations',
            self::Reports => 'Report Control Center',
            self::Settlements => 'Settlements & reserve',
            self::Profit => 'Profit & profit share',
            self::Providers => 'Providers, FX rates & holidays',
            self::Documents => 'Document Center',
            self::Offers => 'Commercial offers',
            self::Bots => 'Bots',
            self::Team => 'Team & access',
        };
    }
}
