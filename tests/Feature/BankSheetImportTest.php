<?php

namespace Tests\Feature;

use App\Enums\AcquirerStatus;
use App\Enums\IntegrationStatus;
use App\Enums\ProviderType;
use App\Merchants\BankSheetImporter;
use App\Models\Merchant;
use App\Models\MerchantAcquirer;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BankSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = <<<'CSV'
,,,,,,,,,,,
Bank,Visa,MasterCard,Trx Fee,Settlement fee,Settlement Cycle,Min. Settlement,RR 180 days,CAP,Refund,CHB FEE,Note
Madfin,3.60%,4%,0.25 Success,60 EUR,T+2,10 000 EUR,10%,50 000 EUR,6 EUR,80 EUR,
,,,,,,,,,,,
Merchants:,Company name,Website,Onboarding status,MCC,Current Limits EUR,Integration status,PSP,,,,
,FIXERO LTD,fixero.co.uk,Active MIDs,5251,400000,Need to do,BazPay,,,,
,AUTUMN NIGHTS LIMITED,clicktooffice.com,RE-BRAND,,1800000,Issue new MID,,,,,NEED TO CHANGE COMPANY DETAILS
,,,,,,,,,,,
Bank,Visa,MasterCard,Trx Fee,Settlement fee,Settlement Cycle,Min. Settlement,RR 180 days,CAP,Refund,CHB FEE,
Nuvei,0.8%+IC++ (1% Introducers),N/A,0.12 (Approved-declined),0.5% USDT,T+3 (daily),0,10%,80000,,,
,,,,,,,,,,,
Merchants:,Company name,Website,Onboarding status,MCC,Current Limits EUR,Integration status,PSP,,,,
,FIXERO LTD,fixero.co.uk,START ONBOARDING,5251,2000000,Need to do,,,,,
CSV;

    public function test_it_reports_first_and_writes_only_with_apply()
    {
        $madfin = Provider::factory()->create(['name' => 'Madfin', 'code' => 'madfin', 'type' => ProviderType::Bank]);
        $fixero = Merchant::factory()->create(['name' => 'FIXERO LTD', 'website' => 'fixero.co.uk']);

        $log = app(BankSheetImporter::class)->import(self::SHEET, false);

        $this->assertNotEmpty($log);
        $this->assertSame(1, Merchant::query()->count());
        $this->assertSame(0, MerchantAcquirer::query()->count());
        $this->assertNull(Provider::query()->where('code', 'nuvei')->first());

        app(BankSheetImporter::class)->import(self::SHEET, true);

        $madfin->refresh();
        $this->assertSame('3.600', $madfin->cost_visa_eu_percent);
        $this->assertSame('50000.0000', $madfin->rolling_reserve_cap);

        $nuvei = Provider::query()->where('code', 'nuvei')->sole();
        $this->assertNull($nuvei->cost_mastercard_eu_percent);
        $this->assertNull($nuvei->cost_visa_eu_percent);
        $this->assertSame('0.1200', $nuvei->cost_decline_fixed);
        $this->assertStringContainsString('Visa 0.8%+IC++', (string) $nuvei->notes);
        $this->assertStringContainsString('Settlement fee 0.5% USDT', (string) $nuvei->notes);

        $link = MerchantAcquirer::query()->where('merchant_id', $fixero->id)->where('provider_id', $madfin->id)->sole();
        $this->assertSame(AcquirerStatus::ActiveMids, $link->status);
        $this->assertSame('BazPay', $link->psp);
        $this->assertSame('5251', $link->mcc);
        $this->assertSame('400000.00', $link->limit);

        $autumn = Merchant::query()->where('name', 'AUTUMN NIGHTS LIMITED')->sole();
        $rebrand = $autumn->acquirers()->sole();
        $this->assertSame(AcquirerStatus::Rebrand, $rebrand->status);
        $this->assertSame(IntegrationStatus::IssueNewMid, $rebrand->integration_status);
        $this->assertSame('NEED TO CHANGE COMPANY DETAILS', $rebrand->notes);

        $onNuvei = MerchantAcquirer::query()->where('merchant_id', $fixero->id)->where('provider_id', $nuvei->id)->sole();
        $this->assertSame(AcquirerStatus::StartOnboarding, $onNuvei->status);

        // A second run finds nothing to change.
        $this->assertSame([], app(BankSheetImporter::class)->import(self::SHEET, false));
    }

    public function test_a_second_site_of_a_company_becomes_its_own_merchant()
    {
        Provider::factory()->create(['name' => 'Cardaq', 'code' => 'cardaq', 'type' => ProviderType::Bank]);
        Merchant::factory()->create(['name' => 'DOMESTIC DREAMS LIMITED', 'website' => 'makemy-cv.co.uk']);

        $sheet = "Bank,Visa,MasterCard,Trx Fee,Settlement fee,Settlement Cycle,Min. Settlement,RR 180 days,CAP,Refund,CHB FEE,Note\nCardaq,N/A,3.8%,0.20 Success,0,T+2,5,10%,50 000 EUR,6 EUR,69 EUR,\n\nMerchants:,Company name,Website,Onboarding status,MCC,Current Limits EUR,Integration status,PSP,,,,\n,DOMESTIC DREAMS LIMITED,streetforge.co.uk,Active MIDs,7372,1800000,Need to do,Zenithal,,,,\n";

        app(BankSheetImporter::class)->import($sheet, true);

        $this->assertSame(2, Merchant::query()->count());
        $second = Merchant::query()->where('website', 'streetforge.co.uk')->sole();
        $this->assertSame('DOMESTIC DREAMS LIMITED · streetforge.co.uk', $second->name);
        $this->assertSame('7372', $second->acquirers()->sole()->mcc);
    }

    public function test_banks_view_lists_merchants_per_bank()
    {
        $admin = User::factory()->create();
        $bank = Provider::factory()->create(['name' => 'Madfin', 'code' => 'madfin', 'type' => ProviderType::Bank]);
        $merchant = Merchant::factory()->create(['name' => 'FIXERO LTD']);
        $merchant->acquirers()->create(['provider_id' => $bank->id, 'status' => AcquirerStatus::ActiveMids, 'limit' => 400000, 'limit_currency' => 'EUR', 'psp' => 'DG']);

        $this->actingAs($admin)->get(route('admin.merchants.banks'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('open', null)
                ->where('banks.0.name', 'Madfin')
                ->where('banks.0.live', 1));

        $this->get(route('admin.merchants.banks', ['bank' => $bank->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('open.name', 'Madfin')
                ->where('open.rows.0.merchant', 'FIXERO LTD')
                ->where('open.rows.0.psp', 'DG'));
    }
}
