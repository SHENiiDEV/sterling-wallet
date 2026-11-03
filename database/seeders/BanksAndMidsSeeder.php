<?php

namespace Database\Seeders;

use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Enums\TariffProduct;
use App\Merchants\BankSheetImporter;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\Provider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The banks sheet as the team keeps it: banks with their terms, the companies and sites onboarded with each, the clients
 * (PSPs) they belong to, and a MID wired to Corefy for every company that is ready (marked VISA/MC or MC in the sheet).
 *
 *   php artisan db:seed --class=BanksAndMidsSeeder
 *
 * Safe to run again: nothing that exists is duplicated, MIDs already entered by hand are left alone. Terms of the banks
 * and the status/limit/MCC of each bank record are brought back to the sheet (the same as `banks:import --apply`).
 */
class BanksAndMidsSeeder extends Seeder
{
    /** The PSPs: each one is a client, and the companies listed under it in the sheet are its companies. */
    private const CLIENTS = ['1Cap', 'BazPay', 'DG', 'Gold', 'KK', 'Payscrow', 'Zenithal'];

    /**
     * The sheet in the format of `banks:import` (the leading marker column left out).
     */
    private const SHEET = <<<'CSV'
Bank,Visa,MasterCard,Trx Fee,Settlement fee,Settlement Cycle,Min. Settlement,RR 180 days,CAP,Refund,CHB FEE
Madfin,3.60%,4%,0.25 Success,60 EUR,T+2,10 000 EUR,10%,50 000 EUR,6 EUR,100 EUR

Merchants:,Company name,Website,Onboarding status,MCC,Current Limits EUR,Integration status,PSP
,FIXERO LTD,fixero.co.uk,Active MIDs,5251,400000,Completed,BazPay
,LUNEHOM LTD,homelune.co.uk,Active MIDs,5712,400000,Completed,DG
,CUR NOVA LTD,cur-nova.com,Active MIDs,8299,400000,Completed,Zenithal
,HARTWICK VENTURES LTD,velusim.com,Active MIDs,4814,400000,Completed,KK
,BETTERB LTD,rust-skins.com,Active MIDs,5816,400000,Completed,Gold
,HIGHLAND CREST GROUP LTD,ever-grove.co.uk,PREPARE KYB,5311,400000,Need to do,1Cap
,WORKING AGENT LTD,cv-makers.co.uk,UPDATE KYB,,400000,Need to do,KK
,DOMESTIC DREAMS LIMITED,makemy-cv.co.uk,ONBOARDING IN PROCESS,,400000,Completed,Zenithal
,TRUE CENTRIC LTD,imgedit.co.uk,Active MIDs,,400000,Need to do,Payscrow
,HAWTHORNE RIDGE LTD,crystalbrawlers.com,ONBOARDING IN PROCESS,,400000,Need to do,1Cap

Bank,Visa,MasterCard,Trx Fee,Settlement fee,Settlement Cycle,Min. Settlement,RR 180 days,CAP,Refund,CHB FEE
PayAlly,PayAlly,4%,0.05 Success/Decline,0,"T+2, weekly (Tuesdays)",5,10%,75 000 EUR,,

Merchants:,Company name,Website,Onboarding status,MCC,Current Limits EUR,Integration status,PSP
,HARTWICK VENTURES LTD,velusim.com,PREPARE KYB,4814,2000000,Need to do,KK
,CUR NOVA LTD,cur-nova.com,PREPARE KYB,,2000000,Need to do,Zenithal
,BETTERB LTD,rust-skins.com,PREPARE KYB,,2000000,Need to do,Gold

Bank,Visa,MasterCard,Trx Fee,Settlement fee,Settlement Cycle,Min. Settlement,RR 180 days,CAP,Refund,CHB FEE
Cardaq,N/A,3.8%,0.20 Success,15,T+2,5,10%,50 000 EUR,6 EUR,69 EUR

Merchants:,Company name,Website,Onboarding status,MCC,Current Limits EUR,Integration status,PSP
,DOMESTIC DREAMS LIMITED,streetforge.co.uk,Active MIDs,7372,1800000,Completed,Zenithal
,WORKING AGENT LTD,cv-makers.co.uk,Active MIDs,7372,1800000,Completed,KK
,AUTUMN NIGHTS LIMITED,clicktooffice.com,RE-BRAND,,1800000,Issue new MID,1Cap
,TRUE CENTRIC LTD,nexused.co.uk,Active MIDs,,1800000,Issue new MID,Payscrow
,PROTECT YOUR ASSETS TODAY LTD,crazydefensehereoes.co.uk,Active MIDs,5816,1800000,Completed,Gold
CSV;

