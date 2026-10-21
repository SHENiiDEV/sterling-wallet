<?php

namespace Tests\Feature\Admin;

use App\Enums\ProviderType;
use App\Models\AdminAuditLog;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Models\User;
use App\Reports\Parsers\FinBridgesParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SplFileInfo;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class OperationManagementTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $midId, int $providerId, array $overrides = []): array
    {
        return [
            'merchant_mid_id' => $midId,
            'provider_id' => $providerId,
            'payment_id' => 'MAN-1',
            'operation_type' => 'sale',
            'report_date' => '2026-09-15',
            'transaction_at' => '2026-09-15 10:30:00',
            'amount' => '125.50',
            'currency' => 'EUR',
            'card_bin' => '411111',
            'card_last4' => '1111',
            'ips' => 'visa',
            'region' => 'eu',
            'customer_name' => 'Gregor Engel',
            'customer_account' => 'AT562050500001581487',
            'reason' => 'Landed on another terminal',
            ...$overrides,
        ];
    }

    /**
     * @return list<MerchantOperation>
     */
    private function cardOperations(): array
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);

        return array_map(fn (array $card) => MerchantOperation::query()->create([
            'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'provider_id' => $cardaq->id, 'role' => $cardaq->type,
            'mid' => $mid->mid, 'payment_id' => 'P-'.$card[0].$card[1], 'operation_type' => 'sale', 'trn_type' => 'sale', 'ips' => 'visa',
            'card_bin' => $card[0], 'card_last4' => $card[1], 'amount' => '10.00', 'currency' => 'EUR',
            'transaction_at' => '2026-09-15 10:00:00', 'report_date' => '2026-09-15',
        ]), [['535765', '2604'], ['492182', '1171'], ['465865', '1016'], ['411111', '1111']]);
    }

    public function test_card_masks_are_searched_together_and_exported()
    {
        $this->cardOperations();

        $this->actingAs($this->admin)
            ->get(route('admin.operations.index', ['search' => 'Card number - 535765%2604 Card number - 492182%1171 Card number - 465865%1016']))
            ->assertInertia(fn ($page) => $page->has('operations.data', 3));

        $this->get(route('admin.operations.index', ['search' => '411111****1111']))
            ->assertInertia(fn ($page) => $page->has('operations.data', 1));

        $csv = $this->get(route('admin.operations.export', ['search' => '535765%2604; 492182%1171']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Payment ID', $csv);
        $this->assertStringContainsString('P-5357652604', $csv);
        $this->assertStringContainsString('P-4921821171', $csv);
        $this->assertStringNotContainsString('P-4658651016', $csv);
    }

    public function test_an_operation_can_be_added_by_hand()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);

        $this->actingAs($this->admin)->get(route('admin.operations.create'))->assertOk();

        $response = $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $cardaq->id));

        $operation = MerchantOperation::query()->firstOrFail();
        $response->assertRedirect(route('admin.operations.show', $operation));
        $this->assertSame($mid->merchant_id, $operation->merchant_id);
        $this->assertSame('bank', $operation->role->value);
        $this->assertSame($mid->mid, $operation->mid);
        $this->assertTrue($operation->raw['manual']);
        $this->assertSame('Landed on another terminal', $operation->raw['reason']);
        $this->assertSame('Gregor Engel', $operation->raw['remitterName']);
        $this->assertSame('AT562050500001581487', $operation->raw['remitterAccount']);
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'operation.created')->count());

        $task = DailyReportTask::query()->sole();
        $this->assertSame($mid->id, $task->merchant_mid_id);
        $this->assertSame('2026-09-15', $task->report_date->toDateString());
        $this->assertTrue($task->sources()->where('provider_id', $cardaq->id)->exists());
    }

    public function test_a_provider_outside_the_mid_and_duplicates_are_refused()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);
        $other = $this->madfin();

        $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $other->id))
            ->assertSessionHasErrors('provider_id');

        $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $cardaq->id));
        $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $cardaq->id))
            ->assertSessionHasErrors('payment_id');

        $this->assertSame(1, MerchantOperation::query()->count());
    }

    public function test_deleting_needs_the_payment_id_and_a_reason()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);
        $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $cardaq->id));
        $operation = MerchantOperation::query()->firstOrFail();

        $this->actingAs($this->admin)->delete(route('admin.operations.destroy', $operation), ['confirm' => 'nope', 'reason' => 'Wrong terminal'])
            ->assertSessionHasErrors('confirm');
        $this->actingAs($this->admin)->delete(route('admin.operations.destroy', $operation), ['confirm' => 'MAN-1'])
            ->assertSessionHasErrors('reason');
        $this->assertSame(1, MerchantOperation::query()->count());

        $this->actingAs($this->admin)->delete(route('admin.operations.destroy', $operation), ['confirm' => 'MAN-1', 'reason' => 'Wrong terminal'])
            ->assertRedirect(route('admin.operations.index'));

        $this->assertSame(0, MerchantOperation::query()->count());
        $log = AdminAuditLog::query()->where('action', 'operation.deleted')->firstOrFail();
        $this->assertSame('Wrong terminal', $log->meta['reason']);
        $this->assertSame('MAN-1', $log->meta['snapshot']['payment_id']);
    }

    public function test_a_missing_report_can_be_created_from_existing_operations()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);
        MerchantOperation::query()->create([
            'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'provider_id' => $cardaq->id, 'role' => 'bank', 'mid' => $mid->mid,
            'payment_id' => 'X1', 'operation_type' => 'sale', 'report_date' => '2026-09-15', 'amount' => '10.00', 'currency' => 'EUR',
        ]);

        $this->artisan('reports:ensure', ['mid' => $mid->mid, 'date' => '2026-09-15'])->assertSuccessful();

        $task = DailyReportTask::query()->sole();
        $this->assertSame($mid->id, $task->merchant_mid_id);
        $this->assertTrue($task->sources()->where('provider_id', $cardaq->id)->exists());
        $this->artisan('reports:ensure', ['mid' => $mid->mid, 'date' => '2026-09-14'])->assertFailed();
    }

    public function test_an_operation_can_be_edited_with_a_reason()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);
        $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $cardaq->id));
        $operation = MerchantOperation::query()->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.operations.edit', $operation))->assertOk();

        $this->actingAs($this->admin)->put(route('admin.operations.update', $operation), $this->payload($mid->id, $cardaq->id, ['amount' => '99.00', 'customer_name' => '', 'reason' => '']))
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)->put(route('admin.operations.update', $operation), $this->payload($mid->id, $cardaq->id, ['amount' => '99.00', 'customer_name' => 'Anna Berg', 'reason' => 'Wrong amount']))
            ->assertRedirect(route('admin.operations.show', $operation));

        $operation->refresh();
        $this->assertSame('99.0000', $operation->amount);
        $this->assertSame('Anna Berg', $operation->raw['remitterName']);
        $this->assertSame('Anna Berg', $operation->customerName());
        $this->assertSame('Wrong amount', $operation->raw['edits'][0]['reason']);
        $log = AdminAuditLog::query()->where('action', 'operation.updated')->firstOrFail();
        $this->assertSame('Wrong amount', $log->meta['reason']);
        $this->assertArrayHasKey('amount', $log->meta['changes']);
    }

    public function test_a_mid_with_history_can_be_deleted_with_everything_on_it()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $merchant = $this->merchantWithTariff();
        $keep = $this->mid($merchant, $cardaq, $corefy, ['mid' => 'KEEP-1']);
        $drop = $this->mid($merchant, $cardaq, $corefy, ['mid' => '6741425_1']);

        foreach ([$keep, $drop] as $mid) {
            $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $cardaq->id, ['payment_id' => 'P-'.$mid->mid]));
        }
        $this->assertSame(2, MerchantOperation::query()->count());
        $this->assertSame(2, DailyReportTask::query()->count());

        // Wrong confirmation: nothing happens.
        $this->actingAs($this->admin)->delete(route('admin.merchants.mids.purge', [$merchant, $drop]), ['confirm' => 'nope'])
            ->assertSessionHasErrors('confirm');
        $this->assertDatabaseHas('merchant_mids', ['id' => $drop->id]);

        $this->actingAs($this->admin)->delete(route('admin.merchants.mids.purge', [$merchant, $drop]), ['confirm' => '6741425_1'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('merchant_mids', ['id' => $drop->id]);
        $this->assertDatabaseHas('merchant_mids', ['id' => $keep->id]);
        $this->assertSame(['P-KEEP-1'], MerchantOperation::query()->pluck('payment_id')->all());
        $this->assertSame([$keep->id], DailyReportTask::query()->pluck('merchant_mid_id')->all());
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'mid.purged')->count());
    }

    public function test_a_list_of_transfers_is_imported_with_references_and_updated_later()
    {
        $bank = Provider::factory()->create(['name' => 'FinBridges', 'code' => 'finbridges', 'type' => ProviderType::Bank, 'timezone' => 'UTC', 'report_delay_days' => 0]);
        $mid = $this->mid($this->merchantWithTariff(), $bank, null, ['mid' => 'CJPU1K100']);
        $file = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($file, implode("\n", [
            'payment_id,reference,amount,currency,transaction_at,processing_at,customer_name,customer_account,customer_bic',
            'a2fcf4a9,,73.00,EUR,2026-09-15 16:06,2026-09-15 16:08,Gregor Engel,AT56 2050 5000 0158 1487,SPKIAT2KXXX',
            'a2ff4580,890661,105.00,EUR,2026-09-16 19:44,2026-09-16 19:45,Susanne Jutta Gaus,DE44 1007 7777 0448 3848 00,NORSDE51XXX',
        ]));

        $this->artisan('operations:import-manual', ['file' => $file, '--mid' => 'CJPU1K100', '--merchant' => $mid->merchant->public_id, '--timezone' => 'Europe/Riga'])->assertSuccessful();

        $this->assertSame(2, MerchantOperation::query()->count());
        $gregor = MerchantOperation::query()->where('payment_id', 'a2fcf4a9')->firstOrFail();
        $this->assertSame('2026-09-15 13:06:00', $gregor->transaction_at->format('Y-m-d H:i:s')); // Riga is UTC+3 in September
        $this->assertSame('Gregor Engel', $gregor->customerName());
        $this->assertNull($gregor->reference);
        $this->assertSame(2, DailyReportTask::query()->count());

        // Again without --update: nothing changes. With it, the reference is added.
        file_put_contents($file, implode("\n", [
            'payment_id,reference,amount,currency,transaction_at,processing_at,customer_name,customer_account,customer_bic',
            'a2fcf4a9,890650,73.00,EUR,2026-09-15 16:06,2026-09-15 16:08,Gregor Engel,AT56 2050 5000 0158 1487,SPKIAT2KXXX',
        ]));
        $this->artisan('operations:import-manual', ['file' => $file, '--mid' => 'CJPU1K100', '--timezone' => 'Europe/Riga'])->assertSuccessful();
        $this->assertNull($gregor->fresh()->reference);
        $this->artisan('operations:import-manual', ['file' => $file, '--mid' => 'CJPU1K100', '--timezone' => 'Europe/Riga', '--update' => true])->assertSuccessful();
        $this->assertSame('890650', $gregor->fresh()->reference);
        $this->assertSame(2, MerchantOperation::query()->count());

        // A wrong merchant is refused, a dry run writes nothing.
        $this->artisan('operations:import-manual', ['file' => $file, '--mid' => 'CJPU1K100', '--merchant' => 'mer_nope'])->assertFailed();
        MerchantOperation::query()->delete();
        $this->artisan('operations:import-manual', ['file' => $file, '--mid' => 'CJPU1K100', '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, MerchantOperation::query()->count());
        @unlink($file);
    }

    public function test_the_payers_reference_is_read_from_the_payment_description()
    {
        $parser = new FinBridgesParser;
        $file = tempnam(sys_get_temp_dir(), 'fb');
        $tx = fn (string $number, string $details) => ['walletId' => 'CJPU1K100', 'transactionNumber' => $number, 'transactionType' => 'DEPOSIT', 'status' => 'COMPLETED', 'date' => 1792061812603, 'amountOrigCcy' => 10, 'origCcy' => 'EUR', 'details' => $details];
        file_put_contents($file, json_encode(['list' => [$tx('T1', 'Zahlungs- / Referenznummer: 890661'), $tx('T2', '891153'), $tx('T3', 'Invoice paid')]]));

        $rows = iterator_to_array($parser->rows(new SplFileInfo($file), 'UTC'), false);

        $this->assertSame(['890661', '891153', 'Invoice paid'], array_map(fn ($r) => $r->reference, $rows));
        @unlink($file);
    }

    public function test_a_csv_list_of_transfers_can_be_uploaded_in_the_report_center()
    {
        Storage::fake('local');
        $bank = Provider::factory()->create(['name' => 'FinBridges', 'code' => 'finbridges', 'type' => ProviderType::Bank, 'report_format' => 'finbridges', 'timezone' => 'UTC', 'report_delay_days' => 0]);
        $this->mid($this->merchantWithTariff(), $bank, null, ['mid' => 'CJPU1K100']);
        $csv = implode("\n", [
            'wallet_id,payment_id,reference,amount,currency,transaction_at,customer_name,customer_account,customer_bic',
            'CJPU1K100,a2ff4580,890661,105.00,EUR,2026-09-16T19:44:00+02:00,Susanne Jutta Gaus,DE44 1007 7777 0448 3848 00,NORSDE51XXX',
            'CJPU1K100,a2feea04,891153,260.10,EUR,2026-09-16T15:28:00+02:00,Mw MD Jansen,NL45 INGB 0101 2587 71,INGBNL2A',
        ]);

        $file = UploadedFile::fake()->createWithContent('transfers.csv', $csv);
        $this->actingAs($this->admin)->post(route('admin.reports.upload'), ['provider_id' => $bank->id, 'file' => $file])->assertSessionHasNoErrors();

        $operation = MerchantOperation::query()->where('payment_id', 'a2ff4580')->firstOrFail();
        $this->assertSame('890661', $operation->reference);
        $this->assertSame('Susanne Jutta Gaus', $operation->customerName());
        $this->assertSame('2026-09-16 17:44:00', $operation->transaction_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-16', $operation->report_date->toDateString());
        $this->assertSame(2, MerchantOperation::query()->count());
    }
}
