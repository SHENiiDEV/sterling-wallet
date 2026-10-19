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
    case UpdateKyb = 'update_kyb';
    case KybSubmitted = 'kyb_submitted';
    case StartOnboarding = 'start_onboarding';
    case OnboardingInProcess = 'onboarding_in_process';
    case Approved = 'approved';
    case ActiveMids = 'active_mids';
    case Rejected = 'rejected';
    case Rebrand = 'rebrand';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::PrepareKyb => 'Prepare KYB',
            self::UpdateKyb => 'Update KYB',
            self::KybSubmitted => 'KYB submitted',
            self::StartOnboarding => 'Start onboarding',
            self::OnboardingInProcess => 'Onboarding in process',
            self::Approved => 'Approved',
            self::ActiveMids => 'Active MIDs',
            self::Rejected => 'Rejected',
            self::Rebrand => 'Re-brand',
            self::Closed => 'Closed',
        };
    }
}
