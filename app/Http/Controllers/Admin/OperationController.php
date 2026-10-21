<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationRequest;
use App\Http\Resources\OperationResource;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Reports\Generation\Tariff;
use App\Reports\Ingestion\ReportIngestionService;
use App\Services\AuditLogger;
use App\Support\ProductScope;
use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    private function filterInput(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:512'],
            'provider' => ['nullable', 'integer'],
            'role' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'region' => ['nullable', 'in:eu,non_eu'],
            'currency' => ['nullable', 'string', 'size:3'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'unmatched' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * The current selection (same filters as the list) as a CSV file.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($this->filterInput($request))
            ->with(['provider:id,name', 'merchant:id,name'])
            ->orderBy('report_date')->orderBy('transaction_at')->orderBy('id');

        $columns = ['Report date', 'Time (UTC)', 'Provider', 'Role', 'Type', 'Merchant', 'MID', 'Payment ID', 'Reference', 'ARN', 'RRN', 'Approval code', 'Card BIN', 'Card last 4', 'Scheme', 'Region', 'Issuer country', 'Amount', 'Currency', 'Customer', 'Customer e-mail', 'Customer account', 'Pair payment ID'];

        AuditLogger::log('operations.exported', null, ['filters' => $request->only(['search', 'provider', 'role', 'type', 'from', 'to'])]);

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns, escape: '');

            // Bounded: a selection that large should be narrowed first.
            $written = 0;
            foreach ($query->lazy(2000) as $op) {
                if (++$written > 200000) {
                    break;
                }

                fputcsv($out, [
                    $op->report_date?->toDateString(), $op->transaction_at?->utc()->format('Y-m-d H:i:s'), $op->provider?->name, $op->role->value,
                    $op->operation_type->value, $op->merchant?->name, $op->mid, $op->payment_id, $op->reference, $op->getAttribute('arn'),
                    $op->getAttribute('rrn'), $op->getAttribute('approval_code'), $op->card_bin, $op->card_last4, $op->ips, $op->region,
                    $op->getAttribute('issuer_country'), $op->amount, $op->currency, $op->customerName(), $op->customer_email,
                    $op->customerAccount(), $op->sp_id,
                ], escape: '');
            }

            fclose($out);
        }, 'operations_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function index(Request $request): Response
    {
        $filters = $this->filterInput($request);

        $query = $this->filtered($filters);

        // Totals come from SQL, never from loading the selection into memory.
        $totals = (clone $query)
            ->reorder()
            ->selectRaw('currency, operation_type, count(*) as operations, sum(amount) as amount')
            ->groupBy('currency', 'operation_type')
            ->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'operation_type' => $row->operation_type instanceof OperationType ? $row->operation_type->value : $row->operation_type,
                'operations' => (int) $row->operations,
                'amount' => number_format((float) $row->amount, 2, '.', ''),
            ]);

        $operations = $query
            ->with(['provider:id,name', 'merchant:id,public_id,name'])
            ->orderByDesc('report_date')
            ->orderByDesc('transaction_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (MerchantOperation $op) => OperationResource::make($op)->resolve());

        return Inertia::render('admin/operations/index', [
            'operations' => $operations,
            'totals' => $totals,
            'filters' => (object) Arr::only($filters, array_keys($filters)),
            'providers' => Provider::query()->whereIn('type', [ProviderType::Bank, ProviderType::Gate])->orderBy('name')->get(['id', 'name']),
            'roles' => array_values(array_filter(ProviderType::options(), fn ($o) => $o['value'] !== ProviderType::Crypto->value)),
            'types' => OperationType::options(),
            'currencies' => Currency::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/operations/form', [
            'operation' => null,
            ...$this->formOptions(),
        ]);
    }

    public function edit(MerchantOperation $operation): Response
    {
        $values = [];
        foreach (array_keys((new OperationRequest)->rules()) as $field) {
            if (! in_array($field, ['reason', 'customer_name', 'customer_account', 'merchant_mid_id', 'report_date', 'transaction_at', 'processing_at'], true)) {
                $values[$field] = $operation->getAttribute($field);
            }
        }
        $values = array_map(fn ($v) => $v instanceof BackedEnum ? $v->value : ($v === null ? '' : (string) $v), $values);

        return Inertia::render('admin/operations/form', [
            'operation' => [
                ...$values,
                'id' => $operation->id,
                'merchant_mid_id' => (string) $operation->merchant_mid_id,
                'report_date' => $operation->report_date?->toDateString() ?? '',
                'transaction_at' => $operation->transaction_at?->utc()->format('Y-m-d\\TH:i') ?? '',
                'processing_at' => $operation->processing_at?->utc()->format('Y-m-d\\TH:i') ?? '',
                'customer_name' => (string) ($operation->raw['remitterName'] ?? ''),
                'customer_account' => (string) ($operation->raw['remitterAccount'] ?? ''),
                'from_file' => ! ($operation->raw['manual'] ?? false),
            ],
            ...$this->formOptions(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'mids' => MerchantMid::query()
                ->with(['merchant:id,name', 'bankProvider:id,name', 'gateProvider:id,name'])
                ->orderBy('mid')
                ->get()
                ->map(fn (MerchantMid $m) => [
                    'id' => $m->id,
                    'mid' => $m->mid,
                    'currency' => $m->currency->value,
                    'merchant' => $m->merchant?->name,
                    'providers' => array_values(array_filter([
                        $m->bankProvider ? ['id' => $m->bankProvider->id, 'name' => $m->bankProvider->name, 'role' => 'bank'] : null,
                        $m->gateProvider ? ['id' => $m->gateProvider->id, 'name' => $m->gateProvider->name, 'role' => 'gate'] : null,
                    ])),
                ]),
            'types' => OperationType::options(),
            'currencies' => Currency::options(),
        ];
    }

    public function store(OperationRequest $request, ReportIngestionService $ingestion): RedirectResponse
    {
        $data = $request->validated();
        $mid = MerchantMid::query()->findOrFail($data['merchant_mid_id']);
        $provider = Provider::query()->findOrFail($data['provider_id']);
        $reason = $data['reason'];
        $payer = array_filter(['remitterName' => $data['customer_name'] ?? null, 'remitterAccount' => $data['customer_account'] ?? null]);
        unset($data['reason'], $data['customer_name'], $data['customer_account']);

        $operation = MerchantOperation::query()->create([
            ...array_filter($data, fn ($v) => $v !== null && $v !== ''),
            'merchant_id' => $mid->merchant_id,
            'role' => $provider->type,
            'mid' => $mid->mid,
            'raw' => [...$payer, 'manual' => true, 'added_by' => $request->user()->name, 'reason' => $reason, 'added_at' => now()->toIso8601String()],
        ]);
        $task = $ingestion->ensureReport($provider, $mid, CarbonImmutable::parse($operation->report_date));
        AuditLogger::log('operation.created', $operation, ['reason' => $reason, 'payment_id' => $operation->payment_id, 'amount' => $operation->amount, 'currency' => $operation->currency]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Operation added.'.($task->status === ReportStatus::Completed ? ' The report for that day is already built: open it in Reports and press Regenerate.' : ' It goes into that day\'s report when the day is over.')]);

        return to_route('admin.operations.show', $operation);
    }

    /**
     * Changing an operation needs a reason; the audit log keeps what changed.
     * A row that came from a provider file is overwritten again if that file is uploaded again.
     */
    public function update(OperationRequest $request, MerchantOperation $operation, ReportIngestionService $ingestion): RedirectResponse
    {
        $data = $request->validated();
        $mid = MerchantMid::query()->findOrFail($data['merchant_mid_id']);
        $provider = Provider::query()->findOrFail($data['provider_id']);
        $reason = $data['reason'];
        $payer = ['remitterName' => $data['customer_name'] ?? null, 'remitterAccount' => $data['customer_account'] ?? null];
        unset($data['reason'], $data['customer_name'], $data['customer_account']);

        $oldReport = $operation->report_date;
        $before = $operation->only(array_keys($data));

        $raw = $operation->raw ?? [];
        foreach ($payer as $key => $value) {
            $value === null || $value === '' ? Arr::forget($raw, $key) : $raw[$key] = $value;
        }
        $raw['edits'] = [...($raw['edits'] ?? []), ['by' => $request->user()->name, 'at' => now()->toIso8601String(), 'reason' => $reason]];

        $operation->update([
            ...array_map(fn ($v) => $v === '' ? null : $v, $data),
            'merchant_id' => $mid->merchant_id,
            'role' => $provider->type,
            'mid' => $mid->mid,
            'raw' => $raw,
        ]);

        $changes = [];
        foreach ($before as $field => $old) {
            $new = $operation->getAttribute($field);
            $old = $old instanceof BackedEnum ? $old->value : $old;
            $new = $new instanceof BackedEnum ? $new->value : $new;
            if ((string) ($old instanceof DateTimeInterface ? $old->format('c') : $old) !== (string) ($new instanceof DateTimeInterface ? $new->format('c') : $new)) {
                $changes[$field] = ['from' => $old instanceof DateTimeInterface ? $old->format('c') : $old, 'to' => $new instanceof DateTimeInterface ? $new->format('c') : $new];
            }
        }
        AuditLogger::log('operation.updated', $operation, ['reason' => $reason, 'changes' => $changes]);

        $task = $ingestion->ensureReport($provider, $mid, CarbonImmutable::parse($operation->report_date));
        $stale = $task->status === ReportStatus::Completed || ($oldReport !== null && $oldReport->toDateString() !== $operation->report_date->toDateString());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Operation saved.'.($stale ? ' A report that is already built does not change by itself: regenerate it in Reports.' : '')]);

        return to_route('admin.operations.show', $operation);
    }

    /**
     * Deleting needs the payment ID (or the word DELETE) typed back and a reason.
     */
    public function destroy(Request $request, MerchantOperation $operation): RedirectResponse
    {
        $data = $request->validate([
            'confirm' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $expected = (string) ($operation->payment_id ?: 'DELETE');
        if (trim($data['confirm']) !== $expected) {
            throw ValidationException::withMessages(['confirm' => "Type \"{$expected}\" to confirm."]);
        }

        AuditLogger::log('operation.deleted', $operation, [
            'reason' => $data['reason'],
            'snapshot' => $operation->only(['provider_id', 'merchant_mid_id', 'payment_id', 'trn_type', 'operation_type', 'amount', 'currency', 'report_date', 'transaction_at']),
        ]);
        $note = $this->staleReportNote($operation);
        $operation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Operation deleted.'.$note]);

        return to_route('admin.operations.index');
    }

    /**
     * A report already built for that day does not change by itself.
     */
    private function staleReportNote(MerchantOperation $operation): string
    {
        $built = $operation->merchant_mid_id && $operation->report_date && DailyReportTask::query()
            ->where('merchant_mid_id', $operation->merchant_mid_id)
            ->where('period_from', '<=', $operation->report_date->toDateString())
            ->where('period_to', '>=', $operation->report_date->toDateString())
            ->where('status', ReportStatus::Completed)
            ->exists();

        return $built ? ' The daily report for that day is already built: regenerate it in Reports.' : '';
    }

    public function show(MerchantOperation $operation): Response
    {
        $operation->load(['provider', 'merchant', 'merchantMid.bankProvider', 'merchantMid.gateProvider', 'matchedOperation.provider']);

        $report = $operation->merchant_mid_id && $operation->report_date
            ? DailyReportTask::query()
                ->where('merchant_mid_id', $operation->merchant_mid_id)
                ->where('period_from', '<=', $operation->report_date->toDateString())
                ->where('period_to', '>=', $operation->report_date->toDateString())
                ->first(['id', 'report_date', 'status'])
            : null;

        return Inertia::render('admin/operations/show', [
            'operation' => OperationResource::make($operation)->resolve(),
            'raw' => $operation->raw ?? (object) [],
            // Bank-side fees are our cost: not for a product-restricted partner.
            'fees' => ProductScope::current() !== null ? [] : array_filter($operation->only(['eu_fee', 'non_eu_fee', 'ic_fee', 'ic_interchange', 'ic_scheme_fee', 'approve_fee', 'decline_fee', 'refund_fee']), fn ($v) => $v !== null),
            'charges' => $this->charges($operation),
            'pair' => $operation->matchedOperation ? OperationResource::make($operation->matchedOperation)->resolve() : null,
            'mid' => $operation->merchantMid ? [
                'mid' => $operation->merchantMid->mid,
                'currency' => $operation->merchantMid->currency->value,
                'bank_provider' => $operation->merchantMid->bankProvider?->name,
                'gate_provider' => $operation->merchantMid->gateProvider?->name,
            ] : null,
            'report' => $report ? ['id' => $report->id, 'report_date' => $report->report_date->toDateString(), 'status' => $report->status->value] : null,
        ]);
    }

    /**
     * Percent charged on this operation: to the merchant, and by its provider.
     *
     * @return array{merchant_percent: string|null, merchant_fee: string|null, provider_percent: string|null, provider_cost: string|null}|null
     */
    private function charges(MerchantOperation $op): ?array
    {
        if ($op->operation_type !== OperationType::Sale || ! $op->merchant instanceof Merchant) {
            return null;
        }

        // The partner sees what the merchant pays, never what the provider charges us.
        $hideCost = ProductScope::current() !== null;
        $amount = BigDecimal::of($op->amount)->abs();
        $merchantPercent = ($op->merchantMid ? Tariff::forMid($op->merchantMid) : Tariff::merchant($op->merchant))->percentFor($op);
        $providerPercent = Tariff::provider($op->provider)->percentFor($op);

        return [
            'merchant_percent' => (string) $merchantPercent->strippedOfTrailingZeros(),
            'merchant_fee' => (string) $amount->multipliedBy($merchantPercent)->dividedBy(100, 2, RoundingMode::HalfUp),
            'provider_percent' => $hideCost ? null : (string) $providerPercent->strippedOfTrailingZeros(),
            'provider_cost' => $hideCost ? null : (string) $amount->multipliedBy($providerPercent)->dividedBy(100, 2, RoundingMode::HalfUp),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<MerchantOperation>
     */
    private function filtered(array $filters): Builder
    {
        return MerchantOperation::query()
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                // Card masks, one or several: "535765%2604", "492182****1171" (BIN, wildcard, last 4).
                if (preg_match_all('/(?<!\d)(\d{6})\d{0,2}\s*[%*xX.…•]+\s*(\d{4})(?!\d)/u', $search, $masks, PREG_SET_ORDER) > 0) {
                    $q->where(function (Builder $q) use ($masks) {
                        foreach ($masks as [, $bin, $last4]) {
                            $q->orWhere(fn (Builder $q) => $q->where('card_bin', $bin)->where('card_last4', $last4));
                        }
                    });

                    return;
                }

                $digits = preg_replace('/\D/', '', $search) ?? '';
                $q->where(fn (Builder $q) => $q
                    ->where('arn', $search)
                    ->orWhere('payment_id', $search)
                    ->orWhere('reference', $search)
                    ->orWhere('sp_id', $search)
                    ->orWhere('mid', $search)
                    ->orWhere('rrn', $search)
                    ->orWhere('customer_email', strtolower($search))
                    ->when(strlen($digits) === 6 && $digits === $search, fn (Builder $q) => $q->orWhere('card_bin', $digits))
                    ->when(strlen($digits) === 4 && $digits === $search, fn (Builder $q) => $q->orWhere('card_last4', $digits))
                    // "411111…1111" / "411111 1111": BIN and last 4 together.
                    ->when(strlen($digits) === 10 && $digits !== $search, fn (Builder $q) => $q->orWhere(
                        fn (Builder $q) => $q->where('card_bin', substr($digits, 0, 6))->where('card_last4', substr($digits, -4)),
                    )));
            })
            ->when($filters['provider'] ?? null, fn (Builder $q, int $id) => $q->where('provider_id', $id))
            ->when($filters['role'] ?? null, fn (Builder $q, string $role) => $q->where('role', $role))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('operation_type', $type))
            ->when($filters['region'] ?? null, fn (Builder $q, string $region) => $q->where('region', $region))
            ->when($filters['currency'] ?? null, fn (Builder $q, string $currency) => $q->where('currency', strtoupper($currency)))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('report_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('report_date', '<=', $to))
            ->when($filters['unmatched'] ?? false, fn (Builder $q) => $q
                ->whereNull('matched_operation_id')
                ->where('operation_type', '!=', OperationType::Decline)
                ->whereHas('merchantMid', fn (Builder $q) => $q->whereNotNull('bank_provider_id')->whereNotNull('gate_provider_id')));
    }
}
