<?php

namespace App\Merchants;

use App\Enums\AcquirerStatus;
use App\Enums\IntegrationStatus;
use App\Enums\MidStatus;
use App\Models\AdminAuditLog;
use App\Models\Client;
use App\Models\Merchant;
use App\Models\MerchantAcquirer;
use App\Models\MerchantMid;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who changed what on a merchant's banks and terminals, newest first, in words.
 */
final class MerchantHistory
{
    private const FIELDS = [
        'status' => 'Status',
        'status_note' => 'Note',
        'limit' => 'Limit',
        'limit_currency' => 'Limit currency',
        'integration_status' => 'Integration',
        'client_id' => 'Client',
        'psp' => 'PSP',
        'mcc' => 'MCC',
        'notes' => 'Notes',
        'mid' => 'MID',
        'label' => 'Label',
        'currency' => 'Currency',
        'bank_provider_id' => 'Acquirer',
        'gate_provider_id' => 'Gateway',
        'gate_mid' => 'Gateway MID',
        'extra_gate_mids' => 'Extra gateway MIDs',
        'processing_limit' => 'Limit',
        'processing_limit_visa' => 'Visa limit',
        'processing_limit_mastercard' => 'Mastercard limit',
        'daily_norm' => 'Daily norm',
        'rolling_reserve_limit' => 'Reserve limit',
        'reports_start_date' => 'Reports from',
    ];

    /**
     * @return list<array{id: int, at: string|null, user: string|null, area: string, title: string, lines: list<string>}>
     */
    public function for(Merchant $merchant, int $limit = 40): array
    {
        $acquirers = MerchantAcquirer::query()->where('merchant_id', $merchant->id)->pluck('id')->all();
        $mids = MerchantMid::query()->where('merchant_id', $merchant->id)->pluck('id')->all();

        $logs = AdminAuditLog::query()
            ->with('user:id,name')
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('subject_type', (new MerchantAcquirer)->getMorphClass())->whereIn('subject_id', $acquirers))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', (new MerchantMid)->getMorphClass())->whereIn('subject_id', $mids)))
            ->latest('id')
            ->limit($limit)
            ->get();

        $providers = Provider::query()->pluck('name', 'id');
        $clients = Client::query()->pluck('name', 'id');

        return $logs->map(function (AdminAuditLog $log) use ($providers, $clients) {
            $meta = $log->meta ?? [];
            $isMid = str_starts_with($log->action, 'merchant_mid');
            $verb = explode('.', $log->action)[1] ?? '';

            $name = fn (string $field, mixed $value) => match (true) {
                $value === null || $value === '' => '—',
                $field === 'client_id' => $clients[$value] ?? "#{$value}",
                in_array($field, ['bank_provider_id', 'gate_provider_id', 'provider_id'], true) => $providers[$value] ?? "#{$value}",
                $field === 'status' && ! $isMid => AcquirerStatus::tryFrom((string) $value)?->label() ?? $value,
                $field === 'status' => MidStatus::tryFrom((string) $value)?->label() ?? $value,
                $field === 'integration_status' => IntegrationStatus::tryFrom((string) $value)?->label() ?? $value,
                default => (string) $value,
            };

            $lines = [];
            foreach ($meta['changes'] ?? [] as $field => [$before, $after]) {
                $lines[] = (self::FIELDS[$field] ?? $field).': '.$name($field, $before).' → '.$name($field, $after);
            }
            if ($verb === 'created') {
                foreach (['provider_id', 'status', 'client_id', 'mid', 'bank_provider_id', 'gate_provider_id'] as $field) {
                    if (isset($meta[$field])) {
                        $lines[] = (self::FIELDS[$field] ?? ($field === 'provider_id' ? 'Bank' : $field)).': '.$name($field, $meta[$field]);
                    }
                }
            }

            return [
                'id' => $log->id,
                'at' => $log->created_at?->toIso8601String(),
                'user' => $log->user?->name,
                'area' => $isMid ? 'MID' : 'Bank',
                'title' => ($isMid ? 'MID' : 'Bank record').' '.match ($verb) { 'created' => 'added', 'updated' => 'changed', 'deleted' => 'removed', default => $verb },
                'lines' => $lines,
            ];
        })->all();
    }
}
