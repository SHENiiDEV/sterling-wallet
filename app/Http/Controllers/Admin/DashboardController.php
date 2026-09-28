<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MidStatus;
use App\Enums\Module;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentStatusResource;
use App\Models\DailyReportTask;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentStatus;
use App\Models\MerchantMid;
use App\Models\User;
use App\Profit\ProfitQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ProfitQuery $profit): Response
    {
        $user = $request->user();
        $canProfit = $user->canAccess(Module::Profit);
        $canDocuments = $user->canAccess(Module::Documents);

        $open = fn (Builder $q) => $q->whereHas('status', fn (Builder $q) => $q->where('is_final', false));

        [$from, $to] = ProfitController::period($request);
        [$prevFrom, $prevTo] = ProfitQuery::previous($from, $to);

        return Inertia::render('admin/dashboard', [
            'profit' => $canProfit ? [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'baseCurrency' => config('sterling.base_currency'),
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
            'reportAlerts' => $this->reportAlerts(),
        ]);
    }

    /**
     * MIDs that arrived unknown in a provider file, and reports that can't
     * finish on their own.
     *
     * @return list<array{key: string, label: string, count: int, detail: string|null}>
     */
    private function reportAlerts(): array
    {
        $alerts = [];

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
