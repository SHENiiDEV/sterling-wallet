<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum OperationSource: string
{
    use HasOptions;

    case Cardaq = 'cardaq';
    case Corefy = 'corefy';

    public function label(): string
    {
        return match ($this) {
            self::Cardaq => 'Cardaq',
            self::Corefy => 'Corefy',
        };
    }
}
