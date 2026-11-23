<?php

namespace App\Http\Controllers\Portal;

use App\Enums\OperationType;
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
use App\Reports\Generation\ClientTrafficReport;
use App\Reports\TerminalVolumes;
use App\Settlements\SettlementStatementPdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
            // A period statement (a week, a month, any range up to a year): the last report day.
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'format' => ['sometimes', 'in:pdf,xlsx'],
            'company' => ['nullable', 'integer'],
        ]);
        $date = CarbonImmutable::parse($data['date']);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : null;
        $isPeriod = $to !== null && $to->greaterThan($date);
        if ($isPeriod && $date->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'A period can be at most a year.']);
        }
        $xlsx = ($data['format'] ?? 'pdf') === 'xlsx';
        $companyId = isset($data['company']) ? (int) $data['company'] : null;
        $company = $companyId === null ? null : Company::query()->where('client_id', $client->id)->findOrFail($companyId);
        $name = ($isPeriod ? 'report_' : 'daily-report_').preg_replace('/[^A-Za-z0-9_-]/', '_', $client->invoiceName().($company ? '_'.$company->name : '')).'_'.$data['date'].($isPeriod ? '_'.$data['to'] : '');

        return response($xlsx ? $statement->xlsx($client, $date, $companyId, $to) : $statement->render($client, $date, $companyId, $to), 200, [
            'Content-Type' => $xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.($xlsx ? '.xlsx' : '.pdf').'"',
        ]);
    }

    /**
     * Traffic of the client over a period for its own login.
     */
    public function clientTraffic(Request $request, ClientTrafficReport $traffic): HttpResponse
    {
        $client = $request->user()->client;
        abort_unless($client instanceof Client, 404);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date'],
            'format' => ['sometimes', 'in:pdf,xlsx'],
            'company' => ['nullable', 'integer'],
        ]);
        $from = CarbonImmutable::parse($data['date']);
        $to = CarbonImmutable::parse($data['to']);
        if ($from->diffInDays($to) > 92) {
            throw ValidationException::withMessages(['to' => 'A traffic report covers at most three months.']);
        }
        $xlsx = ($data['format'] ?? 'pdf') === 'xlsx';
        $companyId = isset($data['company']) ? (int) $data['company'] : null;
        $company = $companyId === null ? null : Company::query()->where('client_id', $client->id)->findOrFail($companyId);
        $name = 'traffic_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $client->invoiceName().($company ? '_'.$company->name : '')).'_'.$data['date'].'_'.$data['to'];

        return response($xlsx ? $traffic->xlsx($client, $from, $to, $companyId) : $traffic->render($client, $from, $to, $companyId), 200, [
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

    /**
     * Refunds, chargebacks and Mastercard collaboration cases of the merchant's MIDs, with the ids to find or block
     * the payer by. They arrive with the acquirer's files, so a line shows once the acquirer has reported it.
     */
    public function refunds(Request $request): Response|StreamedResponse
    {
        [$merchants, $selected] = $this->scope($request);
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', 'in:refund,chargeback,collab'],
            'format' => ['sometimes', 'in:csv'],
        ]);

        $timezone = config('sterling.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $timezone) : $today;
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $timezone) : $to->subDays(29);
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['from' => 'A list covers at most a year.']);
        }

        $types = isset($data['type']) ? [$data['type']] : ['refund', 'chargeback', 'collab'];
        $query = MerchantOperation::query()
            ->with(['merchant:id,name', 'merchantMid:id,mid,bank_mid'])
            ->where('role', ProviderType::Bank)
            ->whereIn('merchant_id', $selected->pluck('id'))
            ->whereIn('operation_type', $types)
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('report_date')->orderByDesc('id');

        if (($data['format'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($query) {
                $out = fopen('php://output', 'wb');
                fputcsv($out, ['Report date', 'Type', 'Amount', 'Currency', 'Merchant', 'MID', 'Refund / case time (UTC)', 'Card', 'sp_id (original payment)', 'Original payment ID', 'Original payment time (UTC)', 'Refund ID', 'Case number', 'ARN', 'Reason'], escape: '');
                (clone $query)->reorder()->chunkById(500, function ($chunk) use ($out) {
                    foreach ($this->refundRows($chunk) as $row) {
                        fputcsv($out, [$row['report_date'], $row['type'], $row['amount'], $row['currency'], $row['merchant'], $row['mid'], $row['time'], $row['card'], $row['sp_id'], $row['original_payment_id'], $row['original_time'], $row['payment_id'], $row['reference'], $row['arn'], $row['reason']], escape: '');
                    }
                });
                fclose($out);
            }, 'refunds_chargebacks_'.$from->toDateString().'_'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $totals = (clone $query)->toBase()->reorder()
            ->selectRaw('operation_type, currency, count(*) as total, sum(abs(amount)) as amount')
            ->groupBy('operation_type', 'currency')->get()
            ->map(fn ($r) => ['type' => $r->operation_type, 'currency' => $r->currency, 'count' => (int) $r->total, 'amount' => (string) round((float) $r->amount, 2)])->values();

        $page = $query->paginate(50)->withQueryString();
        $rows = collect($this->refundRows($page->getCollection()))->values();

        return Inertia::render('portal/refunds', [
            ...$this->shared($request, $merchants, $selected),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'type' => $data['type'] ?? null,
            'totals' => $totals,
            'rows' => $page->setCollection($rows),
        ]);
    }

    /**
     * What the merchant needs to find a refund or chargeback in its own system: the payment it is about and the ids
     * the acquirer and the gateway use. The payment is the original sale: for a chargeback the one it was found on,
     * for a refund the sale of the same card and amount before it. `sp_id` is the gateway's id (cpi_…) of that payment.
     *
     * @param  iterable<int, MerchantOperation>  $operations
     * @return list<array<string, mixed>>
     */
    private function refundRows(iterable $operations): array
    {
        $operations = collect($operations);
        $sales = [];
        foreach ($operations as $o) {
            $sales[$o->id] = ($id = $o->raw['original_operation_id'] ?? null)
                ? MerchantOperation::query()->find($id)
                : ($o->operation_type === OperationType::Refund ? $this->saleOfRefund($o) : null);
        }

        // The gateway side of the pairs: of the line itself and of the original sale.
        $pairIds = $operations->map(fn (MerchantOperation $o) => $o->matched_operation_id)->merge(collect($sales)->map(fn (?MerchantOperation $s) => $s?->matched_operation_id))->filter()->unique()->all();
        $pairs = MerchantOperation::query()->whereIn('id', $pairIds)->get()->keyBy('id');

        return $operations->map(function (MerchantOperation $o) use ($sales, $pairs) {
            $sale = $sales[$o->id] ?? null;
            $own = (string) $o->payment_id;
            $reference = str_contains($own, ':') ? substr($own, strpos($own, ':') + 1) : null;

            return [
                'id' => $o->id,
                'report_date' => $o->report_date->toDateString(),
                'type' => $o->operation_type->value,
                'amount' => (string) round(abs((float) $o->getRawOriginal('amount')), 2),
                'currency' => $o->currency,
                'merchant' => $o->merchant?->name,
                'mid' => $o->merchantMid?->terminalNumber(),
                'time' => $o->transaction_at?->toIso8601String(),
                'card' => $o->card_bin && $o->card_last4 ? $o->card_bin.'••'.$o->card_last4 : ($o->card_mask ?? ''),
                // The line's own id (a refund's, or a chargeback's case number) and the payment it is about.
                'payment_id' => $reference === null ? $own : '',
                'reference' => $reference ?? '',
                'original_payment_id' => (string) ($sale?->payment_id ?? ''),
                'original_time' => $sale?->transaction_at?->toIso8601String(),
                'sp_id' => (string) ($sale?->sp_id ?? $pairs->get($sale?->matched_operation_id)?->payment_id ?? $o->sp_id ?? $pairs->get($o->matched_operation_id)?->payment_id ?? ''),
                'arn' => (string) ($o->arn ?? ''),
                'reason' => $o->operation_type->value === 'chargeback' ? (string) ($o->resolution ?? '') : '',
            ];
        })->all();
    }

    /**
     * The sale a refund gives money back for: the latest one of the same MID, card and amount before it.
     */
    private function saleOfRefund(MerchantOperation $refund): ?MerchantOperation
    {
        if ($refund->card_bin === null || $refund->card_last4 === null) {
            return null;
        }

        return MerchantOperation::query()
            ->where('merchant_mid_id', $refund->merchant_mid_id)->where('role', ProviderType::Bank)->where('operation_type', OperationType::Sale)
            ->where('card_bin', $refund->card_bin)->where('card_last4', $refund->card_last4)
            ->where('amount', abs((float) $refund->getRawOriginal('amount')))
            ->when($refund->transaction_at, fn ($q, $at) => $q->where('transaction_at', '<=', $at))
            ->orderByDesc('transaction_at')->orderByDesc('id')->first();
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
            'mids' => $m->mids->map(fn ($mid) => ['mid' => $mid->terminalNumber(), 'currency' => $mid->currency->value, 'status' => $mid->status->label()])->values(),
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
            ->with(['merchant:id,name,company_id,website', 'merchant.company:id,name', 'merchantMid:id,mid,bank_mid'])
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
            'mid' => $r->merchantMid->terminalNumber(),
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
