<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AcquirerStatus;
use App\Enums\IntegrationStatus;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantAcquirer;
use App\Services\AuditLogger;
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
        $acquirer = $merchant->acquirers()->create($this->validated($request, $merchant));
        AuditLogger::log('merchant_acquirer.created', $acquirer, $acquirer->only(['provider_id', 'status', 'limit']));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bank added.']);

        return back();
    }

    public function update(Request $request, Merchant $merchant, MerchantAcquirer $acquirer): RedirectResponse
    {
        $acquirer->update($this->validated($request, $merchant, $acquirer));
        AuditLogger::log('merchant_acquirer.updated', $acquirer, $acquirer->only(['provider_id', 'status', 'limit']));

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
     * @return array<string, mixed>
     */
    private function validated(Request $request, Merchant $merchant, ?MerchantAcquirer $acquirer = null): array
    {
        return $request->validate([
            'provider_id' => [
                'required',
                Rule::exists('providers', 'id')->where('type', ProviderType::Bank->value),
                Rule::unique('merchant_acquirers', 'provider_id')->where('merchant_id', $merchant->id)->ignore($acquirer?->id),
            ],
            'status' => ['required', Rule::enum(AcquirerStatus::class)],
            'limit' => ['nullable', 'numeric', 'min:0', 'max:10000000000'],
            'limit_currency' => ['required', 'string', 'size:3'],
            'integration_status' => ['required', Rule::enum(IntegrationStatus::class)],
            'psp' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['provider_id.unique' => 'This bank is already listed for the merchant.']);
    }
}
