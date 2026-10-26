<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\User;
use App\Reports\Ingestion\ReportIngestionService;
use App\Settlements\SettlementService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

/**
 * Mastercard Collaboration cases: uploaded as the acquirer's `mc_collab` CSV, charged on the day they were
 * opened at the requested amount plus the merchant's per-case fee, and marked for dispute from a threshold.
 */
class CollabTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();

        $merchant = $this->merchantWithTariff(['crypto_provider_id' => $this->oxen()->id, 'fee_collab_fixed' => '5.0000', 'collab_dispute_threshold' => '20.00']);
        $this->mid = $this->mid($merchant, $this->cardaq(), $this->corefy());
    }

    private function collabCsv(): UploadedFile
    {
        $header = 'CARD,CARD_TYPE,ACQ_REF_NR,MERCHANT_NAME,MERCHANT_ID,MCC_CODE,COLLAB_CREATE_DATE,COLLAB_RESPONSE_DEADLINE,CLAIM_ID,COLLABORATION_ID,COLLAB_AMOUNT,COLLAB_CCY,TR_AMOUNT,TR_CCY,TR_DATE,PAYMENT_ID,TR_RET_REF_NR,MERCHANT_DECISION';
        $rows = [
            "516794%6689,MC,7270497629500506126939,shop,{$this->mid->mid},7399,2026.09.15 10:10:16,2026.09.17 22:10:16,2015831996892,3015831996928,20.00,EUR,20.00,EUR,2026.09.15 14:06:35,1220113513,629411096993,",
            "516794%2916,MC,7270497629600506317552,shop,{$this->mid->mid},7399,2026.09.15 10:44:59,2026.09.17 22:44:59,2015832230219,3015832230222,17.86,EUR,17.86,EUR,2026.09.15 21:40:08,1220745311,629518962139,",
            "-----mc_collab:footer-----,,,,,,,,,,,,,,,,,",
            'rows=2,timestamp=2026-09-16 13:10:30,,,,,,,,,,,,,,,,',
        ];

        return UploadedFile::fake()->createWithContent('collab.csv', implode("\n", [$header, ...$rows])."\n");
    }

    public function test_the_collab_file_is_recognised_and_its_cases_become_operations()
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.reports.upload'), ['provider_id' => $this->mid->bank_provider_id, 'file' => $this->collabCsv()])
            ->assertSessionHasNoErrors();

        $collabs = MerchantOperation::query()->where('operation_type', OperationType::Collab)->orderBy('amount')->get();
        $this->assertSame(['17.8600', '20.0000'], $collabs->pluck('amount')->all());
        $this->assertSame('3015831996928', $collabs->last()->raw['COLLABORATION_ID']);
        $this->assertSame('2026-09-15', $collabs->last()->report_date->toDateString());

        // The same file again changes nothing.
        $this->post(route('admin.reports.upload'), ['provider_id' => $this->mid->bank_provider_id, 'file' => $this->collabCsv()]);
        $this->assertSame(2, MerchantOperation::query()->where('operation_type', OperationType::Collab)->count());

        // 20.00 reaches the merchant's threshold of 20.00, 17.86 does not.
        $this->get(route('admin.operations.show', $collabs->last()))
            ->assertInertia(fn (Assert $page) => $page->where('operation.collab.dispute', true)->where('operation.collab.claim_id', '2015831996892'));
        $this->get(route('admin.operations.show', $collabs->first()))
            ->assertInertia(fn (Assert $page) => $page->where('operation.collab.dispute', false));
    }

    public function test_a_collab_is_charged_on_the_report_with_the_per_case_fee()
    {
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($this->mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($this->mid->gateProvider, $this->corefyCsv($this->mid->gate_mid, 'EUR', '2026-09-15'), CarbonImmutable::parse('2026-09-15'));

        $before = DailyReportTask::query()->where('merchant_mid_id', $this->mid->id)->sole()->fresh();
        $this->assertSame(0.0, (float) $before->collabs_amount);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.reports.upload'), ['provider_id' => $this->mid->bank_provider_id, 'file' => $this->collabCsv()])
            ->assertSessionHasNoErrors();

        $task = DailyReportTask::query()->where('merchant_mid_id', $this->mid->id)->sole()->fresh();

        // Two cases: 20.00 + 17.86 requested, 2 × 5.00 per case on top.
        $this->assertTrue(BigDecimal::of((string) $task->collabs_amount)->isEqualTo('37.86'), (string) $task->collabs_amount);
        $this->assertSame(2, $task->summary_data['counts']['collabs']);
        $this->assertTrue(BigDecimal::of((string) $task->total_merchant_fee)->isEqualTo('24.80'), (string) $task->total_merchant_fee);
        $this->assertSame(2, $task->summary_data['merchant_fee']['fixed_lines']['collab']['count']);
        $this->assertTrue(BigDecimal::of((string) $task->net_payout)->isLessThan((string) $before->net_payout));
    }

    public function test_a_collab_moves_to_the_next_report_when_its_own_report_is_already_in_a_settlement()
    {
        $ingestion = app(ReportIngestionService::class);
        foreach (['2026-09-15', '2026-09-16'] as $day) {
            $ingestion->ingest($this->mid->bankProvider, $this->cardaqCsv(day: $day), CarbonImmutable::parse($day));
            $ingestion->ingest($this->mid->gateProvider, $this->corefyCsv($this->mid->gate_mid, 'EUR', $day), CarbonImmutable::parse($day));
        }

        $first = DailyReportTask::query()->where('report_date', '2026-09-15')->sole();
        $admin = User::factory()->superAdmin()->create();
        app(SettlementService::class)->createDraft($this->mid->merchant, $admin, [$first->id]);

        $this->actingAs($admin)->post(route('admin.reports.upload'), ['provider_id' => $this->mid->bank_provider_id, 'file' => $this->collabCsv()])
            ->assertSessionHasNoErrors();

        // The 15th is in a settlement, so the cases are charged on the 16th.
        $this->assertSame(0.0, (float) $first->fresh()->collabs_amount);
        $second = DailyReportTask::query()->where('report_date', '2026-09-16')->sole()->fresh();
        $this->assertTrue(BigDecimal::of((string) $second->collabs_amount)->isEqualTo('37.86'), (string) $second->collabs_amount);
        $this->assertSame(['2026-09-16'], MerchantOperation::query()->where('operation_type', OperationType::Collab)->get()->map(fn ($op) => $op->report_date->toDateString())->unique()->values()->all());

        // Uploading the file again does not move them back or on.
        $this->post(route('admin.reports.upload'), ['provider_id' => $this->mid->bank_provider_id, 'file' => $this->collabCsv()]);
        $this->assertSame(2, DailyReportTask::query()->where('report_date', '2026-09-16')->sole()->fresh()->summary_data['counts']['collabs']);
    }
}
