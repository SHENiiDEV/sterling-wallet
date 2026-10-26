<?php

namespace App\Console\Commands;

use App\Bots\BotDispatcher;
use App\Merchants\MidPurger;
use App\Models\IntegrationAccount;
use App\Models\MerchantMid;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('finbridges:reload {--from= : First day, YYYY-MM-DD} {--to= : Last day, YYYY-MM-DD (default today)} {--chunk=0 : Split into runs of this many days (0 = one run)} {--account= : Only this bot account id} {--clear : Delete the existing operations, reports and reserve of the covered MIDs first} {--apply : Do it; without it only shows what would happen}')]
#[Description('Reload the history of the FinBridges (C2B) wallets over a date range, optionally clearing what is stored first')]
class FinBridgesBackfillCommand extends Command
{
    public function handle(BotDispatcher $dispatcher, MidPurger $purger): int
    {
        $from = $this->option('from') ? CarbonImmutable::parse($this->option('from'))->startOfDay() : null;
        $to = CarbonImmutable::parse($this->option('to') ?: 'today')->startOfDay();
        if ($from === null || $from->greaterThan($to)) {
            $this->error('Give --from=YYYY-MM-DD, not later than --to.');

            return self::FAILURE;
        }

        $accounts = IntegrationAccount::query()
            ->where('connector', 'finbridges-api')
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->where('is_active', true)
            ->get();

        if ($accounts->isEmpty()) {
            $this->error('No active FinBridges bot account.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $mids = $accounts->flatMap(fn (IntegrationAccount $a) => $a->coveredMids()->get())->unique('id');

        if ($this->option('clear')) {
            $summaries = $mids->mapWithKeys(fn (MerchantMid $mid) => [$mid->id => $purger->summary($mid)]);

            foreach ($mids as $mid) {
                $summary = $summaries[$mid->id];
                $this->line(($apply ? 'Clearing' : 'Would clear')." MID {$mid->mid}: {$summary['operations']} operations, {$summary['daily_reports']} reports, {$summary['reserve_entries']} reserve entries".($summary['locked_settlements'] ? ' — LOCKED by '.implode(', ', $summary['locked_settlements']) : ''));
            }

            // Nothing is deleted unless every terminal can be.
            if ($summaries->contains(fn (array $s) => $s['locked_settlements'] !== [])) {
                $this->error('Some reports are in approved or paid settlements. Cancel those first; nothing was changed.');

                return self::FAILURE;
            }

            if ($apply) {
                $mids->each(fn (MerchantMid $mid) => $purger->clear($mid));
            }
        }

        $chunk = max(0, (int) $this->option('chunk'));
        foreach ($accounts as $account) {
            for ($start = $from; $start->lessThanOrEqualTo($to); $start = $chunk > 0 ? $start->addDays($chunk) : $to->addDay()) {
                $end = $chunk > 0 ? min($start->addDays($chunk - 1), $to) : $to;
                $label = "{$account->name}: {$start->toDateString()} – {$end->toDateString()}";

                if (! $apply) {
                    $this->line("Would queue {$label}");

                    continue;
                }

                try {
                    $run = $dispatcher->queueRange($account, $start, $end);
                } catch (InvalidArgumentException $e) {
                    $this->error("{$label}: {$e->getMessage()}");

                    continue;
                }
                $this->line($run ? "Queued #{$run->id} {$label}" : "Already queued: {$label}");
            }
        }

        if (! $apply) {
            $this->warn('Dry run. Add --apply to do it.');
        }

        return self::SUCCESS;
    }
}
