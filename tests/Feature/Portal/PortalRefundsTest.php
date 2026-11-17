<?php

namespace Tests\Feature\Portal;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantOperation;
use App\Models\User;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class PortalRefundsTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private Merchant $ours;

    private Merchant $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', 'Europe/Riga'));
        $cardaq = $this->cardaq();
        $corefy = $this->corefy();
        $this->ours = $this->merchantWithTariff(['name' => 'Our Shop', 'company_id' => Company::factory()->create(['name' => 'Our Co'])->id]);
        $this->theirs = $this->merchantWithTariff(['name' => 'Their Shop', 'company_id' => Company::factory()->create(['name' => 'Their Co'])->id]);
        $this->mid($this->ours, $cardaq, $corefy);
        $this->mid($this->theirs, $cardaq, $corefy, ['mid' => '4400000002', 'gate_mid' => 'coma_TEST2']);

        $ingestion = app(ReportIngestionService::class);
        foreach ([['4400000001', 'coma_TEST1'], ['4400000002', 'coma_TEST2']] as [$mid, $account]) {
            $ingestion->ingest($cardaq, $this->cardaqCsv($mid), CarbonImmutable::parse('2026-09-15'));
            $ingestion->ingest($corefy, $this->corefyCsv($account), CarbonImmutable::parse('2026-09-15'));
        }
    }

    private function chargeback(Merchant $merchant, string $vrol, string $amount): void
    {
        $mid = $merchant->mids()->sole();
        $sale = MerchantOperation::query()->where('merchant_mid_id', $mid->id)->where('role', ProviderType::Bank)->where('operation_type', OperationType::Sale)->orderBy('id')->first();
        MerchantOperation::query()->create([
            'merchant_id' => $merchant->id, 'merchant_mid_id' => $mid->id, 'provider_id' => $mid->bank_provider_id, 'role' => ProviderType::Bank,
            'payment_id' => 'chargeback:'.$vrol, 'reference' => $sale->payment_id, 'trn_type' => 'chargeback', 'operation_type' => OperationType::Chargeback,
            'amount' => $amount, 'currency' => 'EUR', 'card_bin' => $sale->card_bin, 'card_last4' => $sale->card_last4, 'resolution' => '13.1 - Merchandise/Services Not Received',
            'report_date' => '2026-09-16', 'transaction_at' => $sale->transaction_at, 'raw' => ['original_operation_id' => $sale->id],
        ]);
    }

    public function test_a_merchant_sees_its_own_refunds_and_chargebacks_with_the_ids_to_find_them_by()
    {
        $this->chargeback($this->ours, '1001', '100.00');
        $this->chargeback($this->theirs, '2002', '75.00');
        $user = User::factory()->create(['role' => UserRole::Merchant, 'company_id' => $this->ours->company_id]);

        $this->actingAs($user)->get(route('portal.refunds', ['from' => '2026-09-01', 'to' => '2026-09-20']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/refunds')
            // The worked example has one refund of 30.00; plus the chargeback: two lines of its own, none of the other merchant's.
            ->has('rows.data', 2)
            ->where('rows.data', fn ($rows) => collect($rows)->pluck('type')->sort()->values()->all() === ['chargeback', 'refund'])
            ->where('rows.data', fn ($rows) => collect($rows)->firstWhere('type', 'chargeback')['reference'] === '1001')
            ->where('rows.data', fn ($rows) => collect($rows)->firstWhere('type', 'chargeback')['payment_id'] === 'CQ-1')
            ->where('rows.data', fn ($rows) => collect($rows)->firstWhere('type', 'chargeback')['reason'] === '13.1 - Merchandise/Services Not Received')
            ->where('rows.data', fn ($rows) => collect($rows)->firstWhere('type', 'refund')['amount'] === '30')
            ->has('totals', 2));

        // One type only, and the period is respected.
        $this->get(route('portal.refunds', ['from' => '2026-09-01', 'to' => '2026-09-20', 'type' => 'chargeback']))->assertInertia(fn (Assert $page) => $page->has('rows.data', 1));
        $this->get(route('portal.refunds', ['from' => '2026-09-17', 'to' => '2026-09-20']))->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));

        $csv = $this->get(route('portal.refunds', ['from' => '2026-09-01', 'to' => '2026-09-20', 'format' => 'csv']));
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $csv->streamedContent();
        $this->assertStringContainsString('chargeback', $content);
        $this->assertStringContainsString('1001', $content);
        $this->assertStringNotContainsString('2002', $content);
    }
}
