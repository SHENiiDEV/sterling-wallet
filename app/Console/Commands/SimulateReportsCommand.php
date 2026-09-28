<?php

namespace App\Console\Commands;

use App\Enums\Currency;
use App\Enums\MerchantStatus;
use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Models\Company;
use App\Models\FxRate;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Test data for the whole pipeline: [TEST] merchants on two provider pairs,
 * realistic provider files for N days, uploaded through the bot API exactly
 * like a bot would (or straight into ingestion with --direct).
 */
#[Signature('reports:simulate
    {--days=14 : How many days back from yesterday}
    {--per-day=30 : Sales per MID per day (± 40%)}
    {--direct : Skip HTTP and feed files straight into ingestion}
    {--seed= : Random seed, for repeatable data}
    {--cleanup : Delete all [TEST] merchants and everything they produced}')]
#[Description('Generate and upload test provider reports for [TEST] merchants')]
class SimulateReportsCommand extends Command
{
    private const PREFIX = '[TEST] ';

    /** [merchant, MID, currency, bank code, commerce account]. */
    private const MIDS = [
        ['Madfin EUR shop', 'TEST-MF-EUR', 'EUR', 'madfin', 'coma_TEST_MF_EUR'],
        ['Cardaq multi-currency', 'TEST-CQ-USD', 'USD', 'cardaq', 'coma_TEST_CQ_USD'],
        ['Cardaq multi-currency', 'TEST-CQ-GBP', 'GBP', 'cardaq', 'coma_TEST_CQ_GBP'],
    ];

    private const BINS = [
        'visa' => [['411111', 'LV'], ['453201', 'DE'], ['476173', 'GB'], ['400551', 'US'], ['414720', 'AE']],
        'mastercard' => [['555555', 'LV'], ['522001', 'FR'], ['540011', 'GB'], ['510510', 'US'], ['222300', 'TR']],
    ];

    private const ISSUERS = ['Swedbank', 'Revolut', 'Barclays', 'Chase', 'Deutsche Bank', 'Emirates NBD', 'Monzo'];

    private const DECLINES = ['insufficient_funds', 'do_not_honor', 'expired_card', '3ds_failed', 'suspected_fraud'];

    private Randomizer $random;

    /**
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->random->pickArrayKeys($items, 1)[0]];
    }

    public function handle(ReportIngestionService $ingestion): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        if (! $this->option('direct') && ! config('sterling.bot_api_key')) {
            $this->error('BOT_REPORTS_API_KEY is not set: set it or use --direct.');

            return self::FAILURE;
        }

        // Own generator: other code (PDF/XLSX writers) must not shift the sequence.
        $this->random = new Randomizer(new Mt19937((int) ($this->option('seed') ?? random_int(1, PHP_INT_MAX))));
        $providers = $this->providers();
        $mids = $this->setUpMerchants($providers);
        $today = CarbonImmutable::now(config('sterling.timezone'))->startOfDay();
        $days = max(1, (int) $this->option('days'));
        $this->ensureRates($today->subDays($days + 1));

        $uploads = 0;
        for ($day = $today->subDays($days); $day->lessThan($today); $day = $day->addDay()) {
            foreach ($mids as [$mid, $bank, $account]) {
                [$bankRows, $gateRows] = $this->operations($mid, $bank, $day);

                $this->upload($ingestion, $bank, $mid, $day, $this->bankFile($bank, $bankRows));
                $this->upload($ingestion, $providers['corefy'], $mid, $day, $this->corefyFile($account, $gateRows));
                $uploads += 2;
            }
            $this->line("  {$day->toDateString()} uploaded");
        }

        $this->newLine();
        $this->info("{$uploads} files uploaded for ".count($mids).' test MIDs over '.$days.' day(s).');
        $this->line('Reports are calculated by the queue worker — open Report Control Center in a minute.');
        $this->line('Remove everything later with: php artisan reports:simulate --cleanup');

        return self::SUCCESS;
    }

    /**
     * @return array<string, Provider>
     */
    private function providers(): array
    {
        $cost = ['cost_acq_eu_percent' => 3.8, 'cost_acq_non_eu_percent' => 3.8, 'rolling_reserve_days' => 180];

        return [
            'madfin' => Provider::query()->firstOrCreate(['code' => 'madfin'], [
                'name' => 'Madfin', 'type' => ProviderType::Bank, 'report_format' => 'madfin', 'report_delay_days' => 1,
                'cost_visa_eu_percent' => 3.6, 'cost_visa_non_eu_percent' => 3.6, 'cost_mastercard_eu_percent' => 4, 'cost_mastercard_non_eu_percent' => 4,
                'cost_success_fixed' => 0.25, 'cost_refund_fixed' => 6, 'cost_chargeback_fixed' => 80, ...$cost,
            ]),
            'cardaq' => Provider::query()->firstOrCreate(['code' => 'cardaq'], [
                'name' => 'Cardaq', 'type' => ProviderType::Bank, 'report_format' => 'cardaq', 'connector' => 'cardaq-export', 'report_delay_days' => 2,
                'cost_mastercard_eu_percent' => 3.8, 'cost_mastercard_non_eu_percent' => 3.8,
                'cost_success_fixed' => 0.10, 'cost_refund_fixed' => 6, 'cost_chargeback_fixed' => 69, ...$cost,
            ]),
            'corefy' => Provider::query()->firstOrCreate(['code' => 'corefy'], [
                'name' => 'Corefy', 'type' => ProviderType::Gate, 'report_format' => 'corefy', 'connector' => 'corefy-export', 'report_delay_days' => 1,
                'cost_acq_eu_percent' => 0, 'cost_acq_non_eu_percent' => 0, 'cost_success_fixed' => 0.10, 'cost_decline_fixed' => 0.05,
            ]),
        ];
    }

