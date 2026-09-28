<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Onboarding of a merchant with one acquiring bank.
 */
enum AcquirerStatus: string
{
    use HasOptions;

    case PrepareKyb = 'prepare_kyb';
    case KybSubmitted = 'kyb_submitted';
    case Approved = 'approved';
    case ActiveMids = 'active_mids';
    case Rejected = 'rejected';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::PrepareKyb => 'Prepare KYB',
            self::KybSubmitted => 'KYB submitted',
            self::Approved => 'Approved',
            self::ActiveMids => 'Active MIDs',
            self::Rejected => 'Rejected',
            self::Closed => 'Closed',
        };
    }
}
