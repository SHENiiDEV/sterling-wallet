<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Enums\SettlementStatus;
use App\Http\Controllers\Controller;
use App\Merchants\MerchantOverview;
use App\Merchants\PortalAnalytics;
use App\Models\Client;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\MerchantOperation;
use App\Models\MerchantTariff;
use App\Models\Settlement;
use App\Reports\Generation\ClientDailyStatement;
use App\Reports\Generation\ClientDays;
use App\Reports\TerminalVolumes;
use App\Settlements\SettlementStatementPdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The merchant portal. A login belongs to a company and sees it and every
 * company below it (a client group such as APS → its companies → their
 * merchants): sales and payout charts, our prices, daily reports and
 * settlements. Never our costs or profit.
 */
class PortalController extends Controller
{
    /** @var array<int, list<int>> per user id, for this request */
    private array $companyIds = [];

    public function dashboard(Request $request, MerchantOverview $overview, PortalAnalytics $analytics): Response
    {
        [$merchants, $selected] = $this->scope($request);
        $shared = $this->shared($request, $merchants, $selected);

        return Inertia::render('portal/dashboard', [
            ...$shared,
            'overview' => $overview->for($selected),
            'analytics' => $analytics->for($selected),
            // Inside one company: each merchant with its MIDs and the prices we charge.
            'pricing' => $shared['portal']['company'] !== null || count($shared['portal']['companies']) <= 1
                ? $selected->map(fn (Merchant $m) => $this->pricing($m))->values()
                : [],
            'recentReports' => $this->reportsQuery($selected)->limit(8)->get()->map(fn (DailyReportTask $r) => $this->report($r)),
            'recentSettlements' => $this->settlementsQuery($selected, $request->user()->client_id)->limit(5)->get()->map(fn (Settlement $s) => $this->settlement($s)),
        ]);
    }

    public function reports(Request $request, ClientDays $clientDays): Response
    {
        [$merchants, $selected] = $this->scope($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? (string) $request->query('month') : null;
        $client = $request->user()->client;

        return Inertia::render('portal/reports', [
            ...$this->shared($request, $merchants, $selected),
            'month' => $month,
            // A login of a whole client also gets the consolidated daily statements.
            'clientDays' => $client === null ? null : [
                'name' => $client->invoiceName(),
                'month' => $clientMonth = $month ?? $clientDays->latestMonth($client) ?? now()->format('Y-m'),
                'days' => $clientDays->forMonth($client, $clientMonth),
            ],
            'reports' => $this->reportsQuery($selected)
                ->when($month, fn ($q, $m) => $q->where('report_date', 'like', "{$m}-%"))
                ->paginate(31)
                ->withQueryString()
                ->through(fn (DailyReportTask $r) => $this->report($r)),
        ]);
    }

    /**
     * The consolidated daily report of the client: all its companies, or one.
     */
    public function clientReport(Request $request, ClientDailyStatement $statement): HttpResponse
    {
        $client = $request->user()->client;
        abort_unless($client instanceof Client, 404);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'format' => ['sometimes', 'in:pdf,xlsx'],
            'company' => ['nullable', 'integer'],
        ]);
        $date = CarbonImmutable::parse($data['date']);
        $xlsx = ($data['format'] ?? 'pdf') === 'xlsx';
        $companyId = isset($data['company']) ? (int) $data['company'] : null;
        $company = $companyId === null ? null : Company::query()->where('client_id', $client->id)->findOrFail($companyId);
        $name = 'daily-report_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $client->invoiceName().($company ? '_'.$company->name : '')).'_'.$data['date'];

