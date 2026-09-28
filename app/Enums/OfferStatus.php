<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum OfferStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
        };
    }
}
