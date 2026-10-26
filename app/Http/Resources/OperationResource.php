<?php

namespace App\Http\Resources;

use App\Models\MerchantOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MerchantOperation
 */
class OperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'name' => $this->provider->name]),
            'role' => $this->role->value,
            'mid' => $this->mid,
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant ? ['public_id' => $this->merchant->public_id, 'name' => $this->merchant->name] : null),
            'payment_id' => $this->payment_id,
            'reference' => $this->reference,
            'sp_id' => $this->sp_id,
            'matched_operation_id' => $this->matched_operation_id,
            'arn' => $this->getAttribute('arn'),
            'rrn' => $this->getAttribute('rrn'),
            'approval_code' => $this->getAttribute('approval_code'),
            'card_bin' => $this->card_bin,
            'card_last4' => $this->card_last4,
            'customer_email' => $this->customer_email,
            'customer_name' => $this->customerName(),
            'customer_account' => $this->customerAccount(),
            'ips' => $this->ips,
            'region' => $this->region,
            'issuer_country' => $this->getAttribute('issuer_country'),
            'issuer_name' => $this->getAttribute('issuer_name'),
            'trn_type' => $this->getAttribute('trn_type'),
            // Corefy: how many attempts (terminals) the payment went through; more than one is a cascade.
            'attempts' => $this->attempts(),
            'operation_type' => $this->operation_type->value,
            'operation_type_label' => $this->operation_type->label(),
            'resolution' => $this->getAttribute('resolution'),
            'processing_code' => $this->getAttribute('processing_code'),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'report_date' => $this->report_date?->toDateString(),
            'transaction_at' => $this->transaction_at?->toIso8601String(),
            'processing_at' => $this->getAttribute('processing_at')?->toIso8601String(),
        ];
    }

    private function attempts(): ?int
    {
        foreach ((array) $this->raw as $header => $value) {
            if (preg_match('/tries/i', (string) $header) && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
