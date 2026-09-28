<?php

namespace App\Console\Commands;

use App\Bots\BotDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bots:dispatch {connector? : Only this connector code}')]
#[Description('Queue bot runs for every report file that is due and still missing')]
class DispatchBotsCommand extends Command
{
    public function handle(BotDispatcher $dispatcher): int
    {
        $runs = $dispatcher->dispatchDue($this->argument('connector'));

        foreach ($runs as $run) {
            $this->line("Queued #{$run->id} {$run->connector} {$run->target_key}");
        }
        $this->info(count($runs).' run(s) queued.');

        return self::SUCCESS;
    }
}
