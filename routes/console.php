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
// Corefy API: every 30 minutes all the payments of yesterday and today again, so the day's figures are never behind.
Schedule::command('corefy:refresh')->everyThirtyMinutes()->withoutOverlapping();
// FinBridges (C2B wallet API): pick up new transactions every 10 minutes.
Schedule::command('bots:dispatch finbridges-api')->everyTenMinutes()->withoutOverlapping();
// Accounts with their own rhythm (every N minutes, or daily at a set time): checked every minute.
Schedule::command('bots:dispatch --custom')->everyMinute()->withoutOverlapping();
Schedule::command('reports:release-due')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('reports:reconcile')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('reserve:release')->dailyAt('06:10')->withoutOverlapping();
// The same payment stored twice (a late file in another layout): looked for every night, told by message, removed by hand.
Schedule::command('operations:dedupe --notify')->dailyAt('05:30')->withoutOverlapping();
