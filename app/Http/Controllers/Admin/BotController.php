<?php

namespace App\Http\Controllers\Admin;

use App\Bots\BotDispatcher;
use App\Bots\ConnectorRegistry;
use App\Bots\Target;
use App\Enums\BotRunStatus;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IntegrationAccountRequest;
use App\Models\BotRun;
use App\Models\IntegrationAccount;
use App\Models\MerchantMid;
use App\Models\Provider;
use App\Reports\ReportDateResolver;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BotController extends Controller
{
    public function index(Request $request, ConnectorRegistry $connectors): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'account' => ['nullable', 'integer'],
        ]);

        $accounts = IntegrationAccount::query()->with('provider:id,name,code')->orderBy('name')->get();

        return Inertia::render('admin/bots/index', [
            'accounts' => $accounts->map(fn (IntegrationAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'connector' => $account->connector,
                'provider' => ['id' => $account->provider->id, 'name' => $account->provider->name],
                'login_url' => $account->login_url,
                'has_username' => $account->username !== null,
                'has_password' => $account->password !== null,
                'has_totp' => $account->totp_secret !== null,
                'settings' => $account->settings,
                'mid_ids' => $account->mid_ids ?? [],
                'is_active' => $account->is_active,
                'last_run' => $this->runSummary($account->runs()->latest('id')->first()),
                'failures_in_row' => $this->failuresInRow($account),
            ]),
            'runs' => BotRun::query()
                ->with('account:id,name')
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($filters['account'] ?? null, fn ($q, $id) => $q->where('integration_account_id', $id))
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
                ->through(fn (BotRun $run) => $this->runSummary($run)),
            'filters' => (object) $filters,
            'connectors' => $connectors->options(),
            'statuses' => BotRunStatus::options(),
            'providers' => Provider::query()->whereIn('type', [ProviderType::Bank, ProviderType::Gate])->orderBy('name')->get(['id', 'name', 'connector']),
            'mids' => MerchantMid::query()->with('merchant:id,name')->orderBy('mid')->get(['id', 'mid', 'merchant_id', 'bank_provider_id', 'gate_provider_id'])
                ->map(fn (MerchantMid $mid) => [
                    'id' => $mid->id,
                    'mid' => $mid->mid,
                    'merchant' => $mid->merchant->name,
                    'provider_ids' => $mid->requiredProviderIds(),
                ]),
        ]);
    }

    public function store(IntegrationAccountRequest $request): RedirectResponse
    {
        $account = IntegrationAccount::query()->create($request->attributesToSave());
        AuditLogger::log('bot_account.created', $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account added.']);

        return to_route('admin.bots.index');
    }

    public function update(IntegrationAccountRequest $request, IntegrationAccount $account): RedirectResponse
    {
        $account->update($request->attributesToSave());
        AuditLogger::log('bot_account.updated', $account, ['changed' => array_keys($account->getChanges())]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account saved.']);

        return to_route('admin.bots.index');
    }

    public function destroy(IntegrationAccount $account): RedirectResponse
    {
        AuditLogger::log('bot_account.deleted', $account, ['name' => $account->name]);
        $account->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account deleted.']);

        return to_route('admin.bots.index');
    }

    /**
     * "Run now": fetch one report date for this account, even if it was fetched before.
     */
    public function run(Request $request, IntegrationAccount $account, BotDispatcher $dispatcher, ConnectorRegistry $connectors, ReportDateResolver $dates): RedirectResponse
    {
        $data = $request->validate(['report_date' => ['required', 'date', 'before_or_equal:today']]);
        $period = $dates->periodFor($data['report_date']);
        $connector = $connectors->get($account->connector);

        $mids = $account->coveredMids()->get();
        if ($mids->isEmpty()) {
            throw ValidationException::withMessages(['report_date' => 'This account covers no MIDs yet.']);
        }

        $targets = collect($connector->targetsForPeriod($account, $period, $mids));
        $queued = $targets->map(fn (Target $t) => $dispatcher->queue($connector, $t, $request->user()))->filter()->count();

        Inertia::flash('toast', ['type' => $queued ? 'success' : 'info', 'message' => $queued ? "{$queued} run(s) queued for {$period->reportDate->toDateString()}." : 'A run for this date is already queued.']);

        return to_route('admin.bots.index');
    }

    public function retry(Request $request, BotRun $run, BotDispatcher $dispatcher): RedirectResponse
    {
        $new = $dispatcher->retry($run, $request->user());

        Inertia::flash('toast', ['type' => $new ? 'success' : 'info', 'message' => $new ? 'Run queued again.' : 'This target is already queued.']);

        return back();
    }

    public function screenshot(BotRun $run): BinaryFileResponse
    {
        $path = $run->screenshot_path;
        $root = realpath(storage_path('app'));
        $real = $path ? realpath($path) : false;

        abort_unless($real && $root && str_starts_with($real, $root) && is_file($real), 404);

        return response()->file($real);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runSummary(?BotRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'connector' => $run->connector,
            'account' => $run->relationLoaded('account') ? $run->account?->name : null,
            'report_date' => $run->report_date->toDateString(),
            'target_key' => $run->target_key,
            'mids' => $run->target['mids'] ?? [],
            'status' => $run->status->value,
            'attempts' => $run->attempts,
            'rows_count' => $run->rows_count,
            'files' => array_map('basename', $run->files ?? []),
            'error' => $run->error,
            'log' => $run->log,
            'has_screenshot' => $run->screenshot_path !== null,
            'duration_ms' => $run->duration_ms,
            'created_at' => $run->created_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    private function failuresInRow(IntegrationAccount $account): int
    {
        $count = 0;
        foreach ($account->runs()->whereIn('status', [BotRunStatus::Succeeded, BotRunStatus::Failed])->latest('id')->limit(10)->pluck('status') as $status) {
            if ($status !== BotRunStatus::Failed) {
                break;
            }
            $count++;
        }

        return $count;
    }
}
