<?php

namespace App\Console\Commands;

use App\Enums\ProviderType;
use App\Models\MerchantOperation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Gateway operations stored before the reference id was kept as a field: fills it from the raw row (Corefy's
 * `reference_id`), so it can be searched and used to pair the two sides exactly.
 */
#[Signature('operations:backfill-references {--apply : Write it; without it only counts}')]
#[Description('Fill the reference of gateway operations from their raw rows (Corefy reference_id)')]
class BackfillReferencesCommand extends Command
{
    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $found = 0;

        MerchantOperation::query()
            ->where('role', ProviderType::Gate->value)
            ->whereNull('reference')
            ->whereNotNull('raw')
            ->chunkById(1000, function ($operations) use ($apply, &$found) {
                foreach ($operations as $op) {
                    $reference = $op->raw['reference_id'] ?? null;
                    if (! is_string($reference) || trim($reference) === '') {
                        continue;
                    }
                    $found++;
                    if ($apply) {
                        $op->update(['reference' => mb_substr(trim($reference), 0, 128)]);
                    }
                }
            });

        $this->info(($apply ? 'Filled' : 'Would fill')." the reference of {$found} gateway operation(s).");
        if (! $apply) {
            $this->warn('Dry run. Add --apply to do it, then run reports:reconcile --days=90.');
        }

        return self::SUCCESS;
    }
}