    /**
     * Companies ready on Corefy: [bank code, website, MID number, Corefy commerce account].
     * A MID number or account that is not known yet is null: the MID is created as a placeholder (`TBD-…`, needs review)
     * to be renamed to the real number in Merchants → MIDs. The numbers filled in were taken from the live reports.
     *
     * @var list<array{0: string, 1: string, 2: string|null, 3: string|null}>
     */
    private const COREFY_READY = [
        ['madfin', 'fixero.co.uk', null, null],
        ['madfin', 'homelune.co.uk', '1792-1_CC_IN', null],
        ['madfin', 'cur-nova.com', '1792-3_CC_IN', null],
        ['madfin', 'velusim.com', '1792-4_CC_IN', null],
        ['madfin', 'rust-skins.com', null, null],
        ['cardaq', 'streetforge.co.uk', '6741557', 'coma_mTwkr1nNLBdi8xPr'],
        ['cardaq', 'cv-makers.co.uk', '6741425', null],
        ['cardaq', 'crazydefensehereoes.co.uk', null, null],
    ];

    public function run(): void
    {
        $corefy = $this->corefy();
        $this->finBridges();

        foreach (self::CLIENTS as $name) {
            Client::query()->firstOrCreate(['name' => $name]);
        }

        // Banks, merchants (company + site), bank records.
        foreach (app(BankSheetImporter::class)->import(self::SHEET, true) as $line) {
            $this->command?->line("  {$line}");
        }

        $this->wireBanks();
        $this->assignCompaniesToClients();

        foreach (self::COREFY_READY as [$bankCode, $site, $mid, $account]) {
            $this->mid($bankCode, $site, $mid, $account, $corefy);
        }

        $this->command?->info('Banks, clients, companies and Corefy MIDs are in place. MIDs named TBD-… need their real numbers.');
    }

    /**
     * The gateway all the card MIDs go through. Its costs come from the contract: fill them in Providers.
     */
    private function corefy(): Provider
    {
        $corefy = Provider::query()->firstOrCreate(['code' => 'corefy'], [
            'name' => 'Corefy', 'type' => ProviderType::Gate, 'is_active' => true,
            'report_format' => 'corefy', 'connector' => 'corefy-export', 'report_delay_days' => 1,
        ]);

        $corefy->update(['report_format' => 'corefy', 'connector' => 'corefy-export', 'report_delay_days' => 1]);

        return $corefy;
    }

    /**
     * The C2B wallet API: bank transfers, no card schemes and no gateway. Its costs come from the contract (Providers).
     */
    private function finBridges(): void
    {
        $structure = ['report_format' => 'finbridges', 'connector' => 'finbridges-api', 'timezone' => 'UTC', 'product' => TariffProduct::C2b];

        Provider::query()->firstOrCreate(['code' => 'finbridges'], ['name' => 'FinBridges', 'type' => ProviderType::Bank, 'is_active' => true, ...$structure])
            ->update($structure);
    }

    /**
     * How the core reads each bank's reports.
     */
    private function wireBanks(): void
    {
        $banks = [
            'madfin' => ['report_format' => 'madfin', 'connector' => 'madfin-export', 'report_delay_days' => 1],
            // Cardaq clears hours after the gateway sees a payment: leftovers with the same card and amount are paired.
            'cardaq' => ['report_format' => 'cardaq', 'connector' => 'cardaq-export', 'report_delay_days' => 2, 'matching' => ['window_minutes' => 15, 'pair_leftovers' => true]],
        ];

        foreach ($banks as $code => $attributes) {
            Provider::query()->where('code', $code)->first()?->update($attributes);
        }
    }

    /**
     * The PSP column of the sheet says whose company it is.
     */
    private function assignCompaniesToClients(): void
    {
        foreach (preg_split('/\R/', self::SHEET) ?: [] as $line) {
            $row = array_map('trim', str_getcsv($line, escape: ''));
            if (($row[0] ?? null) !== '' || ($row[1] ?? '') === '' || ($row[7] ?? '') === '') {
                continue;
            }

            $client = Client::query()->where('name', $row[7])->first();
            $company = Company::query()->where('name', $row[1])->first();
            if ($client !== null && $company !== null) {
                $company->update(['client_id' => $client->id]);
            }
        }
    }

    private function mid(string $bankCode, string $site, ?string $number, ?string $account, Provider $corefy): void
    {
        $bank = Provider::query()->where('code', $bankCode)->first();
        $merchant = Merchant::query()->whereRaw('lower(website) = ?', [Str::lower($site)])->first();
        if ($bank === null || $merchant === null) {
            $this->command?->warn("No {$bankCode} merchant for {$site}: MID skipped.");

            return;
        }

        // A MID the team already entered (or renamed from its placeholder) for this merchant at this bank stays.
        if (MerchantMid::query()->where('merchant_id', $merchant->id)->where('bank_provider_id', $bank->id)->exists()) {
            return;
        }

        $merchant->mids()->create([
            'mid' => $number ?? 'TBD-'.Str::upper($bankCode).'-'.Str::upper($site),
            'currency' => 'EUR',
            'label' => $number === null ? 'Placeholder: enter the real MID' : null,
            'status' => $number === null ? MidStatus::Review : MidStatus::Active,
            'bank_provider_id' => $bank->id,
            'gate_provider_id' => $corefy->id,
            'gate_mid' => $account,
        ]);
    }
}
