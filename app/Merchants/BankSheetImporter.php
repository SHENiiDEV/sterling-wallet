<?php

namespace App\Merchants;

use App\Enums\AcquirerStatus;
use App\Enums\IntegrationStatus;
use App\Enums\MerchantStatus;
use App\Enums\ProviderType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantAcquirer;
use App\Models\Provider;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads the banks sheet: per bank a line of cost terms, then the merchants
 * with their onboarding status, MCC, limit, integration, PSP and a note.
 *
 *   Bank,Visa,MasterCard,Trx Fee,Settlement fee,Settlement Cycle,Min. Settlement,RR 180 days,CAP,Refund,CHB FEE,Note
 *   Merchants:,Company name,Website,Onboarding status,MCC,Current Limits EUR,Integration status,PSP,...,Note
 *
 * Without `$apply` nothing is written; every change is only reported.
 */
final class BankSheetImporter
{
    private const PRICING_NOTE = 'Pricing (sheet):';

    /** @var list<string> */
    private array $log = [];

    /**
     * @return list<string> what changed (or would change)
     */
    public function import(string $csv, bool $apply): array
    {
        $this->log = [];

        DB::transaction(function () use ($csv, $apply) {
            foreach ($this->parse($csv) as $block) {
                $bank = $this->bank($block['bank'], $apply);
                foreach ($block['merchants'] as $row) {
                    $this->link($bank, $block['bank']['name'], $row, $apply);
                }
            }
        });

        return $this->log;
    }