        return response($xlsx ? $statement->xlsx($client, $date, $companyId) : $statement->render($client, $date, $companyId), 200, [
            'Content-Type' => $xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.($xlsx ? '.xlsx' : '.pdf').'"',
        ]);
    }

    public function settlements(Request $request): Response
    {
        [$merchants, $selected] = $this->scope($request);

        return Inertia::render('portal/settlements', [
            ...$this->shared($request, $merchants, $selected),
            'settlements' => $this->settlementsQuery($selected, $request->user()->client_id)
                ->paginate(25)
                ->withQueryString()
                ->through(fn (Settlement $s) => $this->settlement($s)),
        ]);
    }

    /**
     * How full each terminal of the client's merchants was, day by day, from
     * the daily gateway reports. Corefy's file for a day arrives the next
     * day, so the latest complete day is yesterday.
     */
    public function volumes(Request $request, TerminalVolumes $volumes): Response
    {
        [$merchants, $selected] = $this->scope($request);
        $data = $request->validate([
            'day' => ['nullable', 'date_format:Y-m-d'],
            'mid' => ['nullable', 'integer'],
        ]);

        $timezone = config('sterling.timezone');
        $yesterday = CarbonImmutable::now($timezone)->startOfDay()->subDay();
        $day = isset($data['day']) ? min(CarbonImmutable::createFromFormat('Y-m-d', $data['day'], $timezone)->startOfDay(), $yesterday) : $yesterday;
        $ids = $selected->pluck('id')->all();

        // The month so far, to show how much of the monthly limit is used.
        $month = $volumes->rows($day->startOfMonth(), $day, merchantIds: $ids)->keyBy('id');
        $rows = $volumes->rows($day, $day, merchantIds: $ids)->map(fn (array $r) => [
            'id' => $r['id'],
            'mid' => $r['mid'],
            'currency' => $r['currency'],
            'merchant' => $r['merchant']['name'],
            'volume' => $r['volume'],
            'approved' => $r['approved'],
            'declined' => $r['declined'],
            'norm' => $r['norm'],
            'norm_percent' => $r['norm_percent'],
            'shortfall' => $r['shortfall'],
            'month_volume' => $month[$r['id']]['volume'] ?? 0,
            'month_limit' => $month[$r['id']]['limit'] ?? null,
            'month_percent' => $month[$r['id']]['used_percent'] ?? null,
        ])->values();

        $selectedRow = isset($data['mid']) ? $rows->firstWhere('id', (int) $data['mid']) : null;

        return Inertia::render('portal/volumes', [
            ...$this->shared($request, $merchants, $selected),
            'day' => $day->toDateString(),
            'shortcuts' => array_map(fn (int $back) => [
                'date' => $yesterday->subDays($back)->toDateString(),
                'label' => $back === 0 ? 'Yesterday' : $yesterday->subDays($back)->format('D j M'),
            ], range(0, 6)),
            'dataThrough' => MerchantOperation::query()->toBase()->where('role', ProviderType::Gate->value)->whereIn('merchant_id', $ids)->max('report_date'),
            'rows' => $rows,
            'selected' => $selectedRow === null ? null : [
                'terminal' => $selectedRow,
                'days' => $volumes->series($selectedRow['id'], $day->subDays(13), $day),
            ],
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
        abort_unless(
            in_array($settlement->status, [SettlementStatus::Approved, SettlementStatus::Settled], true)
                && $this->owns($request, $settlement->merchant)
                && ($settlement->client_id === null || $settlement->client_id === $request->user()->client_id),
            404,
        );

        return response($pdf->render($settlement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$settlement->number.'.pdf"',
        ]);
    }

    /**
     * The companies the user may see (their company and every company
     * below it), the merchants in them, and the selection made with
     * ?company={id} and ?merchant={public_id}.
     *
     * @return array{0: Collection<int, Merchant>, 1: Collection<int, Merchant>}
     */
    private function scope(Request $request): array
    {
        $merchants = Merchant::query()
            ->with('company:id,name,parent_id')
            ->whereIn('company_id', $this->companyIds($request))
            ->orderBy('name')
            ->get();

        $selected = $merchants;
        if ($company = $this->selectedCompany($request)) {
            $ids = $company->descendantIdsWithSelf();
            $selected = $selected->whereIn('company_id', $ids)->values();
        }
        if ($request->filled('merchant')) {
            $one = $selected->where('public_id', $request->query('merchant'))->values();
            $selected = $one->isEmpty() ? $selected : $one;
        }

        return [$merchants, $selected];
    }

    /**
     * @return list<int>
     */
    private function companyIds(Request $request): array
    {
        $user = $request->user();

        if ($this->companyIds[$user->id] ?? null) {
            return $this->companyIds[$user->id];
        }

        // A login of a whole client sees all its companies (and the ones below them).
        $roots = $user->client_id !== null
            ? Company::query()->where('client_id', $user->client_id)->get()
            : Company::query()->whereKey($user->company_id)->get();

        return $this->companyIds[$user->id] = $roots->flatMap(fn (Company $c) => $c->descendantIdsWithSelf())->unique()->values()->all();
    }

    private function selectedCompany(Request $request): ?Company
    {
        $id = (int) $request->query('company');
        if ($id === 0 || $id === $request->user()->company_id || ! in_array($id, $this->companyIds($request), true)) {
            return null;
        }

        return Company::query()->find($id);
    }

    /**
     * @param  Collection<int, Merchant>  $merchants
     * @param  Collection<int, Merchant>  $selected
     * @return array<string, mixed>
     */
    private function shared(Request $request, Collection $merchants, Collection $selected): array
    {
        $company = $this->selectedCompany($request);
        // Companies that have merchants, for the switcher.
        $companies = $merchants->pluck('company')->unique('id')->sortBy('name')->values();
        $inCompany = $company ? $merchants->whereIn('company_id', $company->descendantIdsWithSelf()) : $merchants;

        return [
            'portal' => [
                'root' => $request->user()->client_id !== null
                    ? $request->user()->client?->invoiceName()
                    : Company::query()->whereKey($request->user()->company_id)->value('name'),
                'companies' => $companies->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])->all(),
                'company' => $company ? ['id' => $company->id, 'name' => $company->name] : null,
                'merchants' => $inCompany->map(fn (Merchant $m) => ['public_id' => $m->public_id, 'name' => $m->name])->values()->all(),
                'merchant' => $selected->count() === 1 && $inCompany->count() > 1 ? $selected->first()->public_id : null,
            ],
        ];
    }

    /**
     * What we charge the merchant, as the merchant sees it.
     *
     * @return array<string, mixed>
     */
    private function pricing(Merchant $m): array
    {
        $m->loadMissing('mids', 'tariffs');
        $rate = fn (?string $scheme, string $region) => $m->getAttribute("fee_{$scheme}_{$region}_percent") ?? $m->getAttribute("fee_acq_{$region}_percent");

        // Cards are offered when any scheme has a rate (or a fallback rate prices them).
        $cardOffered = collect(['fee_visa_eu_percent', 'fee_visa_non_eu_percent', 'fee_mastercard_eu_percent', 'fee_mastercard_non_eu_percent'])
            ->contains(fn (string $field) => $m->getAttribute($field) !== null)
            || (float) $m->fee_acq_eu_percent > 0 || (float) $m->fee_acq_non_eu_percent > 0;

        return [
            'public_id' => $m->public_id,
            'name' => $m->name,
            'company' => $m->company?->name,
            'card_offered' => $cardOffered,
            'status' => $m->status->label(),
            'website' => $m->website,
            'rates' => [
                ['Visa', $rate('visa', 'eu'), $rate('visa', 'non_eu')],
                ['Mastercard', $rate('mastercard', 'eu'), $rate('mastercard', 'non_eu')],
            ],
            'fixed' => [
                ['Approved transaction', $m->fee_success_fixed],
                ['Declined transaction', $m->fee_decline_fixed],
                ['Refund', $m->fee_refund_fixed],
                ['Chargeback', $m->fee_chargeback_fixed],
            ],
            'conversion_percent' => $m->fee_fiat_to_crypto_percent,
            // Every other product the merchant is offered, priced by its own tariff.
            'products' => $m->tariffs->filter(fn (MerchantTariff $t) => $t->transfer_percent !== null)->map(fn (MerchantTariff $t) => [
                'key' => $t->product->value,
                'label' => $t->product->label(),
                'lines' => [
                    ['Processing fee', $t->transfer_percent, 'percent'],
                    ['Successful transaction', $t->success_fixed, 'money'],
                    ['Refund', $t->refund_fixed, 'money'],
                    ['Conversion to USDC', $t->fiat_to_crypto_percent, 'percent'],
                ],
            ])->values()->all(),
            'reserve_percent' => $m->rolling_reserve_percent,
            'reserve_days' => $m->rolling_reserve_days,
            // Fixed fees are in the currency of the MID they ran on: the symbol follows from it.
            'currencies' => $m->mids->map(fn ($mid) => $mid->currency->value)->unique()->sort()->values(),
            'mids' => $m->mids->map(fn ($mid) => ['mid' => $mid->mid, 'currency' => $mid->currency->value, 'status' => $mid->status->label()])->values(),
        ];
    }

    private function owns(Request $request, ?Merchant $merchant): bool
    {
        return $merchant !== null && in_array($merchant->company_id, $this->companyIds($request), true);
    }

    /**
     * @param  Collection<int, Merchant>  $merchants
     */
    private function reportsQuery(Collection $merchants)
    {
        return DailyReportTask::query()
            ->with(['merchant:id,name,company_id,website', 'merchant.company:id,name', 'merchantMid:id,mid'])
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->where('status', ReportStatus::Completed)
            ->orderByDesc('report_date')->orderBy('merchant_mid_id');
    }

    /**
     * Drafts are internal; the merchant sees approved and paid settlements.
     *
     * A client-wide settlement covers several companies, so only the client's own logins see it.
     *
     * @param  Collection<int, Merchant>  $merchants
     */
    private function settlementsQuery(Collection $merchants, ?int $clientId)
    {
        return Settlement::query()
            ->with(['merchant:id,name,company_id', 'merchant.company:id,name', 'client:id,name,invoice_name', 'wallet:id,currency,network'])
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->where(fn ($q) => $q->whereNull('client_id')->when($clientId, fn ($q) => $q->orWhere('client_id', $clientId)))
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
            'website' => $r->merchant->website,
            'company' => $r->merchant->company?->name,
            'mid' => $r->merchantMid->mid,
            'currency' => $r->currency,
            'sales_count' => $r->sales_count,
            'turnover' => $r->turnover,
            'refunds' => (string) ((float) $r->refunds_amount + (float) $r->chargebacks_amount + (float) $r->collabs_amount),
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
            'company' => $s->client?->invoiceName() ?? $s->merchant->company?->name,
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
