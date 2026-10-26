<?php

namespace Tests\Feature\Admin;

use App\Enums\AcquirerStatus;
use App\Enums\IntegrationStatus;
use App\Enums\OperationType;
use App\Merchants\AcquirerIntegration;
use App\Models\AdminAuditLog;
use App\Models\Client;
use App\Models\MerchantAcquirer;
use App\Models\MerchantOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class MerchantMatrixTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private function acquirer(array $attributes = []): MerchantAcquirer
    {
        $merchant = $this->merchantWithTariff();

        return $merchant->acquirers()->create([
            'provider_id' => $this->cardaq()->id, 'status' => AcquirerStatus::PrepareKyb, 'limit_currency' => 'EUR',
            'integration_status' => IntegrationStatus::NeedToDo, 'status_changed_at' => now(), ...$attributes,
        ]);
    }

    public function test_the_matrix_lists_each_merchant_against_the_banks_with_its_client()
    {
        $client = Client::query()->create(['name' => 'KK']);
        $acquirer = $this->acquirer(['client_id' => $client->id]);

        $this->actingAs(User::factory()->superAdmin()->create())->get(route('admin.merchants.matrix'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/merchants/matrix')
                ->has('banks', 1)
                ->has('rows', 1)
                ->where("rows.0.cells.{$acquirer->provider_id}.client", 'KK')
                ->where('withoutClient', 0));
    }

    public function test_choosing_a_client_sets_the_psp_and_a_status_change_is_stamped_and_logged()
    {
        $client = Client::query()->create(['name' => 'Zenithal']);
        $acquirer = $this->acquirer(['status_changed_at' => now()->subDays(30)]);
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->put(route('admin.merchants.acquirers.update', [$acquirer->merchant, $acquirer]), [
            'provider_id' => $acquirer->provider_id, 'status' => 'active_mids', 'limit_currency' => 'EUR',
            'integration_status' => 'need_to_do', 'client_id' => $client->id, 'status_note' => 'approved by the bank',
        ])->assertSessionHasNoErrors();

        $acquirer->refresh();
        $this->assertSame($client->id, $acquirer->client_id);
        $this->assertSame('Zenithal', $acquirer->psp);
        $this->assertSame('approved by the bank', $acquirer->status_note);
        $this->assertTrue($acquirer->status_changed_at->isToday());

        $log = AdminAuditLog::query()->where('action', 'merchant_acquirer.updated')->latest('id')->first();
        $this->assertSame(['prepare_kyb', 'active_mids'], $log->meta['changes']['status']);

        $this->get(route('admin.merchants.show', $acquirer->merchant))
            ->assertInertia(fn (Assert $page) => $page
                ->has('history', 1)
                ->where('history.0.title', 'Bank record changed'));
    }

    public function test_integration_is_done_once_the_gateway_reports_a_payment_and_a_long_onboarding_is_stale()
    {
        $acquirer = $this->acquirer(['status_changed_at' => now()->subDays(20)]);
        $this->assertSame(20, AcquirerIntegration::staleDays($acquirer));

        $integration = app(AcquirerIntegration::class);
        $this->assertSame(['need_to_do', false], [$integration->for($acquirer)['value'], $integration->for($acquirer)['auto']]);

        $gate = $this->corefy();
        $mid = $this->mid($acquirer->merchant, $acquirer->provider, $gate);
        MerchantOperation::query()->create([
            'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'provider_id' => $gate->id, 'role' => $gate->type,
            'mid' => $mid->mid, 'payment_id' => 'G-1', 'operation_type' => OperationType::Sale, 'trn_type' => 'sale', 'ips' => 'visa',
            'amount' => '10.00', 'currency' => 'EUR', 'transaction_at' => '2026-10-20 10:00:00', 'report_date' => '2026-10-20',
        ]);

        $live = app(AcquirerIntegration::class)->for($acquirer);
        $this->assertSame(['done', true, '2026-10-20'], [$live['value'], $live['auto'], $live['last_at']]);
    }
}
