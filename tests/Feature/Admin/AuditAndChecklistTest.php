<?php

namespace Tests\Feature\Admin;

use App\Enums\Currency;
use App\Enums\Module;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class AuditAndChecklistTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_the_audit_log_is_filterable_and_only_for_team_access()
    {
        $admin = User::factory()->superAdmin()->create(['name' => 'Boss']);
        $this->actingAs($admin);
        AuditLogger::log('settlement.approved', null, ['total' => '10']);
        AuditLogger::log('report.source_downloaded', null, ['file' => 'a.csv']);

        $this->get(route('admin.audit.index'))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 2)->where('logs.data.0.user', 'Boss'));

        $this->get(route('admin.audit.index', ['action' => 'settlement']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'settlement.approved')->where('areas', ['report', 'settlement']));

        $this->get(route('admin.audit.index', ['search' => 'a.csv']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));

        $this->assertSame(2, AdminAuditLog::query()->count());

        $limited = User::factory()->withModules([Module::Merchants])->create();
        $this->actingAs($limited)->get(route('admin.audit.index'))->assertForbidden();
    }

    public function test_the_checklist_lists_what_is_missing_for_live_merchants_and_terminals()
    {
        $admin = User::factory()->superAdmin()->create();
        $merchant = $this->merchantWithTariff(['name' => 'Half Ready', 'invoice_email' => null]);
        $this->mid($merchant, $this->cardaq(), $this->corefy());
        $this->mid($merchant, $this->cardaq(['code' => 'cardaq-2', 'name' => 'Cardaq 2']), null, ['mid' => '4400000002', 'currency' => Currency::Gbp]);

        $this->actingAs($admin)->get(route('admin.checklist.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('merchants', 1)
                ->where('merchants.0.name', 'Half Ready')
                ->where('counts.no_schedule', 1)
                ->where('counts.no_wallet', 1)
                ->where('counts.no_email', 1)
                ->where('counts.no_client', 1)
                ->where('counts.no_limit', 1)
                ->where('counts.no_fx', 1)
                ->where('counts.no_gate', 1)
                ->where('counts.no_bot', fn ($n) => $n >= 1));

        $this->get(route('admin.checklist.index', ['issue' => 'no_wallet']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('merchants.0.issues.0.code', 'no_wallet')
                ->where('merchants.0.mids', []));
    }
}
