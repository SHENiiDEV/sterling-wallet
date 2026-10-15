<?php

namespace Tests\Feature\Bots;

use App\Bots\BotResult;
use App\Bots\Connectors\CardaqExportConnector;
use App\Bots\PlaywrightRunner;
use App\Bots\Target;
use App\Enums\BotRunStatus;
use App\Models\BotRun;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Reports\ReportDateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class CardaqMailboxTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * What the Node script receives for an account with these settings.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function payloadFor(array $settings, ?string $loginUrl = null): array
    {
        $account = IntegrationAccount::query()->create([
            'provider_id' => $this->cardaq()->id, 'connector' => 'cardaq-export', 'name' => 'Cardaq mailbox',
            'username' => 'reports@example.test', 'password' => 'secret', 'login_url' => $loginUrl, 'settings' => $settings,
        ]);
        $run = BotRun::query()->create(['connector' => 'cardaq-export', 'integration_account_id' => $account->id, 'report_date' => '2026-09-14', 'target_key' => '2026-09-14', 'status' => 'queued']);

        $captured = [];
        $runner = Mockery::mock(PlaywrightRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (string $code, array $payload) use (&$captured) {
            $captured = $payload;

            return new BotResult(BotRunStatus::Skipped, error: 'test');
        });
        $this->app->instance(PlaywrightRunner::class, $runner);

        app(CardaqExportConnector::class)->run(new Target($account, app(ReportDateResolver::class)->periodFor('2026-09-14'), collect()), $run);

        return $captured;
    }

    public function test_hostinger_is_the_default_mailbox()
    {
        $payload = $this->payloadFor([]);

        $this->assertSame('hostinger', $payload['mail_provider']);
        $this->assertSame('https://mail.hostinger.com/auth/login?p=1&c=all', $payload['login_url']);
        $this->assertSame('https://mail.hostinger.com', $payload['mail_base_url']);
    }

    public function test_the_account_can_use_privateemail_instead()
    {
        $payload = $this->payloadFor(['mail_provider' => 'privateemail', 'search_query' => 'WORKING AGENT LTD']);

        $this->assertSame('privateemail', $payload['mail_provider']);
        $this->assertSame('https://privateemail.com/login', $payload['login_url']);
        $this->assertSame('https://privateemail.com', $payload['mail_base_url']);
        $this->assertSame('WORKING AGENT LTD', $payload['search_query']);
        $this->assertSame(['2026-09-14', '2026-09-14'], [$payload['report_date'], $payload['to']]);
    }

    public function test_an_unknown_mailbox_is_refused_on_save()
    {
        $admin = User::factory()->superAdmin()->create();
        $base = ['provider_id' => $this->cardaq()->id, 'connector' => 'cardaq-export', 'name' => 'Cardaq', 'username' => 'a@b.test', 'password' => 'x'];

        $this->actingAs($admin)->post(route('admin.bots.accounts.store'), [...$base, 'settings' => '{"mail_provider": "gmail"}'])
            ->assertSessionHasErrors('settings.mail_provider');
        $this->actingAs($admin)->post(route('admin.bots.accounts.store'), [...$base, 'settings' => '{"mail_provider": "privateemail"}'])
            ->assertSessionHasNoErrors();
    }
}
