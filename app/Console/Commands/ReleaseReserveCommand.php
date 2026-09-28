<?php

namespace App\Console\Commands;

use App\Settlements\ReserveReleaseService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reserve:release')]
#[Description('Release rolling reserve whose hold period is over and add it to the merchant draft settlement')]
class ReleaseReserveCommand extends Command
{
    public function handle(ReserveReleaseService $service): int
    {
        $released = $service->releaseDue();
        $this->info(count($released).' reserve release(s) created.');

        return self::SUCCESS;
    }
}
