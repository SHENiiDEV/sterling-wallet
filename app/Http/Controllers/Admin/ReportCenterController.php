<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Enums\ReserveEntryType;
use App\Enums\SettlementLineType;
use App\Enums\SettlementStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateDailyReportJob;
use App\Mail\DailyReportMail;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Models\ReserveLedgerEntry;
use App\Models\SettlementLine;
use App\Reports\Ingestion\ReportIngestionService;
use App\Reports\ReportDateResolver;
use App\Services\AuditLogger;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportCenterController extends Controller
{
    /**
     * MID × day grid for one month, with totals per currency.
     */
    public function index(Request $request, ReportDateResolver $dates): Response
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'merchant' => ['nullable', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'string'],
        ]);

        $start = CarbonImmutable::createFromFormat('!Y-m', $filters['month'] ?? now()->format('Y-m'));
        $end = $start->endOfMonth()->startOfDay();
        $today = CarbonImmutable::now(config('sterling.timezone'))->startOfDay();

        $tasks = DailyReportTask::query()
            ->with('sources:id,daily_report_task_id,provider_id')
            ->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->get();

        $mids = MerchantMid::query()
            ->with(['merchant:id,public_id,name', 'bankProvider:id,name,report_delay_days', 'gateProvider:id,name,report_delay_days'])
            ->where(fn (Builder $q) => $q->where('status', '!=', MidStatus::Inactive)->orWhereIn('id', $tasks->pluck('merchant_mid_id')))
            ->when($filters['merchant'] ?? null, fn (Builder $q, string $m) => $q->whereHas('merchant', fn (Builder $q) => $q->where('public_id', $m)))
            ->when($filters['currency'] ?? null, fn (Builder $q, string $c) => $q->where('currency', strtoupper($c)))
            ->orderBy('mid')
            ->get();

        $providerNames = Provider::query()->pluck('name', 'id');
        $byMid = $tasks->groupBy('merchant_mid_id');

        $rows = $mids->map(function (MerchantMid $mid) use ($byMid, $dates, $start, $end, $today, $providerNames, $filters) {
            $cells = [];
            foreach ($byMid->get($mid->id, collect()) as $task) {
                /** @var DailyReportTask $task */
                $missing = $task->status === ReportStatus::Completed ? [] : array_values(array_diff(
                    $mid->requiredProviderIds(),
                    $task->sources->pluck('provider_id')->all(),
                ));
                $cells[$task->report_date->toDateString()] = [
                    'id' => $task->id,
                    'status' => $task->status->value,
                    'from' => $task->period_from->toDateString(),
                    'to' => $task->period_to->toDateString(),
                    'reason' => $task->error_log,
                    'missing' => array_map(fn ($id) => $providerNames[$id] ?? '#'.$id, $missing),
                    'turnover' => $task->getRawOriginal('turnover'),
                    'net_profit' => $task->getRawOriginal('net_profit'),
                ];
            }

            // Periods that should have a report by now but have nothing yet.
            if (empty($filters['status']) && $mid->status === MidStatus::Active && $mid->bank_provider_id) {
                $delay = max($mid->bankProvider->report_delay_days ?? 0, $mid->gateProvider->report_delay_days ?? 0);
                $first = $mid->reports_start_date && $mid->reports_start_date->greaterThan($start) ? $mid->reports_start_date : $start;
                for ($day = $first; $day->lessThanOrEqualTo($end); $day = $period->to->addDay()) {
                    $period = $dates->periodFor($day);
                    $key = $period->reportDate->toDateString();
                    if ($period->reportDate->greaterThan($end) || isset($cells[$key])) {
                        continue;
                    }
                    if ($period->to->addDays($delay)->toDateString() <= $today->toDateString()) {
                        $cells[$key] = ['id' => null, 'status' => 'missing', 'from' => $period->from->toDateString(), 'to' => $key, 'reason' => 'No file received yet.', 'missing' => [], 'turnover' => null, 'net_profit' => null];
                    }
                }
            }

            return [
                'id' => $mid->id,
                'mid' => $mid->mid,
                'label' => $mid->label,
                'currency' => $mid->currency->value,
                'status' => $mid->status->value,
                'merchant' => ['public_id' => $mid->merchant->public_id, 'name' => $mid->merchant->name],
                'pair' => ($mid->bankProvider->name ?? '—').($mid->gateProvider ? ' ↔ '.$mid->gateProvider->name : ''),
                'cells' => (object) $cells,
            ];
        })->filter(fn (array $row) => empty($filters['status']) || count((array) $row['cells']) > 0)->values();

        return Inertia::render('admin/reports/index', [
            'month' => $start->format('Y-m'),
            'days' => (int) $start->daysInMonth,
            'today' => $today->toDateString(),
            'rows' => $rows,
            'totals' => $this->totals($tasks->where('status', ReportStatus::Completed)->pluck('id')->all()),
            'statusCounts' => $tasks->countBy(fn (DailyReportTask $t) => $t->status->value),
            'filters' => (object) $filters,
            'merchants' => Merchant::query()->orderBy('name')->get(['public_id', 'name']),
            'statuses' => ReportStatus::options(),
            'providers' => Provider::query()->whereIn('type', [ProviderType::Bank, ProviderType::Gate])->whereNotNull('report_format')->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    public function show(DailyReportTask $report): Response
    {
        $report->load(['merchant:id,public_id,name,invoice_email', 'merchantMid.bankProvider:id,name', 'merchantMid.gateProvider:id,name', 'sources.provider:id,name', 'sources.botRun:id,status']);
        $mid = $report->merchantMid;

        $settlementLines = SettlementLine::query()
            ->with('settlement:id,number,status')
            ->where('daily_report_task_id', $report->id)
            ->get()
            ->map(fn (SettlementLine $l) => [
                'type' => $l->type->value,
                'settlement' => ['id' => $l->settlement->id, 'number' => $l->settlement->number, 'status' => $l->settlement->status->value],
                'amount' => $l->amount,
            ]);

        return Inertia::render('admin/reports/show', [
            'report' => [
                'id' => $report->id,
                'status' => $report->status->value,
                'report_date' => $report->report_date->toDateString(),
                'period_from' => $report->period_from->toDateString(),
                'period_to' => $report->period_to->toDateString(),
                'currency' => $report->currency,
                'error' => $report->error_log,
                'generated_at' => $report->generated_at?->toIso8601String(),
                'email_sent_at' => $report->email_sent_at?->toIso8601String(),
                'fx_rate' => $report->fx_rate,
                'base_currency' => $report->base_currency,
                'summary' => $report->summary_data,
                ...$report->only(['sales_count', ...DailyReportTask::MONEY_FIELDS]),
                'files' => array_keys(array_filter([
                    'pdf' => $report->generated_pdf_path,
                    'xlsx' => $report->generated_xlsx_path,
                    'operations' => $report->generated_operations_path,
                ])),
            ],
            'merchant' => $report->merchant->only(['public_id', 'name', 'invoice_email']),
            'mid' => [
                'id' => $mid->id,
                'mid' => $mid->mid,
                'bank_provider' => $mid->bankProvider?->name,
                'gate_provider' => $mid->gateProvider?->name,
            ],
            'sources' => $report->sources->map(fn ($s) => [
                'provider' => $s->provider->name,
                'role' => $s->role->value,
                'rows' => $s->rows_count,
                'received_at' => $s->received_at->toIso8601String(),
                'bot_run_id' => $s->bot_run_id,
            ]),
            'missing' => Provider::query()->whereIn('id', $report->missingProviderIds())->pluck('name'),
            'operations' => MerchantOperation::query()
                ->where('merchant_mid_id', $mid->id)
                ->whereBetween('report_date', [$report->period_from->toDateString(), $report->period_to->toDateString()])
                ->selectRaw('role, operation_type, count(*) as total')
                ->groupBy('role', 'operation_type')
                ->get()
                ->map(fn ($r) => ['role' => $r->role instanceof ProviderType ? $r->role->value : $r->role, 'type' => $r->operation_type->value, 'count' => (int) $r->total]),
            'reserve' => ReserveLedgerEntry::query()->where('daily_report_task_id', $report->id)->orderBy('id')->get()
                ->map(fn (ReserveLedgerEntry $e) => [
                    'type' => $e->type->value,
                    'amount' => $e->amount,
                    'release_on' => $e->release_on?->toDateString(),
                    'note' => $e->note,
                    'created_at' => $e->created_at?->toIso8601String(),
                ]),
            'settlements' => $settlementLines,
            'lockReason' => $report->lockReason(),
        ]);
    }

    public function regenerate(DailyReportTask $report): RedirectResponse
    {
        if ($reason = $report->lockReason()) {
            throw ValidationException::withMessages(['report' => "This report is locked. {$reason}"]);
        }

        $report->update(['status' => ReportStatus::Pending, 'error_log' => null]);
        GenerateDailyReportJob::dispatch($report->id, false);
        AuditLogger::log('report.regenerated', $report);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Report queued for regeneration.']);

        return back();
    }

    public function resend(DailyReportTask $report): RedirectResponse
    {
        $email = $report->merchant->invoice_email;
        if ($report->status !== ReportStatus::Completed || ! $email) {
            throw ValidationException::withMessages(['report' => 'Only a completed report of a merchant with an invoice e-mail can be sent.']);
        }

        Mail::to($email)->queue(new DailyReportMail($report));
        $report->update(['is_email_sent' => true, 'email_sent_at' => now()]);
        AuditLogger::log('report.resent', $report, ['to' => $email]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Report sent to {$email}."]);

        return back();
    }

    /**
     * Deleting a report reverses its reserve; its operations stay, so the
     * report comes back on the next file or bot run.
     */
    public function destroy(DailyReportTask $report): RedirectResponse
    {
        if ($reason = $report->lockReason()) {
            throw ValidationException::withMessages(['report' => "This report is locked. {$reason}"]);
        }
        if ($settlement = $report->activeSettlement()) {
            throw ValidationException::withMessages(['report' => "Remove it from settlement {$settlement->number} first."]);
        }

        DB::transaction(function () use ($report) {
            $held = BigDecimal::of((string) ReserveLedgerEntry::query()
                ->where('daily_report_task_id', $report->id)
                ->whereIn('type', [ReserveEntryType::Hold, ReserveEntryType::Adjustment])
                ->sum('amount'));

            if (! $held->isZero()) {
                ReserveLedgerEntry::query()->create([
                    'merchant_id' => $report->merchant_id,
                    'merchant_mid_id' => $report->merchant_mid_id,
                    'daily_report_task_id' => $report->id,
                    'currency' => $report->currency,
                    'type' => ReserveEntryType::Adjustment,
                    'amount' => (string) $held->negated(),
                    'note' => 'Reversal: report '.$report->report_date->toDateString().' deleted',
                    'created_by' => auth()->id(),
                ]);
            }

            AuditLogger::log('report.deleted', $report, ['mid_id' => $report->merchant_mid_id, 'report_date' => $report->report_date->toDateString(), 'reserve_reversed' => (string) $held]);
            $report->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Report deleted and its reserve reversed.']);

        return to_route('admin.reports.index', ['month' => $report->report_date->format('Y-m')]);
    }

    public function download(DailyReportTask $report, string $file): StreamedResponse
    {
        $path = match ($file) {
            'pdf' => $report->generated_pdf_path,
            'xlsx' => $report->generated_xlsx_path,
            'operations' => $report->generated_operations_path,
            default => null,
        };
        $disk = Storage::disk(config('sterling.reports.disk'));
        abort_unless($path && $disk->exists($path), 404);

        return $disk->download($path);
    }

    /**
     * Manual upload of a provider file — same pipeline as the bots.
     */
    public function upload(Request $request, ReportIngestionService $ingestion): RedirectResponse
    {
        $data = $request->validate([
            'provider_id' => ['required', 'exists:providers,id'],
            'file' => ['required', 'file', 'max:102400'],
            'report_date' => ['nullable', 'date'],
        ]);

        $provider = Provider::query()->findOrFail($data['provider_id']);
        try {
            $result = $ingestion->ingest($provider, $request->file('file'), isset($data['report_date']) ? CarbonImmutable::parse($data['report_date']) : null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        AuditLogger::log('report.uploaded', $provider, ['file' => $result->storedPath, 'rows' => $result->rows]);

        $message = "{$result->rows} operation(s) imported into ".count($result->tasks).' report(s).';
        if ($result->unknownMids) {
            $message .= ' New MIDs for review: '.implode(', ', $result->unknownMids).'.';
        }
        Inertia::flash('toast', ['type' => $result->rows > 0 ? 'success' : 'warning', 'message' => $message]);

        return back();
    }

    /**
     * @param  list<int>  $taskIds  completed reports in view
     * @return list<array<string, mixed>>
     */
    private function totals(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $paid = SettlementLine::query()->toBase()
            ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
            ->where('settlements.status', SettlementStatus::Settled->value)
            ->where('settlement_lines.type', SettlementLineType::Report->value)
            ->whereIn('settlement_lines.daily_report_task_id', $taskIds)
            ->selectRaw('settlement_lines.currency, sum(settlement_lines.amount) as amount')
            ->groupBy('settlement_lines.currency')
            ->pluck('amount', 'currency');

        return DailyReportTask::query()->toBase()
            ->whereIn('id', $taskIds)
            ->selectRaw('currency, count(*) as reports, sum(turnover) as turnover, sum(net_payout) as payout, '
                .'sum(total_merchant_fee + conversion_fee) as our_fee, sum(net_profit) as net_profit, sum(reserve_amount) as reserve')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($r) => [
                'currency' => $r->currency,
                'reports' => (int) $r->reports,
                'turnover' => round((float) $r->turnover, 2),
                'payout' => round((float) $r->payout, 2),
                'paid_out' => round((float) ($paid[$r->currency] ?? 0), 2),
                'our_fee' => round((float) $r->our_fee, 2),
                'net_profit' => round((float) $r->net_profit, 2),
                'reserve' => round((float) $r->reserve, 2),
            ])
            ->all();
    }
}
