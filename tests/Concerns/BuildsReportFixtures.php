<?php

namespace Tests\Concerns;

use App\Enums\Currency;
use App\Enums\MerchantStatus;
use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Models\FxRate;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\Provider;
use Illuminate\Http\UploadedFile;

/**
 * Providers, a merchant with a known tariff and provider files in the
 * formats the parsers read. Numbers are chosen so the expected report can
 * be worked out by hand (see DailyReportGeneratorTest).
 */
trait BuildsReportFixtures
{
    protected function cardaq(array $overrides = []): Provider
    {
        return Provider::factory()->create([
            'name' => 'Cardaq', 'code' => 'cardaq', 'type' => ProviderType::Bank,
            'report_format' => 'cardaq', 'connector' => 'cardaq-export', 'report_delay_days' => 2,
            'cost_visa_eu_percent' => 1.5, 'cost_visa_non_eu_percent' => 2.5,
            'cost_mastercard_eu_percent' => 1.5, 'cost_mastercard_non_eu_percent' => 2.5,
            'cost_acq_eu_percent' => 1.5, 'cost_acq_non_eu_percent' => 2.5,
            'cost_success_fixed' => 0.05, 'cost_decline_fixed' => 0, 'cost_refund_fixed' => 0, 'cost_chargeback_fixed' => 0,
            ...$overrides,
        ]);
    }

    protected function madfin(array $overrides = []): Provider
    {
        return $this->cardaq(['name' => 'Madfin', 'code' => 'madfin', 'report_format' => 'madfin', 'connector' => 'madfin-export', 'report_delay_days' => 1, ...$overrides]);
    }

    protected function corefy(array $overrides = []): Provider
    {
        return Provider::factory()->gate()->create([
            'name' => 'Corefy', 'code' => 'corefy', 'report_format' => 'corefy', 'connector' => 'corefy-export', 'report_delay_days' => 1,
            'cost_visa_eu_percent' => null, 'cost_visa_non_eu_percent' => null,
            'cost_mastercard_eu_percent' => null, 'cost_mastercard_non_eu_percent' => null,
            'cost_acq_eu_percent' => 0, 'cost_acq_non_eu_percent' => 0,
            'cost_success_fixed' => 0.10, 'cost_decline_fixed' => 0.05, 'cost_refund_fixed' => 0, 'cost_chargeback_fixed' => 0,
            ...$overrides,
        ]);
    }

    protected function oxen(): Provider
    {
        return Provider::factory()->crypto()->create(['name' => 'Oxen', 'code' => 'oxen', 'cost_crypto_percent' => 0.25]);
    }

    protected function merchantWithTariff(array $overrides = []): Merchant
    {
        return Merchant::factory()->create([
            'status' => MerchantStatus::Active,
            'fee_visa_eu_percent' => 3, 'fee_visa_non_eu_percent' => 4,
            'fee_mastercard_eu_percent' => 3, 'fee_mastercard_non_eu_percent' => 4,
            'fee_acq_eu_percent' => 3, 'fee_acq_non_eu_percent' => 4,
            'fee_success_fixed' => 0.20, 'fee_decline_fixed' => 0.10, 'fee_refund_fixed' => 1.00, 'fee_chargeback_fixed' => 0,
            'fee_fiat_to_crypto_percent' => 0.4,
            'rolling_reserve_percent' => 10, 'rolling_reserve_days' => 180,
            'invoice_email' => 'billing@merchant.test',
            ...$overrides,
        ]);
    }

    protected function mid(Merchant $merchant, Provider $bank, ?Provider $gate, array $overrides = []): MerchantMid
    {
        return MerchantMid::factory()->for($merchant)->create([
            'mid' => '4400000001',
            'currency' => Currency::Eur,
            'status' => MidStatus::Active,
            'bank_provider_id' => $bank->id,
            'gate_provider_id' => $gate?->id,
            'gate_mid' => $gate ? 'coma_TEST1' : null,
            'rolling_reserve_limit' => 100000,
            ...$overrides,
        ]);
    }

