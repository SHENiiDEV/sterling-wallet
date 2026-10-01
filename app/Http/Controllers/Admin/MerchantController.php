<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AcquirerStatus;
use App\Enums\Currency;
use App\Enums\IntegrationStatus;
use App\Enums\MerchantStatus;
use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Enums\UserRole;
use App\Enums\WalletType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MerchantRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\MerchantResource;
use App\Merchants\MerchantOverview;
use App\Merchants\MerchantPurger;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\MerchantAcquirer;
use App\Models\MerchantCryptoWallet;
use App\Models\Provider;
use App\Models\Settlement;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class MerchantController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'company' => ['nullable', 'integer'],
        ]);

        $merchants = Merchant::query()
            ->with(['company:id,name', 'mids:id,merchant_id,mid,currency,status'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(
                fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('public_id', $search)
                    ->orWhereHas('mids', fn (Builder $q) => $q->where('mid', 'like', "%{$search}%")),
            ))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['company'] ?? null, fn (Builder $q, int $company) => $q->where('company_id', $company))
            ->when($filters['currency'] ?? null, fn (Builder $q, string $currency) => $q->whereHas('mids', fn (Builder $q) => $q->where('currency', $currency)))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Merchant $merchant) => MerchantResource::make($merchant)->resolve());

        return Inertia::render('admin/merchants/index', [
            'merchants' => $merchants,
            'statusCounts' => Merchant::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'filters' => (object) Arr::only($filters, ['search', 'status', 'currency', 'company']),
            'statuses' => MerchantStatus::options(),
            'currencies' => Currency::options(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/merchants/form', [
            'merchant' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(MerchantRequest $request): RedirectResponse
    {
        $merchant = Merchant::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Merchant created. Add its MIDs next.']);

        return to_route('admin.merchants.show', $merchant);
    }

    public function show(Request $request, Merchant $merchant): Response
    {
        $merchant->load([
            'company:id,name,parent_id',
            'cryptoProvider:id,name',
            'mids' => fn ($q) => $q->with(['bankProvider:id,name', 'gateProvider:id,name'])
                ->withSum('reserveEntries as reserve_balance', 'amount')
                ->orderBy('currency'),
        ]);

        return Inertia::render('admin/merchants/show', [
            'merchant' => MerchantResource::make($merchant)->resolve(),
            // Loaded only when the delete dialog opens.
            'deletion' => Inertia::optional(fn () => app(MerchantPurger::class)->summary($merchant)),
            'wallets' => $merchant->wallets()->orderBy('type')->get()->map(fn (MerchantCryptoWallet $wallet) => [
                'id' => $wallet->id,
                'type' => $wallet->type->value,
                'type_label' => $wallet->type->label(),
                'label' => $wallet->label,
                'currency' => $wallet->currency,
                'network' => $wallet->network,
                'address' => $wallet->address,
                'has_seed' => $wallet->seed_phrase !== null,
                'notes' => $wallet->notes,
                'is_active' => $wallet->is_active,
            ]),
            'acquirers' => $merchant->acquirers()->with('provider:id,name')->get()
                ->sortBy(fn (MerchantAcquirer $a) => $a->provider->name)->values()
                ->map(fn (MerchantAcquirer $a) => [
                    'id' => $a->id,
                    'provider_id' => $a->provider_id,
                    'provider' => $a->provider->name,
                    'status' => $a->status->value,
                    'status_label' => $a->status->label(),
                    'limit' => $a->limit,
                    'limit_currency' => $a->limit_currency,
                    'integration_status' => $a->integration_status->value,
                    'integration_label' => $a->integration_status->label(),
                    'psp' => $a->psp,
                    'notes' => $a->notes,
                ]),
            'acquirerStatuses' => AcquirerStatus::options(),
            'integrationStatuses' => IntegrationStatus::options(),
            'documents' => $merchant->documents()->with(['status', 'owner'])->latest('updated_at')->limit(5)->get()
                ->map(fn ($document) => DocumentResource::make($document)->resolve()),
            'bankProviders' => Provider::query()->ofType(ProviderType::Bank)->orderBy('name')->get(['id', 'name', 'is_active']),
            'gateProviders' => Provider::query()->ofType(ProviderType::Gate)->orderBy('name')->get(['id', 'name', 'is_active']),
            'currencies' => Currency::options(),
            'midStatuses' => MidStatus::options(),
            'walletTypes' => WalletType::options(),
            'canManageSeeds' => $request->user()->role === UserRole::SuperAdmin,
            'overview' => app(MerchantOverview::class)->for(collect([$merchant])),
            'reports' => DailyReportTask::query()
                ->with('merchantMid:id,mid')
                ->where('merchant_id', $merchant->id)
                ->orderByDesc('report_date')->orderBy('merchant_mid_id')
                ->limit(60)
                ->get()
                ->map(fn (DailyReportTask $r) => [
                    'id' => $r->id,
                    'report_date' => $r->report_date->toDateString(),
                    'mid' => $r->merchantMid->mid,
                    'currency' => $r->currency,
                    'status' => $r->status->value,
                    'status_label' => $r->status->label(),
                    'sales_count' => $r->sales_count,
                    'turnover' => $r->turnover,
                    'net_payout' => $r->net_payout,
                    'net_profit' => $r->net_profit,
                    'has_pdf' => $r->generated_pdf_path !== null,
                ]),
            'settlements' => Settlement::query()
                ->where('merchant_id', $merchant->id)
                ->withCount('lines')
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (Settlement $s) => [
                    'id' => $s->id,
                    'number' => $s->number,
                    'status' => $s->status->value,
                    'status_label' => $s->status->label(),
                    'lines' => $s->lines_count,
                    'total_payout' => $s->total_payout,
                    'payout_currency' => $s->payout_currency,
                    'created_at' => $s->created_at?->toDateString(),
                    'settled_at' => $s->settled_at?->toDateString(),
                    'tx_hash' => $s->tx_hash,
                ]),
            // Portal logins that see this merchant: its company's and the parents'.
            'portalUsers' => $merchant->company ? PortalUserController::usersFor($merchant->company) : null,
            'portalCompanies' => $merchant->company
                ? collect($merchant->company->ancestorsWithSelf())->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])->all()
                : [],
            'portalUrl' => url('/merchant'),
        ]);
    }

    public function edit(Merchant $merchant): Response
    {
        return Inertia::render('admin/merchants/form', [
            'merchant' => MerchantResource::make($merchant)->resolve(),
            ...$this->formOptions(),
        ]);
    }

    public function update(MerchantRequest $request, Merchant $merchant): RedirectResponse
    {
        $merchant->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Merchant saved.']);

        return to_route('admin.merchants.show', $merchant);
    }

    /**
     * Deletes a closed merchant with all its data. The name must be typed
     * to confirm; the dialog shows what goes with it first.
     */
    public function destroy(Request $request, Merchant $merchant, MerchantPurger $purger): RedirectResponse
    {
        $request->validate([
            'confirm' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($merchant) {
                if (trim((string) $value) !== trim($merchant->name)) {
                    $fail('Type the merchant name exactly to confirm.');
                }
            }],
        ]);

        $deleted = $purger->purge($merchant, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Merchant %s deleted with %d operations and %d reports.',
            $merchant->name, $deleted['operations'], $deleted['daily_reports'],
        )]);

        return to_route('admin.merchants.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'cryptoProviders' => Provider::query()->ofType(ProviderType::Crypto)->orderBy('name')->get(['id', 'name']),
            'statuses' => MerchantStatus::options(),
            'defaults' => config('sterling.merchant_defaults'),
        ];
    }
}
