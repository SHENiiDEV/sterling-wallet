<?php

namespace Tests\Feature\Bots;

use App\Bots\BotDispatcher;
use App\Bots\BotResult;
use App\Bots\PlaywrightRunner;
use App\Enums\BotRunStatus;
use App\Jobs\RunConnectorJob;
use App\Models\BotRun;
use App\Models\DailyReportTask;
use App\Models\IntegrationAccount;
use App\Models\MerchantMid;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class BotRunTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private Provider $corefy;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-17 09:00:00', 'Europe/Riga'));

        $this->corefy = $this->corefy();
        $this->mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy, ['reports_start_date' => '2026-09-15']);
    }

    private function account(array $overrides = []): IntegrationAccount
    {
        return IntegrationAccount::query()->create([
            'provider_id' => $this->corefy->id,
            'connector' => 'corefy-export',
            'name' => 'Corefy main',
            'username' => 'bot@example.test',
            'password' => 'hunter2',
            ...$overrides,
        ]);
    }

    /**
     * @param  callable(string, array<string, mixed>): BotResult  $respond
     */
    private function fakeRunner(callable $respond): void
    {
        $runner = Mockery::mock(PlaywrightRunner::class);
        $runner->shouldReceive('run')->andReturnUsing($respond);
        $this->app->instance(PlaywrightRunner::class, $runner);
    }

    public function test_credentials_are_encrypted_at_rest()
    {
        $account = $this->account();

        $stored = \DB::table('integration_accounts')->where('id', $account->id)->first();
        $this->assertNotSame('hunter2', $stored->password);
        $this->assertSame('hunter2', $account->fresh()->password);
        $this->assertArrayNotHasKey('password', $account->toArray());
    }

    public function test_scheduler_queues_one_run_per_missing_period_and_commerce_account()
    {
        Queue::fake();
        $this->account();

        $this->artisan('bots:dispatch')->assertSuccessful();

        $runs = BotRun::query()->orderBy('report_date')->get();
        $this->assertSame(['2026-09-15:coma_TEST1', '2026-09-16:coma_TEST1'], $runs->pluck('target_key')->all());
        Queue::assertPushed(RunConnectorJob::class, 2);

        // Nothing new while those runs are still queued.
        $this->artisan('bots:dispatch')->assertSuccessful();
        $this->assertSame(2, BotRun::query()->count());
    }

    public function test_inactive_accounts_are_not_scheduled()
    {
        Queue::fake();
        $this->account(['is_active' => false]);

        $this->artisan('bots:dispatch')->assertSuccessful();

        $this->assertSame(0, BotRun::query()->count());
    }

    public function test_a_successful_run_feeds_ingestion()
    {
        $file = $this->corefyCsv(day: '2026-09-15')->getPathname();
        $seen = [];
        $this->fakeRunner(function (string $script, array $input) use ($file, &$seen) {
            $seen[] = $input;

            return new BotResult(BotRunStatus::Succeeded, files: [$file], rows: 5, reportDate: CarbonImmutable::parse($input['report_date']));
        });
        $account = $this->account();

        $run = app(BotDispatcher::class)->dispatchDue()[0]->fresh();

        $this->assertSame(BotRunStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->attempts);
        $this->assertCount(2, $seen); // 15th and 16th are both due
        $this->assertSame('coma_TEST1', $seen[0]['commerce_account']);
        $this->assertSame('bot@example.test', $seen[0]['username']);
        // Riga midnight 2026-09-15 = 2026-09-14 21:00 UTC; the range ends at the next local midnight.
        $this->assertSame(CarbonImmutable::parse('2026-09-14 21:00:00', 'UTC')->getTimestamp(), $seen[0]['gte']);
        $this->assertSame(CarbonImmutable::parse('2026-09-15 21:00:00', 'UTC')->getTimestamp(), $seen[0]['lt']);

        $task = DailyReportTask::query()->where('report_date', '2026-09-15')->sole();
        $this->assertSame($run->id, $task->sources()->sole()->bot_run_id);
        $this->assertSame(5, $this->mid->operations()->count());
        $this->assertSame($account->id, $run->integration_account_id);
    }

    public function test_zero_rows_marks_the_day_as_received()
    {
        $this->fakeRunner(fn () => new BotResult(BotRunStatus::Succeeded, files: [], rows: 0));
        $this->account();

        app(BotDispatcher::class)->dispatchDue();

        $this->assertSame(2, DailyReportTask::query()->count());
        $this->assertSame(0, (int) DailyReportTask::query()->first()->sources()->sole()->rows_count);
    }

    public function test_skipped_runs_are_retried_by_the_next_schedule()
    {
        $this->fakeRunner(fn () => new BotResult(BotRunStatus::Skipped, error: 'No e-mail yet.'));
        $this->account();

        app(BotDispatcher::class)->dispatchDue();
        $this->assertSame(2, BotRun::query()->where('status', BotRunStatus::Skipped)->count());

        app(BotDispatcher::class)->dispatchDue();
        $this->assertSame(4, BotRun::query()->count());
    }

    public function test_failures_are_journaled_and_alerted_after_three_in_a_row()
    {
        config(['sterling.bots.telegram_token' => 'tg', 'sterling.bots.telegram_chat_id' => '42']);
        Http::fake();
        $this->fakeRunner(fn () => new BotResult(BotRunStatus::Failed, error: 'Login failed', screenshot: null, log: 'trace'));
        $account = $this->account();

        foreach (range(1, 3) as $attempt) {
            $run = BotRun::query()->create([
                'connector' => 'corefy-export', 'integration_account_id' => $account->id, 'report_date' => '2026-09-15',
                'target_key' => "k{$attempt}", 'status' => BotRunStatus::Queued,
                'target' => ['report_date' => '2026-09-15', 'from' => '2026-09-15', 'to' => '2026-09-15', 'mid_ids' => [$this->mid->id], 'params' => ['commerce_account' => 'coma_TEST1']],
            ]);

            $job = new RunConnectorJob($run->id);
            try {
                app()->call([$job, 'handle']);
                $this->fail('The job should throw so the queue retries it.');
            } catch (RuntimeException $e) {
                $this->assertSame('Login failed', $e->getMessage());
            }
            $job->failed($e);

            $run->refresh();
            $this->assertSame(BotRunStatus::Failed, $run->status);
            $this->assertSame('Login failed', $run->error);
            $this->assertSame('trace', $run->log);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage') && str_contains($request['text'], '3 times in a row'));
    }

    public function test_the_runner_reads_the_last_json_line_of_a_script()
    {
        $dir = storage_path('framework/testing/bots');
        @mkdir($dir, 0777, true);
        file_put_contents("{$dir}/echo-bot.mjs", <<<'JS'
            let input = '';
            process.stdin.on('data', (c) => (input += c));
            process.stdin.on('end', () => {
                const data = JSON.parse(input);
                console.error('log line');
                console.log('noise');
                console.log(JSON.stringify({ status: 'skipped', error: `no mail for ${data.report_date}` }));
            });
            JS);
        config(['sterling.bots.scripts_path' => $dir]);

        $result = app(PlaywrightRunner::class)->run('echo-bot', ['report_date' => '2026-09-15']);

        $this->assertSame(BotRunStatus::Skipped, $result->status);
        $this->assertSame('no mail for 2026-09-15', $result->error);
        $this->assertStringContainsString('log line', $result->log);
    }
}
