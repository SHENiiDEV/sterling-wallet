<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AcquirerStatus;
use App\Enums\IntegrationStatus;
use App\Enums\ProviderType;
use App\Enums\TariffProduct;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Merchant;
use App\Models\MerchantAcquirer;
use App\Services\AuditLogger;
use App\Support\ProductScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * A merchant's standing with each acquiring bank (onboarding pipeline).
 */
class MerchantAcquirerController extends Controller
{
    public function store(Request $request, Merchant $merchant): RedirectResponse
    {
        $acquirer = $merchant->acquirers()->create($this->payload($this->validated($request, $merchant)) + ['status_changed_at' => now()]);
        AuditLogger::log('merchant_acquirer.created', $acquirer, $acquirer->only(['provider_id', 'status', 'limit', 'client_id']));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bank added.']);

        return back();
    }

    public function update(Request $request, Merchant $merchant, MerchantAcquirer $acquirer): RedirectResponse
    {
        $data = $this->payload($this->validated($request, $merchant, $acquirer), $acquirer);

        if ($data['status'] !== $acquirer->status->value) {
            $data['status_changed_at'] = now();
        } else {
            unset($data['status_note']);
        }

        $changes = AuditLogger::diff($acquirer, $data);
        $acquirer->save();

        if ($changes !== []) {
            AuditLogger::log('merchant_acquirer.updated', $acquirer, ['changes' => $changes]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    public function destroy(Merchant $merchant, MerchantAcquirer $acquirer): RedirectResponse
    {
        AuditLogger::log('merchant_acquirer.deleted', $acquirer, $acquirer->only(['provider_id', 'status']));
        $acquirer->delete();

        return back();
    }

    /**
     * The PSP text follows the chosen client, so everything that still reads it keeps showing the right name.
     * A legacy PSP without a client stays as it is until a client is picked.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data, ?MerchantAcquirer $acquirer = null): array
    {
        if (isset($data['client_id'])) {
            $data['psp'] = Client::query()->whereKey($data['client_id'])->value('name');
        } elseif ($acquirer?->client_id !== null) {
            $data['psp'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Merchant $merchant, ?MerchantAcquirer $acquirer = null): array
    {
        return $request->validate([
            'provider_id' => [
                'required',
                Rule::exists('providers', 'id')->where('type', ProviderType::Bank->value)->when(ProductScope::current(), fn ($rule, TariffProduct $p) => $rule->where('product', $p->value)),
                Rule::unique('merchant_acquirers', 'provider_id')->where('merchant_id', $merchant->id)->ignore($acquirer?->id),
            ],
            'status' => ['required', Rule::enum(AcquirerStatus::class)],
            'limit' => ['nullable', 'numeric', 'min:0', 'max:10000000000'],
            'limit_currency' => ['required', 'string', 'size:3'],
            'integration_status' => ['required', Rule::enum(IntegrationStatus::class)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'status_note' => ['nullable', 'string', 'max:255'],
            'mcc' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['provider_id.unique' => 'This bank is already listed for the merchant.']);
    }
}
