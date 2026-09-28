<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ProviderType: string
{
    use HasOptions;

    case Bank = 'bank';
    case Gate = 'gate';
    case Crypto = 'crypto';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Acquirer (bank)',
            self::Gate => 'Gateway',
            self::Crypto => 'Crypto',
        };
    }
}
