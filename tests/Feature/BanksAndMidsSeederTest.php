<?php

namespace Tests\Feature;

use App\Enums\IntegrationStatus;
use App\Enums\MidStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\Provider;
use Database\Seeders\BanksAndMidsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BanksAndMidsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_the_banks_clients_companies_and_corefy_mids_from_the_sheet()
    {
        $this->seed(BanksAndMidsSeeder::class);

        // Banks with their terms, and how the core reads them.
        $madfin = Provider::query()->where('code', 'madfin')->sole();
        $this->assertSame([3.6, 4.0, 0.25, 100.0], [(float) $madfin->cost_visa_eu_percent, (float) $madfin->cost_mastercard_eu_percent, (float) $madfin->cost_success_fixed, (float) $madfin->cost_chargeback_fixed]);
        $this->assertSame(['madfin', 'madfin-export'], [$madfin->report_format, $madfin->connector]);

        $cardaq = Provider::query()->where('code', 'cardaq')->sole();
        $this->assertNull($cardaq->cost_visa_eu_percent);
        $this->assertSame([3.8, 15.0, 2], [(float) $cardaq->cost_mastercard_eu_percent, (float) $cardaq->settlement_fee, $cardaq->report_delay_days]);
        $this->assertTrue($cardaq->matchingRules()['pair_leftovers']);

        $this->assertSame('payally', Provider::query()->where('code', 'payally')->sole()->code);
        $finbridges = Provider::query()->where('code', 'finbridges')->sole();
        $this->assertSame(['finbridges', 'finbridges-api', 'UTC'], [$finbridges->report_format, $finbridges->connector, $finbridges->timezone]);
        $corefy = Provider::query()->where('code', 'corefy')->sole();
        $this->assertSame(['corefy-export', 1], [$corefy->connector, $corefy->report_delay_days]);

        // Clients are the PSPs, and each company belongs to its PSP.
        $this->assertSame(['1Cap', 'BazPay', 'DG', 'Gold', 'KK', 'Payscrow', 'Zenithal'], Client::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame('Zenithal', Company::query()->where('name', 'CUR NOVA LTD')->sole()->client->name);
        $this->assertSame('KK', Company::query()->where('name', 'WORKING AGENT LTD')->sole()->client->name);
        $this->assertSame('1Cap', Company::query()->where('name', 'HIGHLAND CREST GROUP LTD')->sole()->client->name);

        // A company with two sites has two merchants.
        $this->assertSame(['makemy-cv.co.uk', 'streetforge.co.uk'], Merchant::query()->whereHas('company', fn ($q) => $q->where('name', 'DOMESTIC DREAMS LIMITED'))->orderBy('website')->pluck('website')->all());

        // "Completed" in the sheet is the Done integration status.
        $fixero = Merchant::query()->where('website', 'fixero.co.uk')->sole();
        $this->assertSame(IntegrationStatus::Done, $fixero->acquirers()->sole()->integration_status);
        $this->assertSame($fixero->company->client_id, $fixero->acquirers()->sole()->client_id);

        // One Corefy MID for every company that is ready; known numbers are active, the rest wait for their real number.
        $this->assertSame(8, MerchantMid::query()->count());
        $this->assertSame($corefy->id, MerchantMid::query()->where('mid', '6741557')->sole()->gate_provider_id);
        $this->assertSame('coma_mTwkr1nNLBdi8xPr', MerchantMid::query()->where('mid', '6741557')->sole()->gate_mid);
        $this->assertSame(MidStatus::Active, MerchantMid::query()->where('mid', '1792-3_CC_IN')->sole()->status);
        $placeholders = MerchantMid::query()->where('mid', 'like', 'TBD-%')->get();
        $this->assertCount(3, $placeholders);
        $this->assertTrue($placeholders->every(fn (MerchantMid $m) => $m->status === MidStatus::Review));
    }

    public function test_running_it_again_changes_nothing_and_keeps_a_mid_renamed_by_hand()
    {
        $this->seed(BanksAndMidsSeeder::class);
        MerchantMid::query()->where('mid', 'like', 'TBD-MADFIN-FIXERO%')->update(['mid' => '1792-2', 'status' => MidStatus::Active]);

        $this->seed(BanksAndMidsSeeder::class);

        $this->assertSame(8, MerchantMid::query()->count());
        $this->assertSame(1, MerchantMid::query()->where('mid', '1792-2')->count());
        $this->assertSame(0, MerchantMid::query()->where('mid', 'like', 'TBD-MADFIN-FIXERO%')->count());
        $this->assertSame(7, Client::query()->count());
    }
}