    /**
     * @return list<array{bank: array<string, string>, merchants: list<array<string, string>>}>
     */
    private function parse(string $csv): array
    {
        $blocks = [];
        $state = null;
        $bankHeader = [];
        $merchantHeader = [];

        foreach (preg_split('/\R/', $csv) ?: [] as $line) {
            $row = array_map('trim', str_getcsv($line, escape: ''));
            if (implode('', $row) === '') {
                $state = $state === 'merchants' ? null : $state;

                continue;
            }

            if ($row[0] === 'Bank') {
                $bankHeader = $row;
                $state = 'bank';

                continue;
            }

            if ($row[0] === 'Merchants:') {
                $merchantHeader = $row;
                $state = 'merchants';

                continue;
            }

            if ($state === 'bank') {
                $blocks[] = ['bank' => $this->assoc($bankHeader, $row, 'bank'), 'merchants' => []];
                $state = null;
            } elseif ($state === 'merchants' && $blocks !== [] && ($row[1] ?? '') !== '') {
                $blocks[array_key_last($blocks)]['merchants'][] = $this->assoc($merchantHeader, $row, 'merchant');
            }
        }

        return $blocks;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $row
     * @return array<string, string>
     */
    private function assoc(array $header, array $row, string $kind): array
    {
        $named = [];
        foreach ($header as $i => $name) {
            if ($name !== '') {
                $named[$name] = $row[$i] ?? '';
            }
        }

        // The note column has no header in the merchant block.
        $named['Note'] = $row[11] ?? '';

        if ($kind === 'bank') {
            $named['name'] = $row[0];
        }

        return $named;
    }

    /**
     * @param  array<string, string>  $sheet
     */
    private function bank(array $sheet, bool $apply): ?Provider
    {
        $name = $sheet['name'];
        $provider = Provider::query()->ofType(ProviderType::Bank)->whereRaw('lower(name) = ?', [Str::lower($name)])->first()
            ?? Provider::query()->where('code', Str::slug($name))->first();

        $wanted = [];
        $unparsed = [];

        foreach (['Visa' => 'visa', 'MasterCard' => 'mastercard'] as $column => $scheme) {
            $raw = $sheet[$column] ?? '';
            if ($raw === '') {
                continue;
            }
            [$value, $ok] = $this->percent($raw);
            if (! $ok) {
                $unparsed[] = "{$column} {$raw}";

                continue;
            }
            $wanted["cost_{$scheme}_eu_percent"] = $value;
            $wanted["cost_{$scheme}_non_eu_percent"] = $value;
        }

        $schemeRates = array_filter([
            $wanted['cost_visa_eu_percent'] ?? null, $wanted['cost_mastercard_eu_percent'] ?? null,
        ], fn ($v) => $v !== null);
        if ($schemeRates !== []) {
            // Unknown brand: price as the dearer scheme.
            $wanted['cost_acq_eu_percent'] = $wanted['cost_acq_non_eu_percent'] = max($schemeRates);
        }

        if (($sheet['Trx Fee'] ?? '') !== '') {
            if (preg_match('/^(\d+(?:\.\d+)?)\s*(.*)$/', $sheet['Trx Fee'], $m)) {
                $wanted['cost_success_fixed'] = (float) $m[1];
                if (str_contains(Str::lower($m[2]), 'decline')) {
                    $wanted['cost_decline_fixed'] = (float) $m[1];
                }
            } else {
                $unparsed[] = "Trx Fee {$sheet['Trx Fee']}";
            }
        }

        foreach (['Settlement fee' => 'settlement_fee', 'Min. Settlement' => 'min_settlement', 'CAP' => 'rolling_reserve_cap', 'Refund' => 'cost_refund_fixed', 'CHB FEE' => 'cost_chargeback_fixed'] as $column => $field) {
            $raw = $sheet[$column] ?? '';
            if ($raw === '') {
                continue;
            }
            $amount = $this->amount($raw);
            if ($amount === null) {
                $unparsed[] = "{$column} {$raw}";

                continue;
            }
            $wanted[$field] = $amount;
        }

        if (($sheet['Settlement Cycle'] ?? '') !== '') {
            $wanted['settlement_cycle'] = mb_substr($sheet['Settlement Cycle'], 0, 32);
        }

        if (($sheet['RR 180 days'] ?? '') !== '') {
            [$rr, $ok] = $this->percent($sheet['RR 180 days']);
            if ($ok && $rr !== null) {
                $wanted['rolling_reserve_percent'] = $rr;
                $wanted['rolling_reserve_days'] = 180;
            }
        }

        $notes = collect(preg_split('/\R/', (string) $provider?->notes) ?: [])
            ->reject(fn (string $l) => str_starts_with($l, self::PRICING_NOTE) || $l === '')
            ->when($unparsed !== [], fn ($c) => $c->push(self::PRICING_NOTE.' '.implode('; ', $unparsed)))
            ->implode("\n");
        $wanted['notes'] = $notes === '' ? null : $notes;

        if ($provider === null) {
            $this->log[] = "NEW bank {$name}".($unparsed === [] ? '' : ' (non-standard pricing kept in notes)');
            if (! $apply) {
                return null;
            }

            return Provider::query()->create([...$wanted, 'name' => $name, 'code' => Str::slug($name), 'type' => ProviderType::Bank, 'is_active' => true]);
        }

        foreach ($wanted as $field => $value) {
            if (! $this->same($provider->getAttribute($field), $value)) {
                $this->log[] = "{$provider->name}: {$field}  ".($provider->getAttribute($field) ?? 'N/A').' -> '.($value ?? 'N/A');
            }
        }
        if ($apply) {
            $provider->update($wanted);
        }

        return $provider;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function link(?Provider $bank, string $bankName, array $row, bool $apply): void
    {
        $company = $row['Company name'];
        $site = Str::lower($row['Website'] ?? '');
        $label = "{$company} @ {$bankName}";

        $merchant = $this->findMerchant($company, $site);

        $status = $this->enumOf(AcquirerStatus::class, $row['Onboarding status'] ?? '', $label);
        $integration = $this->enumOf(IntegrationStatus::class, $row['Integration status'] ?? '', $label);
        $mcc = preg_match('/^\d{4}$/', $row['MCC'] ?? '') ? $row['MCC'] : null;
        $limit = $this->amount($row['Current Limits EUR'] ?? '');

        if ($merchant === null) {
            $this->log[] = "NEW merchant {$company} ({$site})";
            if (! $apply) {
                return;
            }
            $merchant = $this->createMerchant($company, $site, $mcc);
        }

        if ($bank === null) {
            $this->log[] = "{$label}: link will be created with the bank";

            return;
        }

        $wanted = array_filter([
            'status' => $status,
            'integration_status' => $integration,
            'psp' => ($row['PSP'] ?? '') !== '' ? $row['PSP'] : null,
            'client_id' => ($row['PSP'] ?? '') !== '' ? Client::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($row['PSP']))])->value('id') : null,
            'mcc' => $mcc,
            'limit' => $limit,
            'notes' => ($row['Note'] ?? '') !== '' ? $row['Note'] : null,
        ], fn ($v) => $v !== null);

