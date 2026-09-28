<?php

namespace Tests\Feature\Reports;

use App\Models\DailyReportTask;
use App\Reports\Generation\DailyStatement;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

/**
 * The merchant PDF on the worked example of DailyReportGeneratorTest.
 */
class DailyStatementTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private function workedExample(): DailyReportTask
    {
        Storage::fake('local');
        Mail::fake();

        $merchant = $this->merchantWithTariff(['crypto_provider_id' => $this->oxen()->id]);
        $mid = $this->mid($merchant, $this->cardaq(), $this->corefy());
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($mid->gateProvider, $this->corefyCsv($mid->gate_mid, 'EUR', '2026-09-15'), CarbonImmutable::parse('2026-09-15'));

        return DailyReportTask::query()->sole();
    }

    public function test_it_explains_the_payout_step_by_step()
    {
        $data = app(DailyStatement::class)->data($this->workedExample());

        $steps = collect($data['steps'])->mapWithKeys(fn ($s) => [$s['label'] => (string) $s['amount']])->all();
        $this->assertSame([
            'Gross sales' => '350.00',
            'Refunds' => '-30.00',
            'Chargebacks' => '0.00',
            'Processing fee' => '-13.00',
            'Transaction fees' => '-1.80',
            'Net after fees' => '305.20',
            'Rolling reserve' => '-30.52',
            'Net volume' => '274.68',
            'Conversion fee' => '-1.10',
            'Net payout' => '273.58',
        ], $steps);

        $detail = collect($data['steps'])->pluck('detail', 'label');
        $this->assertSame('3 × 0.20 EUR approved sale · 1 × 1.00 EUR refund · 2 × 0.10 EUR declined attempt', $detail['Transaction fees']);
        $this->assertSame('10% of net after fees · released 2027-03-14', $detail['Rolling reserve']);
        $this->assertSame('0.4% fiat → crypto', $detail['Conversion fee']);

        $schemes = collect($data['schemes'])->map(fn ($r) => [$r['label'], $r['count'], (string) $r['amount'], (string) $r['rate']->toScale(2), (string) $r['fee']])->all();
        $this->assertSame([
            ['Mastercard · Non-EU', 1, '200.00', '4.00', '8.00'],
            ['Visa · EU / EEA', 1, '100.00', '3.00', '3.00'],
            ['Visa · Non-EU', 1, '50.00', '4.00', '2.00'],
        ], $schemes);

        $this->assertSame(4, $data['operationsTotal']); // 3 sales + 1 refund, no declines
        $this->assertSame(60.0, $data['approvalRate']);
    }

    public function test_the_pdf_shows_no_internal_cost_or_profit()
    {
        $task = $this->workedExample();
        $html = view('reports.daily', app(DailyStatement::class)->data($task))->render();

        $this->assertStringContainsString('Daily Processing Report', $html);
        $this->assertStringContainsString('273.58', $html);
        $this->assertStringNotContainsString('6.91', $html); // our net profit
        $this->assertStringNotContainsString('8.99', $html); // provider cost

        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($task->generated_pdf_path));
    }

    public function test_documents_can_be_rebuilt_without_recalculating()
    {
        $task = $this->workedExample();
        Storage::disk('local')->delete($task->generated_pdf_path);
        $task->forceFill(['net_payout' => '999.00'])->saveQuietly();

        $this->artisan('reports:rebuild-documents', ['--from' => '2026-09-01'])->assertSuccessful();

        Storage::disk('local')->assertExists($task->generated_pdf_path);
        $this->assertSame('999.0000', $task->fresh()->net_payout);
    }
}