    protected function eurUsdRate(string $date = '2026-09-01', string $rate = '1.10'): FxRate
    {
        return FxRate::query()->create(['rate_date' => $date, 'base' => 'EUR', 'quote' => 'USD', 'rate' => $rate]);
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    protected function csvFile(array $rows, string $name = 'report.csv', string $delimiter = ','): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fx').'.csv';
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, $delimiter, escape: '');
        }
        fclose($handle);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /**
     * Cardaq clearing CSV: 3 sales (Visa EU 100, MC non-EU 200, Visa non-EU 50) and one refund of 30.
     */
    protected function cardaqCsv(string $mid = '4400000001', string $currency = 'EUR', string $day = '2026-09-15'): UploadedFile
    {
        return $this->csvFile([
            ['Merchant name', 'MID', 'Transaction ID', 'ARN', 'Card number', 'Card brand', 'Region', 'Trn type', 'Amount', 'Currency', 'Trn date'],
            ['SHOP LTD', $mid, 'CQ-1', '74000000000000000000001', '411111******1111', 'VISA', 'EU', '5', '100.00', $currency, "{$day} 10:00:00"],
            ['SHOP LTD', $mid, 'CQ-2', '74000000000000000000002', '555555******4444', 'MASTERCARD', 'NON-EU', '5', '200.00', $currency, "{$day} 11:00:00"],
            ['SHOP LTD', $mid, 'CQ-3', '74000000000000000000003', '422222******2222', 'VISA', 'NON-EU', '5', '50.00', $currency, "{$day} 12:00:00"],
            ['SHOP LTD', $mid, 'CQ-4', '74000000000000000000004', '411111******1111', 'VISA', 'EU', '25', '30.00', $currency, "{$day} 13:00:00"],
            ['', '', '', '', '', '', '', 'Total', '', '', ''],
        ], 'cardaq.csv');
    }

    /**
     * Same operations in Madfin's layout (different headers, text trn type).
     */
    protected function madfinCsv(string $mid = '5500000001', string $day = '2026-09-15'): UploadedFile
    {
        return $this->csvFile([
            ['Merchant', 'Terminal ID', 'Transaction ID', 'ARN', 'PAN', 'Scheme', 'Region', 'Transaction type', 'Amount', 'Currency', 'Transaction date'],
            ['SHOP LTD', $mid, 'MF-1', 'A1', '411111XXXXXX1111', 'Visa', 'EU', 'Sale', '100,00', 'EUR', '15.09.2026 10:00'],
            ['SHOP LTD', $mid, 'MF-2', 'A2', '555555XXXXXX4444', 'Mastercard', 'Non-EU', 'Sale', '200,00', 'EUR', '15.09.2026 11:00'],
            ['SHOP LTD', $mid, 'MF-3', 'A3', '422222XXXXXX2222', 'Visa', 'Non-EU', 'Sale', '50,00', 'EUR', '15.09.2026 12:00'],
            ['SHOP LTD', $mid, 'MF-4', 'A4', '411111XXXXXX1111', 'Visa', 'EU', 'Refund', '30,00', 'EUR', '15.09.2026 13:00'],
        ], 'madfin.csv', ';');
    }

    /**
     * Corefy export for the same payments (+2 minutes) and two declines.
     */
    protected function corefyCsv(string $account = 'coma_TEST1', string $currency = 'EUR', string $day = '2026-09-15'): UploadedFile
    {
        return $this->csvFile([
            ['ID', 'Commerce account', 'Status', 'Resolution', 'Amount', 'Currency', 'Card', 'Customer email', 'Created'],
            ["{$account}_pi_1", $account, 'processed', 'ok', '100.00', $currency, '411111******1111', 'a@buyer.test', "{$day} 10:02:00"],
            ["{$account}_pi_2", $account, 'processed', 'ok', '200.00', $currency, '555555******4444', 'b@buyer.test', "{$day} 11:01:00"],
            ["{$account}_pi_3", $account, 'processed', 'ok', '50.00', $currency, '422222******2222', 'c@buyer.test', "{$day} 12:03:00"],
            ["{$account}_pi_4", $account, 'process_failed', 'insufficient_funds', '75.00', $currency, '400000******0002', 'd@buyer.test', "{$day} 14:00:00"],
            ["{$account}_pi_5", $account, 'process_failed', 'do_not_honor', '20.00', $currency, '400000******0002', 'd@buyer.test', "{$day} 14:05:00"],
            ["{$account}_pi_6", $account, 'processing', '', '10.00', $currency, '400000******0003', 'e@buyer.test', "{$day} 15:00:00"],
        ], 'corefy.csv', ';');
    }
}
