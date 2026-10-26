<?php

namespace App\Merchants;

use App\Enums\IntegrationStatus;
use App\Enums\ProviderType;
use App\Models\MerchantAcquirer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Integration with our gateway is "done" the moment the gateway reports a payment on one of the
 * merchant's terminals at that bank, so it is read from the data instead of being kept by hand.
 */
final class AcquirerIntegration
{
    /** @var array<string, CarbonImmutable>|null merchant:bank => last gateway payment */
    private ?array $live = null;

    /**
     * @return array<string, CarbonImmutable>
     */
    private function live(): array
    {
        return $this->live ??= DB::table('merchant_operations')
            ->join('merchant_mids', 'merchant_mids.id', '=', 'merchant_operations.merchant_mid_id')
            ->where('merchant_operations.role', ProviderType::Gate->value)
            ->whereNotNull('merchant_mids.bank_provider_id')
            ->groupBy('merchant_mids.merchant_id', 'merchant_mids.bank_provider_id')
            ->selectRaw('merchant_mids.merchant_id as merchant_id, merchant_mids.bank_provider_id as bank_id, MAX(merchant_operations.transaction_at) as last_at')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->merchant_id}:{$row->bank_id}" => CarbonImmutable::parse($row->last_at)])
            ->all();
    }

    /**
     * @return array{value: string, label: string, auto: bool, last_at: string|null}
     */
    public function for(MerchantAcquirer $acquirer): array
    {
        $last = $this->live()["{$acquirer->merchant_id}:{$acquirer->provider_id}"] ?? null;

        if ($last !== null) {
            return ['value' => IntegrationStatus::Done->value, 'label' => IntegrationStatus::Done->label(), 'auto' => $acquirer->integration_status !== IntegrationStatus::Done, 'last_at' => $last->toDateString()];
        }

        return ['value' => $acquirer->integration_status->value, 'label' => $acquirer->integration_status->label(), 'auto' => false, 'last_at' => null];
    }

    /**
     * Days the onboarding has been in its current in-flight status, or null when nothing is pending or it is not long enough yet.
     */
    public static function staleDays(MerchantAcquirer $acquirer): ?int
    {
        if (! $acquirer->status->isInFlight() || $acquirer->status_changed_at === null) {
            return null;
        }

        $days = (int) $acquirer->status_changed_at->diffInDays(now());

        return $days >= config('sterling.onboarding_stale_days') ? $days : null;
    }
}
