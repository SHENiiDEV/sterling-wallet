<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\MerchantAcquirer;
use App\Models\Provider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('banks:sync-terms {--apply : Write the changes (without it only the differences are shown)}')]
#[Description('Compare the cost terms of Madfin / Cardaq and the limit and MCC of their merchants with the agreed ones')]
class SyncBankTermsCommand extends Command
{
    /**
     * Percent = % of the sale, fixed amounts in EUR, `null` = the bank doesn't take the scheme (N/A).
     */
    private const BANKS = [
        'madfin' => [
            'visa' => 3.6, 'mastercard' => 4, 'success' => 0.25,
            'settlement_fee' => 60, 'cycle' => 'T+2', 'min_settlement' => 10000,
            'rr_percent' => 10, 'rr_days' => 180, 'cap' => 60000, 'refund' => 6, 'chargeback' => 80,
        ],
        'cardaq' => [
            'visa' => null, 'mastercard' => 3.8, 'success' => 0.20,
            'settlement_fee' => 10, 'cycle' => 'T+2', 'min_settlement' => 5,
            'rr_percent' => 10, 'rr_days' => 180, 'cap' => 50000, 'refund' => 6, 'chargeback' => 69,
        ],
    ];

    /**
     * [bank, merchant, MCC, limit EUR].
     */
    private const MERCHANTS = [
        ['madfin', 'FIXERO LTD', '5251', 400000],
        ['madfin', 'LUNEHOM LTD', '5712', 400000],
        ['madfin', 'CUR NOVA LTD', '8299', 400000],
        ['madfin', 'HARTWICK VENTURES LTD', '4814', 400000],
        ['cardaq', 'DOMESTIC DREAMS LIMITED', '7372', 1800000],
        ['cardaq', 'WORKING AGENT LTD', '7372', 1800000],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $changes = 0;
        $banks = [];

        foreach (self::BANKS as $code => $t) {
            $bank = Provider::query()->where('code', $code)->first();
            if ($bank === null) {
                $this->error("No provider with code '{$code}'.");

                continue;
            }
            $banks[$code] = $bank;

            $wanted = [
                'cost_visa_eu_percent' => $t['visa'], 'cost_visa_non_eu_percent' => $t['visa'],
                'cost_mastercard_eu_percent' => $t['mastercard'], 'cost_mastercard_non_eu_percent' => $t['mastercard'],
                'cost_success_fixed' => $t['success'],
                'settlement_fee' => $t['settlement_fee'], 'settlement_cycle' => $t['cycle'], 'min_settlement' => $t['min_settlement'],
                'rolling_reserve_percent' => $t['rr_percent'], 'rolling_reserve_days' => $t['rr_days'], 'rolling_reserve_cap' => $t['cap'],
                'cost_refund_fixed' => $t['refund'], 'cost_chargeback_fixed' => $t['chargeback'],
            ];

            $changes += $this->diff($bank->name, $bank, $wanted);
            if ($apply) {
                $bank->update($wanted);
            }
        }

        foreach (self::MERCHANTS as [$code, $name, $mcc, $limit]) {
            $label = "{$name} @ {$code}";
            $merchant = Merchant::query()->where('name', $name)->first();
            if ($merchant === null || ! isset($banks[$code])) {
                $this->warn("{$label}: merchant not found, create it first.");

                continue;
            }

            $link = MerchantAcquirer::query()->where('merchant_id', $merchant->id)->where('provider_id', $banks[$code]->id)->first();
            if ($link === null) {
                $this->warn("{$label}: no bank record, will be created.");
                $changes++;
                if ($apply) {
                    $merchant->acquirers()->create(['provider_id' => $banks[$code]->id, 'limit' => $limit, 'limit_currency' => 'EUR', 'mcc' => $mcc]);
                }

                continue;
            }

            $wanted = ['limit' => $limit, 'limit_currency' => 'EUR', 'mcc' => $mcc];
            $changes += $this->diff($label, $link, $wanted);
            if ($apply) {
                $link->update($wanted);
            }
        }

        $this->newLine();
        $this->info($changes === 0 ? 'Everything already matches.' : ($apply ? "Applied, {$changes} difference(s) fixed." : "{$changes} difference(s). Run with --apply to write them."));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $wanted
     */
    private function diff(string $label, Provider|MerchantAcquirer $model, array $wanted): int
    {
        $count = 0;
        foreach ($wanted as $field => $value) {
            $current = $model->getAttribute($field);
            $same = $value === null || $current === null
                ? $value === $current
                : (is_numeric($value) && is_numeric($current) ? (float) $value === (float) $current : (string) $value === (string) $current);

            if (! $same) {
                $this->line(sprintf('%s: %s  %s -> %s', $label, $field, $current ?? 'N/A', $value ?? 'N/A'));
                $count++;
            }
        }

        return $count;
    }
}
