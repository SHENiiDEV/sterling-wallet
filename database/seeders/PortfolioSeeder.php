<?php

namespace Database\Seeders;

use App\Enums\AcquirerStatus;
use App\Enums\IntegrationStatus;
use App\Enums\MerchantStatus;
use App\Enums\ProviderType;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Provider;
use Illuminate\Database\Seeder;

/**
 * Acquiring banks with our cost, and the merchant companies onboarding with
 * each of them (status, limit, integration, PSP). Safe to run repeatedly:
 * everything is matched by bank code or company name and updated in place.
 *
 * php artisan db:seed --class=PortfolioSeeder --force
 */
class PortfolioSeeder extends Seeder
{
    /**
     * Cost fields per bank. Percent = % of the sale, fixed amounts in EUR.
     * `null` for a card scheme = the bank doesn't take that scheme.
     */
    private const BANKS = [
        'madfin' => [
            'name' => 'Madfin', 'report_format' => 'madfin', 'report_delay_days' => 1,
            'visa' => 3.6, 'mastercard' => 4, 'success' => 0.25, 'decline' => 0,
            'settlement_fee' => 60, 'cycle' => 'T+2', 'min_settlement' => 10000,
            'cap' => 60000, 'refund' => 6, 'chargeback' => 80,
        ],
        'payally' => [
            'name' => 'PayAlly',
            'visa' => 4, 'mastercard' => 4, 'success' => 0.05, 'decline' => 0.05,
            'settlement_fee' => 0, 'cycle' => 'T+2, weekly (Tuesdays)', 'min_settlement' => 5,
            'cap' => 75000, 'refund' => 0, 'chargeback' => 0,
        ],
        'cardaq' => [
            'name' => 'Cardaq', 'report_format' => 'cardaq', 'connector' => 'cardaq-export', 'report_delay_days' => 2,
            'visa' => null, 'mastercard' => 3.8, 'success' => 0.10, 'decline' => 0,
            'settlement_fee' => 10, 'cycle' => 'T+2', 'min_settlement' => 5,
            'cap' => 50000, 'refund' => 6, 'chargeback' => 69,
            'notes' => 'Visa: N/A — Mastercard only.',
        ],
        'pixxels' => [
            'name' => 'Pixxels',
            'visa' => 2, 'mastercard' => 2, 'success' => 0, 'decline' => 0,
            'settlement_fee' => 0, 'cycle' => 'T+2', 'min_settlement' => 0,
            'cap' => 0, 'refund' => 0, 'chargeback' => 0, 'rr' => 0,
            'notes' => 'Pricing: 2% + IC++ (interchange and scheme fees on top, per the clearing report).',
        ],
        'trustpayments' => [
            'name' => 'TrustPayments',
            'visa' => 1.5, 'mastercard' => 1.5, 'success' => 0, 'decline' => 0,
            'settlement_fee' => 0, 'cycle' => null, 'min_settlement' => 0,
            'cap' => 0, 'refund' => 0, 'chargeback' => 0, 'rr' => 0,
            'notes' => 'Pricing: 1.5% + IC++ (interchange and scheme fees on top, per the clearing report).',
        ],
    ];

    /**
     * Companies: website and MCC.
     */
    private const COMPANIES = [
        'FIXERO LTD' => ['fixero.co.uk', '5251'],
        'LUNEHOM LTD' => ['homelune.co.uk', '5712'],
        'CUR NOVA LTD' => ['cur-nova.com', '8299'],
        'HARTWICK VENTURES LTD' => ['velusim.com', '4814'],
        'HIGHLAND CREST GROUP LTD' => ['ever-grove.co.uk', '5311'],
        'DOMESTIC DREAMS LIMITED' => ['makemy-cv.co.uk', '7372'],
        'WORKING AGENT LTD' => ['cv-makers.co.uk', '7372'],
    ];

    /**
     * [bank code, company, status, limit EUR, PSP]. Integration is "Need to do" everywhere.
     */
    private const ONBOARDING = [
        ['madfin', 'FIXERO LTD', AcquirerStatus::ActiveMids, 500000, 'Deniss'],
        ['madfin', 'LUNEHOM LTD', AcquirerStatus::ActiveMids, 500000, 'BazPay'],
        ['madfin', 'CUR NOVA LTD', AcquirerStatus::ActiveMids, 500000, 'Flexify'],
        ['madfin', 'HARTWICK VENTURES LTD', AcquirerStatus::PrepareKyb, 350000, null],
        ['madfin', 'HIGHLAND CREST GROUP LTD', AcquirerStatus::PrepareKyb, 350000, null],

        ['payally', 'HARTWICK VENTURES LTD', AcquirerStatus::PrepareKyb, 2000000, null],
        ['payally', 'HIGHLAND CREST GROUP LTD', AcquirerStatus::PrepareKyb, 2000000, null],

        ['cardaq', 'DOMESTIC DREAMS LIMITED', AcquirerStatus::ActiveMids, 1800000, 'Deniss'],
        ['cardaq', 'WORKING AGENT LTD', AcquirerStatus::ActiveMids, 1800000, null],

        ['pixxels', 'FIXERO LTD', AcquirerStatus::PrepareKyb, 500000, null],
        ['pixxels', 'LUNEHOM LTD', AcquirerStatus::PrepareKyb, 500000, null],
        ['pixxels', 'HARTWICK VENTURES LTD', AcquirerStatus::PrepareKyb, 500000, null],
        ['pixxels', 'HIGHLAND CREST GROUP LTD', AcquirerStatus::PrepareKyb, 500000, null],

        ['trustpayments', 'FIXERO LTD', AcquirerStatus::PrepareKyb, 500000, null],
        ['trustpayments', 'LUNEHOM LTD', AcquirerStatus::PrepareKyb, 500000, null],
        ['trustpayments', 'HARTWICK VENTURES LTD', AcquirerStatus::PrepareKyb, 500000, null],
        ['trustpayments', 'HIGHLAND CREST GROUP LTD', AcquirerStatus::PrepareKyb, 500000, null],
    ];

