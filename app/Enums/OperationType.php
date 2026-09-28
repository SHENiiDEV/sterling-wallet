<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum OperationType: string
{
    use HasOptions;

    case Sale = 'sale';
    case Refund = 'refund';
    case Decline = 'decline';
    case Chargeback = 'chargeback';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Refund => 'Refund',
            self::Decline => 'Decline',
            self::Chargeback => 'Chargeback',
        };
    }
}
