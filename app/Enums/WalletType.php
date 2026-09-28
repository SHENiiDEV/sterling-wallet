<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum WalletType: string
{
    use HasOptions;

    case ProviderInflow = 'provider_inflow';
    case RollingReserve = 'rolling_reserve';
    case BackupReserve = 'backup_reserve';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ProviderInflow => 'Provider inflow',
            self::RollingReserve => 'Rolling reserve',
            self::BackupReserve => 'Backup reserve',
            self::Other => 'Other',
        };
    }
}
