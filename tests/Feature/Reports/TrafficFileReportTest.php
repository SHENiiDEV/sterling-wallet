<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Reports\Generation\ClientTrafficReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TrafficFileReportTest extends TestCase
{
    use RefreshDatabase;

    private function export(): string
    {
        return implode("\n", [
            'commerce_name,status,resolution,created,amount,currency,card_mask,issuer_country,original_resolution_message',
            'BAZPAY TEST LTD,processed,ok,2026-11-02 10:00:00.123,100,EUR,416598******1234,DE,',
            'BAZPAY TEST LTD,processed,ok,2026-11-02 11:00:00,50,EUR,535456******9999,FR,',
            'BAZPAY TEST LTD,refunded,ok,2026-11-03 09:00:00,20,EUR,416598******1111,IE,',
            'BAZPAY TEST LTD,process_failed,timeout,2026-11-02 12:00:00,30,EUR,416598******2222,DE,Declined by 3DS',
            'BAZPAY TEST LTD,process_failed,client_auth_failed,2026-11-03 13:00:00,40,EUR,535456******3333,FR,',
            'BAZPAY TEST LTD,process_failed,ROUTING_ERROR,2026-11-02 14:00:00,10,EUR,416598******4444,DE,',
            'BAZPAY TEST LTD,created,,2026-11-03 15:00:00,99,EUR,416598******5555,DE,',
        ])."\n";
    }

    public function test_a_corefy_export_becomes_a_traffic_report_without_routing_errors()
    {
        $file = UploadedFile::fake()->createWithContent('payments.csv', $this->export());

        $data = app(ClientTrafficReport::class)->dataFromFile($file);

        $this->assertSame('BAZPAY TEST LTD', $data['title']);
        // Routing errors and invoices that were never tried are not attempts.
        $this->assertSame([5, 3, 2, 60.0, 1], [$data['totals']['attempts'], $data['totals']['successful'], $data['totals']['declined'], $data['totals']['rate'], $data['routingErrors']]);
        $this->assertTrue($data['totals']['amount']['EUR']->isEqualTo('170.00'));
        $this->assertSame(['2026-11-02', '2026-11-03'], array_keys($data['days']));
        $this->assertSame('2026-11-02', $data['from']->toDateString());
        $this->assertEqualsCanonicalizing(['declined by 3ds', 'client auth failed'], array_column($data['reasons'], 'reason'));
        $this->assertEqualsCanonicalizing(['visa', 'mastercard'], array_column($data['schemes'], 'name'));

        // A period inside the file, and a title of our own.
        $day = app(ClientTrafficReport::class)->dataFromFile(
            UploadedFile::fake()->createWithContent('payments.csv', $this->export()), 'My client', CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-03'),
        );
        $this->assertSame(['My client', 2], [$day['title'], $day['totals']['attempts']]);
    }

    public function test_the_upload_returns_the_pdf_or_xlsx_and_refuses_a_file_that_is_not_an_export()
    {
        $admin = User::factory()->superAdmin()->create();

        $pdf = $this->actingAs($admin)->post(route('admin.traffic.file'), ['file' => UploadedFile::fake()->createWithContent('payments.csv', $this->export())]);
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('traffic_BAZPAY_TEST_LTD_2026-11-02_2026-11-03.pdf', (string) $pdf->headers->get('Content-Disposition'));

        $this->actingAs($admin)->post(route('admin.traffic.file'), ['file' => UploadedFile::fake()->createWithContent('payments.csv', $this->export()), 'format' => 'xlsx'])->assertOk();

        $this->actingAs($admin)->post(route('admin.traffic.file'), ['file' => UploadedFile::fake()->createWithContent('other.csv', "a,b,c\n1,2,3\n")])->assertSessionHasErrors('file');
    }
}
