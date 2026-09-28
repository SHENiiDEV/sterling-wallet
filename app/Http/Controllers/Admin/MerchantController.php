<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Enums\MerchantStatus;
use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Enums\UserRole;
use App\Enums\WalletType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MerchantRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\MerchantResource;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantCryptoWallet;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
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
            'company:id,name',
            'cryptoProvider:id,name',
            'mids' => fn ($q) => $q->with(['bankProvider:id,name', 'gateProvider:id,name'])
                ->withSum('reserveEntries as reserve_balance', 'amount')
                ->orderBy('currency'),
        ]);

        return Inertia::render('admin/merchants/show', [
            'merchant' => MerchantResource::make($merchant)->resolve(),
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
            'documents' => $merchant->documents()->with(['status', 'owner'])->latest('updated_at')->limit(5)->get()
                ->map(fn ($document) => DocumentResource::make($document)->resolve()),
            'bankProviders' => Provider::query()->ofType(ProviderType::Bank)->orderBy('name')->get(['id', 'name', 'is_active']),
            'gateProviders' => Provider::query()->ofType(ProviderType::Gate)->orderBy('name')->get(['id', 'name', 'is_active']),
            'currencies' => Currency::options(),
            'midStatuses' => MidStatus::options(),
            'walletTypes' => WalletType::options(),
            'canManageSeeds' => $request->user()->role === UserRole::SuperAdmin,
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

    public function destroy(Merchant $merchant): RedirectResponse
    {
        if ($merchant->operations()->exists() || $merchant->dailyReports()->exists()) {
            throw ValidationException::withMessages([
                'merchant' => 'This merchant has operations or reports. Close it instead of deleting.',
            ]);
        }

        $merchant->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Merchant deleted.']);

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