        $link = MerchantAcquirer::query()->where('merchant_id', $merchant->id)->where('provider_id', $bank->id)->first();
        if ($link === null) {
            $this->log[] = "{$label}: NEW bank record ".json_encode(array_map($this->plain(...), $wanted));
            if ($apply) {
                $merchant->acquirers()->create([...$wanted, 'provider_id' => $bank->id, 'limit_currency' => 'EUR']);
            }

            return;
        }

        foreach ($wanted as $field => $value) {
            $current = $this->plain($link->getAttribute($field));
            if (! $this->same($current, $this->plain($value))) {
                $this->log[] = "{$label}: {$field}  ".($current ?? '—').' -> '.$this->plain($value);
            }
        }
        if ($apply) {
            $link->update($wanted);
        }
    }

    /**
     * By website first; a merchant of that name without a site is adopted.
     */
    private function findMerchant(string $company, string $site): ?Merchant
    {
        $byName = fn () => Merchant::query()->whereRaw('lower(name) like ?', [Str::lower($company).'%']);

        if ($site !== '') {
            $found = Merchant::query()->whereRaw('lower(website) = ?', [$site])->first();
            if ($found !== null) {
                return $found;
            }
        }

        $unsited = $byName()->where(fn ($q) => $q->whereNull('website')->orWhere('website', ''))->first();

        return $unsited ?? ($site === '' ? $byName()->first() : null);
    }

    private function createMerchant(string $company, string $site, ?string $mcc): Merchant
    {
        $owner = Company::query()->firstOrCreate(['name' => $company]);
        $taken = Merchant::query()->where('company_id', $owner->id)->orWhereRaw('lower(name) = ?', [Str::lower($company)])->exists();

        return Merchant::query()->create([
            'company_id' => $owner->id,
            'name' => $taken && $site !== '' ? "{$company} · {$site}" : $company,
            'website' => $site !== '' ? $site : null,
            'mcc' => $mcc,
            'status' => MerchantStatus::Onboarding,
            ...config('sterling.merchant_defaults'),
        ]);
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enumOf(string $enum, string $text, string $label): ?BackedEnum
    {
        $key = Str::slug($text, '_');
        if ($key === '') {
            return null;
        }

        foreach ($enum::cases() as $case) {
            if ($case->value === $key || Str::slug($case->label(), '_') === $key) {
                return $case;
            }
        }
        // "Integration done" is the "Done" status.
        if ($enum === IntegrationStatus::class && $key === 'integration_done') {
            return IntegrationStatus::Done;
        }
        if ($enum === AcquirerStatus::class && $key === 're_brand') {
            return AcquirerStatus::Rebrand;
        }

        $this->log[] = "{$label}: unknown status '{$text}', left unchanged";

        return null;
    }

    /**
     * @return array{0: float|null, 1: bool} value and whether the text was understood ("N/A" = no rate)
     */
    private function percent(string $text): array
    {
        if (in_array(Str::upper($text), ['N/A', 'NA', '-'], true)) {
            return [null, true];
        }

        return preg_match('/^(\d+(?:[.,]\d+)?)\s*%$/', $text, $m) ? [(float) str_replace(',', '.', $m[1]), true] : [null, false];
    }

    private function amount(string $text): ?float
    {
        $clean = preg_replace('/\s*(EUR|€)\s*$/i', '', $text);
        $clean = str_replace([' ', "\u{a0}"], '', (string) $clean);

        return $clean !== '' && is_numeric($clean) ? (float) $clean : null;
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function same(mixed $current, mixed $wanted): bool
    {
        if ($current === null || $wanted === null) {
            return $current === $wanted;
        }

        return is_numeric($current) && is_numeric($wanted) ? (float) $current === (float) $wanted : (string) $current === (string) $wanted;
    }
}
