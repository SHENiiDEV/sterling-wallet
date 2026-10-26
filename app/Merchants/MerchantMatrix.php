<?php

namespace App\Merchants;

use App\Enums\ProviderType;
use App\Models\Client;
use App\Models\Merchant;
use App\Models\MerchantAcquirer;
use App\Models\Provider;

/**
 * Companies and sites down the side, banks across the top, the onboarding standing in each cell.
 */
final class MerchantMatrix
{
    public function __construct(private AcquirerIntegration $integration) {}

    /**
     * @return array{banks: list<array<string, mixed>>, rows: list<array<string, mixed>>, clients: list<array<string, mixed>>, withoutClient: int}
     */
    public function build(): array
    {
        $banks = Provider::query()->ofType(ProviderType::Bank)->orderBy('name')->get(['id', 'name', 'is_active']);

        $merchants = Merchant::query()
            ->with(['company:id,name', 'acquirers.client:id,name'])
            ->where('is_test', false)
            ->orderBy('name')
            ->get();

        $withoutClient = 0;
        $rows = $merchants->map(function (Merchant $merchant) use ($banks, &$withoutClient) {
            $cells = [];
            foreach ($merchant->acquirers as $acquirer) {
                /** @var MerchantAcquirer $acquirer */
                if (! $banks->contains('id', $acquirer->provider_id)) {
                    continue;
                }
                $integration = $this->integration->for($acquirer);
                $withoutClient += $acquirer->client_id === null ? 1 : 0;

                $cells[$acquirer->provider_id] = [
                    'status' => $acquirer->status->value,
                    'status_label' => $acquirer->status->label(),
                    'since' => $acquirer->status_changed_at?->toDateString(),
                    'stale_days' => AcquirerIntegration::staleDays($acquirer),
                    'limit' => $acquirer->limit,
                    'limit_currency' => $acquirer->limit_currency,
                    'client_id' => $acquirer->client_id,
                    'client' => $acquirer->client?->name ?? $acquirer->psp,
                    'integration' => $integration['value'],
                    'integration_label' => $integration['label'],
                    'integration_auto' => $integration['auto'],
                ];
            }

            return [
                'public_id' => $merchant->public_id,
                'name' => $merchant->name,
                'company' => $merchant->company?->name,
                'website' => $merchant->website,
                'cells' => (object) $cells,
            ];
        })->values()->all();

        return [
            'banks' => $banks->map(fn (Provider $b) => ['id' => $b->id, 'name' => $b->name, 'is_active' => $b->is_active])->all(),
            'rows' => $rows,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name'])->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'withoutClient' => $withoutClient,
        ];
    }
}
