<?php

namespace Tests\Feature\Admin;

use App\Authenticator\AuthenticatorCodes;
use App\Enums\Module;
use App\Models\AdminAuditLog;
use App\Models\AuthenticatorCode;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
    }

    public function test_a_code_is_stored_encrypted_and_generates_the_current_digits()
    {
        $this->actingAs($this->admin)->post(route('admin.authenticator.store'), [
            'name' => 'Oxen · COSMIC BAGEL LTD', 'service' => 'Oxen', 'secret' => 'gezd gnbv gy3t qojq gezd gnbv gy3t qojq',
        ])->assertSessionHasNoErrors();

        $code = AuthenticatorCode::query()->sole();
        $this->assertSame(self::SECRET, $code->secret);
        // At rest it is not the plain secret.
        $this->assertStringNotContainsString(self::SECRET, (string) DB::table('authenticator_codes')->value('secret'));

        $response = $this->get(route('admin.authenticator.codes', ['ids' => [$code->id]]))->assertOk();
        $this->assertSame(Totp::code(self::SECRET), $response->json((string) $code->id.'.code'));
        // The page itself never carries the secret.
        $this->get(route('admin.authenticator.index'))->assertInertia(fn (Assert $page) => $page
            ->has('codes', 1)
            ->missing('codes.0.secret'));
    }

    public function test_a_wrong_secret_is_refused_and_an_empty_one_keeps_the_stored_secret_on_edit()
    {
        $this->actingAs($this->admin)->post(route('admin.authenticator.store'), ['name' => 'Bad', 'secret' => 'not a secret!'])
            ->assertSessionHasErrors('secret');

        $this->post(route('admin.authenticator.store'), ['name' => 'Mailbox', 'secret' => self::SECRET]);
        $code = AuthenticatorCode::query()->sole();

        $this->put(route('admin.authenticator.update', $code), ['name' => 'Mailbox renamed', 'secret' => ''])->assertSessionHasNoErrors();
        $this->assertSame(['Mailbox renamed', self::SECRET], [$code->fresh()->name, $code->fresh()->secret]);
    }

    public function test_only_staff_with_the_module_can_see_the_codes()
    {
        $code = AuthenticatorCode::query()->create(['name' => 'X', 'secret' => self::SECRET]);

        $without = User::factory()->withModules([Module::Settlements])->create();
        $this->actingAs($without)->get(route('admin.authenticator.index'))->assertForbidden();
        $this->get(route('admin.authenticator.codes', ['ids' => [$code->id]]))->assertForbidden();

        $with = User::factory()->withModules([Module::Authenticator])->create();
        $this->actingAs($with)->get(route('admin.authenticator.index'))->assertOk();
    }

    public function test_viewing_a_code_is_written_to_the_audit_log_once_in_a_while()
    {
        $code = AuthenticatorCode::query()->create(['name' => 'X', 'secret' => self::SECRET]);

        $this->actingAs($this->admin)->get(route('admin.authenticator.codes', ['ids' => [$code->id]]));
        $this->get(route('admin.authenticator.codes', ['ids' => [$code->id]]));

        $this->assertSame(1, AdminAuditLog::query()->where('action', 'authenticator.code_viewed')->count());
    }

    public function test_codes_linked_to_a_payout_party_are_found_for_its_settlement()
    {
        $client = Client::query()->create(['name' => 'Kudret']);
        $company = Company::query()->create(['name' => 'COSMIC BAGEL LTD', 'client_id' => $client->id]);
        $merchant = Merchant::factory()->create(['company_id' => $company->id]);
        $other = Merchant::factory()->create();
        $settlement = Settlement::query()->create([
            'number' => 'SET-T-1', 'merchant_id' => $merchant->id, 'client_id' => $client->id, 'status' => 'draft', 'payout_currency' => 'USDT', 'rates' => [],
        ]);

        $forCompany = AuthenticatorCode::query()->create(['name' => 'Oxen COSMIC', 'secret' => self::SECRET]);
        $forCompany->links()->create(['target_type' => 'company', 'target_id' => $company->id]);
        $forOther = AuthenticatorCode::query()->create(['name' => 'Oxen other', 'secret' => self::SECRET]);
        $forOther->links()->create(['target_type' => 'merchant', 'target_id' => $other->id]);
        $inactive = AuthenticatorCode::query()->create(['name' => 'Off', 'secret' => self::SECRET, 'is_active' => false]);
        $inactive->links()->create(['target_type' => 'client', 'target_id' => $client->id]);

        $found = app(AuthenticatorCodes::class)->forSettlement($settlement);
        $this->assertSame(['Oxen COSMIC'], $found->pluck('name')->all());

        $this->actingAs($this->admin)->get(route('admin.settlements.show', $settlement))
            ->assertInertia(fn (Assert $page) => $page
                ->has('authenticatorCodes', 1)
                ->where('authenticatorCodes.0.name', 'Oxen COSMIC')
                ->where('authenticatorCodes.0.links.0.name', 'COSMIC BAGEL LTD'));
    }

    public function test_the_link_picker_gets_companies_under_their_client_and_merchants_under_their_company()
    {
        $client = Client::query()->create(['name' => 'Kudret']);
        $company = Company::query()->create(['name' => 'COSMIC BAGEL LTD', 'client_id' => $client->id]);
        $merchant = Merchant::factory()->create(['company_id' => $company->id, 'website' => 'cosmic.example']);

        $this->actingAs($this->admin)->get(route('admin.authenticator.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('targets.company.0.parent_id', $client->id)
                ->where('targets.merchant.0.parent_id', $company->id)
                ->where('targets.merchant.0.hint', 'cosmic.example')
                ->where('targets.merchant.0.id', $merchant->id));
    }
}
