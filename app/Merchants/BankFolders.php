<?php

namespace App\Merchants;

use App\Enums\AcquirerStatus;
use App\Enums\ProviderType;
use App\Models\MerchantAcquirer;
use App\Models\MerchantMid;
use App\Models\Provider;
use Illuminate\Support\Collection;

/**
 * Banks as folders: bank → merchants with their onboarding status, limit, MCC, PSP and MIDs at that bank.
 */
final class BankFolders
{
    /**
     * @return list<array<string, mixed>>
     */
    public function banks(): array
    {
        $banks = Provider::query()->ofType(ProviderType::Bank)->with(['acquirers.merchant:id,name'])->withCount('bankMids')->orderBy('name')->get();

        return $banks->map(fn (Provider $bank) => [
            'id' => $bank->id,
            'name' => $bank->name,
            'is_active' => $bank->is_active,
            'merchants' => $bank->acquirers->count(),
            'live' => $bank->acquirers->where('status', AcquirerStatus::ActiveMids)->count(),
            'mids' => $bank->bank_mids_count,
            'statuses' => $bank->acquirers->groupBy(fn (MerchantAcquirer $a) => $a->status->value)
                ->map(fn (Collection $group, string $status) => ['status' => $status, 'label' => $group->first()->status->label(), 'count' => $group->count()])
                ->values()->all(),
            'preview' => $bank->acquirers->take(3)->map(fn (MerchantAcquirer $a) => $a->merchant->name)->values()->all(),
        ])->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function bank(int $id): ?array
    {
        $bank = Provider::query()->ofType(ProviderType::Bank)->find($id);
        if ($bank === null) {
            return null;
        }

        $mids = MerchantMid::query()->where('bank_provider_id', $bank->id)->orderBy('currency')->get()->groupBy('merchant_id');

        $rows = $bank->acquirers()->with('merchant.company:id,name')->get()
            ->sortBy(fn (MerchantAcquirer $a) => $a->merchant->name)
            ->map(fn (MerchantAcquirer $a) => [
                'id' => $a->id,
                'merchant_id' => $a->merchant_id,
                'merchant' => $a->merchant->name,
                'company' => $a->merchant->company?->name,
                'website' => $a->merchant->website,
                'status' => $a->status->value,
                'status_label' => $a->status->label(),
                'mcc' => $a->mcc ?? $a->merchant->mcc,
                'limit' => $a->limit,
                'limit_currency' => $a->limit_currency,
                'integration' => $a->integration_status->label(),
                'psp' => $a->psp,
                'notes' => $a->notes,
                'mids' => ($mids[$a->merchant_id] ?? collect())->map(fn (MerchantMid $m) => ['id' => $m->id, 'mid' => $m->mid, 'currency' => $m->currency->value])->values()->all(),
            ])->values()->all();

        return [
            'id' => $bank->id,
            'name' => $bank->name,
            'terms' => $this->terms($bank),
            'notes' => $bank->notes,
            'rows' => $rows,
        ];
    }

    /**
     * Cost terms as label / value pairs, the way the banks sheet shows them.
     *
     * @return list<array{label: string, value: string}>
     */
    private function terms(Provider $bank): array
    {
        $percent = fn (?string $v) => $v === null ? 'N/A' : rtrim(rtrim($v, '0'), '.').'%';
        $money = fn (?string $v) => $v === null ? '—' : number_format((float) $v, (float) $v === floor((float) $v) ? 0 : 2, '.', ' ').' EUR';

        $fee = $money($bank->cost_success_fixed).' success'.((float) $bank->cost_decline_fixed > 0 ? ' / '.$money($bank->cost_decline_fixed).' decline' : '');

        return [
            ['label' => 'Visa', 'value' => $percent($bank->cost_visa_eu_percent)],
            ['label' => 'MasterCard', 'value' => $percent($bank->cost_mastercard_eu_percent)],
            ['label' => 'Trx fee', 'value' => $fee],
            ['label' => 'Settlement fee', 'value' => $money($bank->settlement_fee)],
            ['label' => 'Cycle', 'value' => $bank->settlement_cycle ?? '—'],
            ['label' => 'Min. settlement', 'value' => $money($bank->min_settlement)],
            ['label' => "RR {$bank->rolling_reserve_days} days", 'value' => $percent($bank->rolling_reserve_percent).' · cap '.$money($bank->rolling_reserve_cap)],
            ['label' => 'Refund', 'value' => $money($bank->cost_refund_fixed)],
            ['label' => 'Chargeback', 'value' => $money($bank->cost_chargeback_fixed)],
        ];
    }
}
