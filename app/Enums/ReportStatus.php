<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ReportStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Partial = 'partial';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Partial => 'Partial',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Blocked => 'Blocked',
        };
    }
}