    /**
     * @param  array<string, Provider>  $providers
     * @return list<array{0: MerchantMid, 1: Provider, 2: string}>
     */
    private function setUpMerchants(array $providers): array
    {
        foreach (['madfin', 'cardaq', 'corefy'] as $code) {
            if ($providers[$code]->report_format === null) {
                throw new RuntimeException("Provider {$code} has no report format set; set it in Providers first.");
            }
        }

        $crypto = Provider::query()->where('type', ProviderType::Crypto)->value('id');
        $result = [];

        foreach (self::MIDS as [$name, $midCode, $currency, $bankCode, $account]) {
            $company = Company::query()->firstOrCreate(['name' => self::PREFIX.$name]);
            $merchant = Merchant::query()->firstOrCreate(['name' => self::PREFIX.$name], [
                'company_id' => $company->id,
                'status' => MerchantStatus::Active,
                'is_test' => true,
                'crypto_provider_id' => $crypto,
                'fee_visa_eu_percent' => 4.5, 'fee_visa_non_eu_percent' => 5.5,
                'fee_mastercard_eu_percent' => 4.5, 'fee_mastercard_non_eu_percent' => 5.5,
                'fee_acq_eu_percent' => 4.5, 'fee_acq_non_eu_percent' => 5.5,
                'fee_success_fixed' => 0.35, 'fee_decline_fixed' => 0.15, 'fee_refund_fixed' => 7, 'fee_chargeback_fixed' => 90,
                'fee_fiat_to_crypto_percent' => 0.5, 'rolling_reserve_percent' => 10, 'rolling_reserve_days' => 180,
                'notes' => 'Created by reports:simulate. Delete with reports:simulate --cleanup.',
            ]);
            if (! $merchant->wallets()->exists()) {
                $merchant->wallets()->create(['type' => 'provider_inflow', 'currency' => 'USDC', 'network' => 'TRC20', 'address' => 'TTEST'.strtoupper(substr(md5($name), 0, 29))]);
            }

            $mid = MerchantMid::query()->where('mid', $midCode)->first() ?? $merchant->mids()->create([
                'mid' => $midCode,
                'currency' => Currency::from($currency),
                'label' => 'Test data',
                'status' => MidStatus::Active,
                'bank_provider_id' => $providers[$bankCode]->id,
                'gate_provider_id' => $providers['corefy']->id,
                'gate_mid' => $account,
                'rolling_reserve_limit' => 50000,
            ]);

            $result[] = [$mid, $providers[$bankCode], $account];
        }

        return $result;
    }

