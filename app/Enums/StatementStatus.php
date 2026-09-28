<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum StatementStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Closed => 'Closed',
        };
    }
}
