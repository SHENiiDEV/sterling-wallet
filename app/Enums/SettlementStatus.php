<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SettlementStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Approved = 'approved';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Settled => 'Settled',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Statuses that hold on to their reports and reserve releases.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Draft, self::Approved, self::Settled];
    }
}
