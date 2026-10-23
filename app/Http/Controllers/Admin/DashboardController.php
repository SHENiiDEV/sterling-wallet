<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MidStatus;
use App\Enums\Module;
use App\Enums\ReportStatus;
use App\Enums\SettlementStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentStatusResource;
use App\Models\DailyReportTask;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentStatus;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\SettlementLine;
use App\Models\User;
use App\Profit\ProfitQuery;
use App\Settlements\DueSettlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ProfitQuery $profit): Response
    {
        $user = $request->user();
        // A product-restricted partner gets its own overview, with no costs or profit.
        if ($user->productScope() !== null) {
            return $this->partnerOverview($request);
        }

        $canProfit = $user->canAccess(Module::Profit);
        $canDocuments = $user->canAccess(Module::Documents);

        $open = fn (Builder $q) => $q->whereHas('status', fn (Builder $q) => $q->where('is_final', false));

        [$from, $to] = ProfitController::period($request);
        [$prevFrom, $prevTo] = ProfitQuery::previous($from, $to);
        $product = ProfitController::product($request);
        $all = $profit;
        $profit = $profit->forProduct($product);

        return Inertia::render('admin/dashboard', [
            'profit' => $canProfit ? [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'baseCurrency' => config('sterling.base_currency'),
                'product' => $product?->value,
                'products' => $all->byProduct($from, $to),
                'summary' => $profit->summary($from, $to),
                'previous' => $profit->summary($prevFrom, $prevTo),
                'daily' => $profit->daily($from, $to),
                'byCurrency' => $profit->byCurrency($from, $to),
                'byPair' => $profit->byProviderPair($from, $to),
                'byMerchant' => $profit->byMerchant($from, $to, 8),
            ] : null,
            'operations' => $user->canAccess(Module::Reports) || $canProfit ? $profit->operations() : null,
            'documents' => $canDocuments,
            'stats' => [
                'documents_open' => Document::query()->where($open)->count(),
                'documents_overdue' => Document::query()->overdue()->count(),
                'documents_signed_30d' => Document::query()
                    ->whereHas('status', fn (Builder $q) => $q->where('is_final', true))
                    ->where('status_changed_at', '>=', now()->subDays(30))
                    ->count(),
                'staff' => User::query()->where('role', '!=', UserRole::Merchant)->where('is_active', true)->count(),
            ],
            'pipeline' => DocumentStatusResource::collection(
                DocumentStatus::query()->ordered()->withCount('documents')->get(),
            )->resolve(),
            'attention' => Document::query()
                ->with(['status', 'owner'])
                ->where($open)
                ->whereNotNull('due_date')
                ->orderBy('due_date')
                ->limit(6)
                ->get()
                ->map(fn (Document $document) => DocumentResource::make($document)->resolve()),
            'activity' => DocumentActivity::query()
                ->with(['user:id,name', 'toStatus', 'document:id,title'])
                ->whereHas('document')
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (DocumentActivity $activity) => [
                    'id' => $activity->id,
                    'type' => $activity->type->value,
                    'user' => $activity->user?->name,
                    'document' => ['id' => $activity->document->id, 'title' => $activity->document->title],
                    'to_status' => $activity->toStatus ? DocumentStatusResource::make($activity->toStatus)->resolve() : null,
                    'created_at' => $activity->created_at?->toIso8601String(),
                ]),
            'reportAlerts' => $this->reportAlerts($user->canAccess(Module::Settlements)),
        ]);
    }

    /**
     * What a C2B partner sees first: volume, what merchants pay us and what is
     * waiting to be paid out. Every query is already narrowed to the partner's
     * product; provider costs and profit are not part of it.
     */
    private function partnerOverview(Request $request): Response
    {
        [$from, $to] = ProfitController::period($request);
        $user = $request->user();
        $product = $user->productScope();

        $reports = fn () => DailyReportTask::query()->toBase()
            ->where('daily_report_tasks.status', ReportStatus::Completed)
            ->whereBetween('daily_report_tasks.report_date', [$from->toDateString(), $to->toDateString()]);

        $unsettled = DailyReportTask::query()->toBase()
            ->where('status', ReportStatus::Completed)
            ->whereNotIn('id', SettlementLine::query()->select('daily_report_task_id')
                ->where('type', 'report')->whereNotNull('daily_report_task_id')
                ->whereHas('settlement', fn (Builder $q) => $q->whereIn('status', SettlementStatus::active())))
            ->selectRaw('currency, count(*) as reports, sum(net_payout) as payout')
            ->groupBy('currency')->orderBy('currency')->get();

        return Inertia::render('admin/c2b/overview', [
            'product' => ['value' => $product->value, 'label' => $product->label()],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $reports()
                ->selectRaw('currency, count(*) as reports, sum(sales_count) as sales, sum(turnover) as turnover, sum(refunds_amount + chargebacks_amount) as refunds, sum(total_merchant_fee + conversion_fee) as fees, sum(net_payout) as payout')
                ->groupBy('currency')->orderBy('currency')->get()
                ->map(fn ($r) => ['currency' => $r->currency, 'reports' => (int) $r->reports, 'sales' => (int) $r->sales, 'turnover' => round((float) $r->turnover, 2), 'refunds' => round((float) $r->refunds, 2), 'fees' => round((float) $r->fees, 2), 'payout' => round((float) $r->payout, 2)]),
            'daily' => $reports()
                ->selectRaw('report_date, sum(turnover_base) as turnover')
                ->groupBy('report_date')->orderBy('report_date')->get()
                ->map(fn ($r) => ['date' => (string) $r->report_date, 'turnover' => round((float) $r->turnover, 2)]),
            'baseCurrency' => config('sterling.base_currency'),
            'merchants' => $reports()
                ->join('merchants', 'merchants.id', '=', 'daily_report_tasks.merchant_id')
                ->selectRaw('merchants.public_id, merchants.name, daily_report_tasks.currency, sum(daily_report_tasks.turnover) as turnover, sum(daily_report_tasks.total_merchant_fee + daily_report_tasks.conversion_fee) as fees')
                ->groupBy('merchants.public_id', 'merchants.name', 'daily_report_tasks.currency')
                ->orderByDesc('turnover')->limit(10)->get()
                ->map(fn ($r) => ['public_id' => $r->public_id, 'name' => $r->name, 'currency' => $r->currency, 'turnover' => round((float) $r->turnover, 2), 'fees' => round((float) $r->fees, 2)]),
            'unsettled' => $unsettled->map(fn ($r) => ['currency' => $r->currency, 'reports' => (int) $r->reports, 'payout' => round((float) $r->payout, 2)]),
            'recent' => MerchantOperation::query()->with('merchant:id,name')->latest('transaction_at')->limit(8)->get()
                ->map(fn (MerchantOperation $o) => ['id' => $o->id, 'reference' => $o->reference ?? $o->payment_id, 'merchant' => $o->merchant?->name, 'amount' => $o->amount, 'currency' => $o->currency, 'at' => $o->transaction_at?->toIso8601String()]),
            'reportAlerts' => $this->reportAlerts($user->canAccess(Module::Settlements)),
        ]);
    }

    /**
     * MIDs that arrived unknown in a provider file, and reports that can't
     * finish on their own.
     *
     * @return list<array{key: string, label: string, count: int, detail: string|null}>
     */
    private function reportAlerts(bool $withSettlements = false): array
    {
        $alerts = [];

        // Reports that are due for payout today by the merchants' T+N, not yet in any settlement.
        if ($withSettlements) {
            $due = app(DueSettlements::class)->merchants();
            if ($due->isNotEmpty()) {
                $alerts[] = [
                    'key' => 'settlements_due',
                    'label' => 'merchant(s) have reports due for settlement',
                    'count' => $due->count(),
                    'detail' => $due->take(5)->map(fn (array $row) => $row['merchant']->name)->implode(', ').($due->count() > 5 ? ', …' : ''),
                ];
            }
        }

        $review = MerchantMid::query()->where('status', MidStatus::Review)->pluck('mid');
        if ($review->isNotEmpty()) {
            $alerts[] = [
                'key' => 'review_mids',
                'label' => 'MID(s) waiting for review — their reports are not calculated',
                'count' => $review->count(),
                'detail' => $review->take(5)->implode(', ').($review->count() > 5 ? ', …' : ''),
            ];
        }

        foreach ([ReportStatus::Blocked, ReportStatus::Failed] as $status) {
            $tasks = DailyReportTask::query()->where('status', $status);
            $count = (clone $tasks)->count();
            if ($count > 0) {
                $alerts[] = [
                    'key' => $status->value,
                    'label' => strtolower($status->label()).' daily report(s)',
                    'count' => $count,
                    'detail' => (clone $tasks)->latest('report_date')->value('error_log'),
                ];
            }
        }

        return $alerts;
    }
}
