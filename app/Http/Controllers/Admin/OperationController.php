<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OperationResource;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Reports\Generation\Tariff;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class OperationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:128'],
            'provider' => ['nullable', 'integer'],
            'role' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'region' => ['nullable', 'in:eu,non_eu'],
            'currency' => ['nullable', 'string', 'size:3'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'unmatched' => ['nullable', 'boolean'],
        ]);

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
            'fees' => array_filter($operation->only(['eu_fee', 'non_eu_fee', 'ic_fee', 'ic_interchange', 'ic_scheme_fee', 'approve_fee', 'decline_fee', 'refund_fee']), fn ($v) => $v !== null),
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

        $amount = BigDecimal::of($op->amount)->abs();
        $merchantPercent = Tariff::merchant($op->merchant)->percentFor($op);
        $providerPercent = Tariff::provider($op->provider)->percentFor($op);

        return [
            'merchant_percent' => (string) $merchantPercent->strippedOfTrailingZeros(),
            'merchant_fee' => (string) $amount->multipliedBy($merchantPercent)->dividedBy(100, 2, RoundingMode::HalfUp),
            'provider_percent' => (string) $providerPercent->strippedOfTrailingZeros(),
            'provider_cost' => (string) $amount->multipliedBy($providerPercent)->dividedBy(100, 2, RoundingMode::HalfUp),
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
                $digits = preg_replace('/\D/', '', $search) ?? '';
                $q->where(fn (Builder $q) => $q
                    ->where('arn', $search)
                    ->orWhere('payment_id', $search)
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
