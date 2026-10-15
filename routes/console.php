<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cardaq (T+2, e-mail): look for the report every hour, round the clock.
Schedule::command('bots:dispatch cardaq-export')->hourly()->withoutOverlapping();
// Corefy (T+1): yesterday's export is ready in the morning; retry hourly until it is in.
Schedule::command('bots:dispatch corefy-export')->hourly()->between('06:00', '12:00')->timezone(config('sterling.timezone'))->withoutOverlapping();
Schedule::command('bots:dispatch madfin-export')->hourly()->withoutOverlapping();
Schedule::command('reports:release-due')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('reports:reconcile')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('reserve:release')->dailyAt('06:10')->withoutOverlapping();