    public function run(): void
    {
        $banks = [];
        foreach (self::BANKS as $code => $bank) {
            $banks[$code] = $this->bank($code, $bank);
        }

        $merchants = [];
        foreach (self::COMPANIES as $name => [$website, $mcc]) {
            $merchants[$name] = $this->merchant($name, $website, $mcc);
        }

        foreach (self::ONBOARDING as [$code, $company, $status, $limit, $psp]) {
            $merchants[$company]->acquirers()->updateOrCreate(
                ['provider_id' => $banks[$code]->id],
                [
                    'status' => $status,
                    'limit' => $limit,
                    'limit_currency' => 'EUR',
                    'integration_status' => IntegrationStatus::NeedToDo,
                    'psp' => $psp,
                ],
            );
        }

        // A merchant with live MIDs anywhere is active; the rest are onboarding.
        foreach ($merchants as $merchant) {
            $live = $merchant->acquirers()->where('status', AcquirerStatus::ActiveMids)->exists();
            if (in_array($merchant->status, [MerchantStatus::Onboarding, MerchantStatus::Active], true)) {
                $merchant->update([
                    'status' => $live ? MerchantStatus::Active : MerchantStatus::Onboarding,
                    'onboarding_status' => $live ? 'Active MIDs' : 'Prepare KYB',
                ]);
            }
        }

        $this->command?->info(sprintf('%d banks, %d merchants, %d bank links.', count($banks), count($merchants), count(self::ONBOARDING)));
    }

    /**
     * @param  array<string, mixed>  $b
     */
    private function bank(string $code, array $b): Provider
    {
        $provider = Provider::query()->firstOrNew(['code' => $code]);

        $provider->fill([
            'name' => $provider->name ?? $b['name'],
            'type' => ProviderType::Bank,
            'cost_visa_eu_percent' => $b['visa'],
            'cost_visa_non_eu_percent' => $b['visa'],
            'cost_mastercard_eu_percent' => $b['mastercard'],
            'cost_mastercard_non_eu_percent' => $b['mastercard'],
            // Unknown brand: price as the dearer scheme.
            'cost_acq_eu_percent' => max((float) $b['visa'], (float) $b['mastercard']),
            'cost_acq_non_eu_percent' => max((float) $b['visa'], (float) $b['mastercard']),
            'cost_success_fixed' => $b['success'],
            'cost_decline_fixed' => $b['decline'],
            'cost_refund_fixed' => $b['refund'],
            'cost_chargeback_fixed' => $b['chargeback'],
            'settlement_fee' => $b['settlement_fee'],
            'settlement_cycle' => $b['cycle'],
            'min_settlement' => $b['min_settlement'],
            'rolling_reserve_percent' => $b['rr'] ?? 10,
            'rolling_reserve_days' => 180,
            'rolling_reserve_cap' => $b['cap'],
        ]);

        // Report settings are only filled in, never overwritten.
        foreach (['report_format', 'connector', 'report_delay_days'] as $field) {
            if (isset($b[$field]) && ($provider->{$field} === null || ! $provider->exists)) {
                $provider->{$field} = $b[$field];
            }
        }
        if (isset($b['notes']) && ! str_contains((string) $provider->notes, $b['notes'])) {
            $provider->notes = trim(($provider->notes ? $provider->notes."\n" : '').$b['notes']);
        }
        $provider->is_active ??= true;
        $provider->save();

        return $provider;
    }

    private function merchant(string $name, string $website, string $mcc): Merchant
    {
        $company = Company::query()->firstOrCreate(['name' => $name]);

        /** @var Merchant $merchant */
        $merchant = Merchant::query()->firstOrNew(['company_id' => $company->id, 'name' => $name]);
        $merchant->fill(['website' => $website, 'mcc' => $mcc]);
        if (! $merchant->exists) {
            $merchant->fill(['status' => MerchantStatus::Onboarding, ...config('sterling.merchant_defaults')]);
        }
        $merchant->save();

        return $merchant;
    }
}