    /**
     * Reports need a rate to EUR and settlements one to USDC; add neutral
     * rates only for pairs that have none at all.
     */
    private function ensureRates(CarbonImmutable $from): void
    {
        foreach ([['EUR', 'USD', '1.08'], ['EUR', 'GBP', '0.85'], ['EUR', 'USDC', '1.08']] as [$base, $quote, $rate]) {
            $exists = FxRate::query()->where(fn ($q) => $q->where(['base' => $base, 'quote' => $quote])->orWhere(['base' => $quote, 'quote' => $base]))->exists();
            if (! $exists) {
                FxRate::query()->create(['rate_date' => $from, 'base' => $base, 'quote' => $quote, 'rate' => $rate, 'source' => 'demo']);
                $this->warn("Added a demo FX rate {$base}→{$quote} = {$rate} (none existed). Replace it in FX rates with a real one.");
            }
        }
    }

    /**
     * One day of a MID: clearing rows for the acquirer, and the same payments
     * (a minute or two later, a few missing) plus declines for the gateway.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function operations(MerchantMid $mid, Provider $bank, CarbonImmutable $day): array
    {
        $count = max(1, (int) round((int) $this->option('per-day') * $this->random->getInt(60, 140) / 100));
        $schemes = $bank->cost_visa_eu_percent === null ? ['mastercard'] : ['visa', 'visa', 'mastercard'];
        $currency = $mid->currency->value;
        $bankRows = $gateRows = [];

        for ($i = 1; $i <= $count; $i++) {
            $scheme = $this->pick($schemes);
            [$bin, $country] = $this->pick(self::BINS[$scheme]);
            $row = [
                'mid' => $mid->mid,
                'id' => sprintf('%s-%s-%03d', $mid->mid, $day->format('Ymd'), $i),
                'bin' => $bin,
                'last4' => str_pad((string) $this->random->getInt(0, 9999), 4, '0', STR_PAD_LEFT),
                'scheme' => $scheme,
                'country' => $country,
                'region' => in_array($country, ['LV', 'DE', 'FR'], true) ? 'EU' : 'NON-EU',
                'issuer' => $this->pick(self::ISSUERS),
                'amount' => number_format($this->random->getInt(1500, 45000) / 100, 2, '.', ''),
                'currency' => $currency,
                'at' => $day->setTime($this->random->getInt(7, 22), $this->random->getInt(0, 59), $this->random->getInt(0, 59)),
                'email' => 'buyer'.$this->random->getInt(100, 999).'@example.test',
                'type' => 'sale',
            ];
            $bankRows[] = $row;

            // ~3 % of clearing rows have no gateway record → shown as "without pair".
            if ($this->random->getInt(1, 100) > 3) {
                $gateRows[] = [...$row, 'at' => $row['at']->addSeconds($this->random->getInt(20, 180))];
            }

            // ~15 % of attempts decline at the gateway before reaching the bank.
            if ($this->random->getInt(1, 100) <= 15) {
                $gateRows[] = [...$row, 'id' => $row['id'].'-D', 'type' => 'decline', 'last4' => str_pad((string) $this->random->getInt(0, 9999), 4, '0', STR_PAD_LEFT), 'reason' => $this->pick(self::DECLINES)];
            }
        }

        // ~5 % refunds of the day's sales.
        foreach (array_slice($bankRows, 0, (int) floor($count * 0.05)) as $sale) {
            $bankRows[] = [...$sale, 'id' => $sale['id'].'-R', 'type' => 'refund', 'at' => $sale['at']->addHours(1)];
        }

        return [$bankRows, $gateRows];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function bankFile(Provider $bank, array $rows): UploadedFile
    {
        if ($bank->report_format === 'madfin') {
            return $this->csv([
                ['Merchant', 'Terminal ID', 'Transaction ID', 'ARN', 'PAN', 'Scheme', 'Region', 'Issuer country', 'Transaction type', 'Amount', 'Currency', 'Transaction date'],
                ...array_map(fn ($r) => [
                    'TEST', $r['mid'], $r['id'], 'ARN'.crc32($r['id']), $r['bin'].'XXXXXX'.$r['last4'], ucfirst($r['scheme']),
                    $r['region'] === 'EU' ? 'EU' : 'Non-EU', $r['country'], $r['type'] === 'refund' ? 'Refund' : 'Sale',
                    str_replace('.', ',', $r['amount']), $r['currency'], $r['at']->format('d.m.Y H:i'),
                ], $rows),
            ], 'madfin.csv', ';');
        }

        return $this->csv([
            ['Merchant name', 'MID', 'Transaction ID', 'ARN', 'Card number', 'Card brand', 'Region', 'Issuer country', 'Issuer', 'Trn type', 'Amount', 'Currency', 'Trn date'],
            ...array_map(fn ($r) => [
                'TEST', $r['mid'], $r['id'], '7400'.str_pad((string) crc32($r['id']), 19, '0', STR_PAD_LEFT),
                $r['bin'].'******'.$r['last4'], strtoupper($r['scheme']), $r['region'], $r['country'], $r['issuer'],
                $r['type'] === 'refund' ? '25' : '5', $r['amount'], $r['currency'], $r['at']->format('Y-m-d H:i:s'),
            ], $rows),
        ], 'cardaq.csv');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function corefyFile(string $account, array $rows): UploadedFile
    {
        return $this->csv([
            ['ID', 'Commerce account', 'Status', 'Resolution', 'Amount', 'Currency', 'Card', 'Customer email', 'Created'],
            ...array_map(fn ($r) => [
                'pi_'.$r['id'], $account, $r['type'] === 'decline' ? 'process_failed' : 'processed', $r['type'] === 'decline' ? $r['reason'] : 'ok',
                $r['amount'], $r['currency'], $r['bin'].'******'.$r['last4'], $r['email'], $r['at']->format('Y-m-d H:i:s'),
            ], $rows),
        ], 'corefy.csv', ';');
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function csv(array $rows, string $name, string $delimiter = ','): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sim').'.csv';
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, $delimiter, escape: '');
        }
        fclose($handle);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function upload(ReportIngestionService $ingestion, Provider $provider, MerchantMid $mid, CarbonImmutable $day, UploadedFile $file): void
    {
        $content = (string) file_get_contents($file->getPathname());

        if ($this->option('direct')) {
            $ingestion->ingest($provider, $file, $day, coveredMids: [$mid]);
            @unlink($file->getPathname());

            return;
        }

        $url = rtrim((string) config('app.url'), '/').'/api/v1/reports/upload/'.$provider->code;
        $response = Http::withToken((string) config('sterling.bot_api_key'))
            ->acceptJson()
            ->timeout(120)
            ->retry(3, 3000, throw: false)
            ->attach('file', $content, $file->getClientOriginalName())
            ->post($url, ['merchant_id' => $mid->id, 'report_date' => $day->toDateString()]);
        @unlink($file->getPathname());

        if (! $response->successful()) {
            throw new RuntimeException("Upload to {$url} failed ({$response->status()}): ".mb_substr($response->body(), 0, 500));
        }
    }

    private function cleanup(): int
    {
        $merchants = Merchant::query()->where('is_test', true)->where('name', 'like', self::PREFIX.'%')->get();
        if ($merchants->isEmpty()) {
            $this->info('No [TEST] merchants found.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($merchants) {
            $midIds = MerchantMid::query()->whereIn('merchant_id', $merchants->modelKeys())->pluck('id');
            // Operations only null their MID on delete, so remove them explicitly.
            MerchantOperation::query()->whereIn('merchant_mid_id', $midIds)->update(['matched_operation_id' => null]);
            MerchantOperation::query()->whereIn('merchant_mid_id', $midIds)->delete();

            foreach ($merchants as $merchant) {
                $company = $merchant->company;
                $merchant->delete(); // cascades MIDs, reports, sources, reserve, settlements, wallets
                if ($company && str_starts_with($company->name, self::PREFIX) && ! $company->merchants()->exists()) {
                    $company->delete();
                }
            }
        });

        $this->info('Removed '.$merchants->count().' [TEST] merchant(s) with their MIDs, operations, reports, reserve and settlements.');
        $this->line('Demo FX rates (source "demo") were kept; delete them in FX rates if you added real ones.');

        return self::SUCCESS;
    }
}
