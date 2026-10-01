<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ReportStatus;
use App\Enums\SettlementStatus;
use App\Http\Controllers\Controller;
use App\Merchants\MerchantOverview;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Settlements\SettlementStatementPdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The merchant portal: a company's users see the daily reports and
 * settlements of its merchants — their own numbers only.
 */
class PortalController extends Controller
{
    public function dashboard(Request $request, MerchantOverview $overview): Response
    {
        [$merchants, $selected] = $this->scope($request);

        return Inertia::render('portal/dashboard', [
            ...$this->shared($request, $merchants, $selected),
            'overview' => $overview->for($selected),
            'recentReports' => $this->reportsQuery($selected)->limit(8)->get()->map(fn (DailyReportTask $r) => $this->report($r)),
            'recentSettlements' => $this->settlementsQuery($selected)->limit(5)->get()->map(fn (Settlement $s) => $this->settlement($s)),
        ]);
    }

    public function reports(Request $request): Response
    {
        [$merchants, $selected] = $this->scope($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? (string) $request->query('month') : null;

        return Inertia::render('portal/reports', [
            ...$this->shared($request, $merchants, $selected),
            'month' => $month,
            'reports' => $this->reportsQuery($selected)
                ->when($month, fn ($q, $m) => $q->where('report_date', 'like', "{$m}-%"))
                ->paginate(31)
                ->withQueryString()
                ->through(fn (DailyReportTask $r) => $this->report($r)),
        ]);
    }

    public function settlements(Request $request): Response
    {
        [$merchants, $selected] = $this->scope($request);

        return Inertia::render('portal/settlements', [
            ...$this->shared($request, $merchants, $selected),
            'settlements' => $this->settlementsQuery($selected)
                ->paginate(25)
                ->withQueryString()
                ->through(fn (Settlement $s) => $this->settlement($s)),
        ]);
    }

    public function downloadReport(Request $request, DailyReportTask $report, string $file): StreamedResponse
    {
        abort_unless($report->status === ReportStatus::Completed && $this->owns($request, $report->merchant), 404);

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

    public function settlementPdf(Request $request, Settlement $settlement, SettlementStatementPdf $pdf): HttpResponse
    {
        abort_unless(in_array($settlement->status, [SettlementStatus::Approved, SettlementStatus::Settled], true) && $this->owns($request, $settlement->merchant), 404);

        return response($pdf->render($settlement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$settlement->number.'.pdf"',
        ]);
    }

    /**
     * The company's merchants, and the ones selected by ?merchant=public_id.
     *
     * @return array{0: Collection<int, Merchant>, 1: Collection<int, Merchant>}
     */
    private function scope(Request $request): array
    {
        $merchants = Merchant::query()->where('company_id', $request->user()->company_id)->orderBy('name')->get();
        $selected = $request->filled('merchant')
            ? $merchants->where('public_id', $request->query('merchant'))->values()
            : $merchants;

        return [$merchants, $selected->isEmpty() ? $merchants : $selected];
    }

    /**
     * @param  Collection<int, Merchant>  $merchants
     * @param  Collection<int, Merchant>  $selected
     * @return array<string, mixed>
     */
    private function shared(Request $request, Collection $merchants, Collection $selected): array
    {
        return [
            'company' => Company::query()->whereKey($request->user()->company_id)->value('name'),
            'merchants' => $merchants->map(fn (Merchant $m) => ['public_id' => $m->public_id, 'name' => $m->name])->values(),
            'merchant' => $selected->count() === 1 && $merchants->count() > 1 ? $selected->first()->public_id : null,
        ];
    }

    private function owns(Request $request, ?Merchant $merchant): bool
    {
        return $merchant !== null && $merchant->company_id !== null && $merchant->company_id === $request->user()->company_id;
    }

    /**
     * @param  Collection<int, Merchant>  $merchants
     */
    private function reportsQuery(Collection $merchants)
    {
        return DailyReportTask::query()
            ->with(['merchant:id,name', 'merchantMid:id,mid'])
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->where('status', ReportStatus::Completed)
            ->orderByDesc('report_date')->orderBy('merchant_mid_id');
    }

    /**
     * Drafts are internal; the merchant sees approved and paid settlements.
     *
     * @param  Collection<int, Merchant>  $merchants
     */
    private function settlementsQuery(Collection $merchants)
    {
        return Settlement::query()
            ->with(['merchant:id,name', 'wallet:id,currency,network'])
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->whereIn('status', [SettlementStatus::Approved, SettlementStatus::Settled])
            ->latest('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function report(DailyReportTask $r): array
    {
        return [
            'id' => $r->id,
            'report_date' => $r->report_date->toDateString(),
            'period_from' => $r->period_from->toDateString(),
            'period_to' => $r->period_to->toDateString(),
            'merchant' => $r->merchant->name,
            'mid' => $r->merchantMid->mid,
            'currency' => $r->currency,
            'sales_count' => $r->sales_count,
            'turnover' => $r->turnover,
            'refunds' => (string) ((float) $r->refunds_amount + (float) $r->chargebacks_amount),
            'fees' => (string) round((float) $r->total_merchant_fee + (float) $r->conversion_fee, 2),
            'reserve' => $r->reserve_amount,
            'net_payout' => $r->net_payout,
            'files' => [
                'pdf' => $r->generated_pdf_path !== null,
                'xlsx' => $r->generated_xlsx_path !== null,
                'operations' => $r->generated_operations_path !== null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settlement(Settlement $s): array
    {
        return [
            'id' => $s->id,
            'number' => $s->number,
            'merchant' => $s->merchant->name,
            'status' => $s->status->value,
            'status_label' => $s->status === SettlementStatus::Settled ? 'Paid' : 'Approved',
            'total_payout' => $s->total_payout,
            'payout_currency' => $s->payout_currency,
            'wallet' => $s->wallet ? trim($s->wallet->currency.' '.$s->wallet->network) : null,
            'approved_at' => $s->approved_at?->toDateString(),
            'settled_at' => $s->settled_at?->toDateString(),
            'tx_hash' => $s->tx_hash,
            'explorer_url' => SettlementStatementPdf::explorerUrl($s->wallet?->network, $s->tx_hash),
        ];
    }
}
