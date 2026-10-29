<?php

namespace App\Bots;

use App\Enums\BotRunStatus;
use App\Models\IntegrationAccount;
use Carbon\CarbonImmutable;

/**
 * When an account's bot may be started by the scheduler. `auto` keeps the rhythm of its connector (see routes/console.php);
 * `interval` starts it every N minutes; `daily` once a day from a set time (in the report time zone), trying again
 * every hour until a run has succeeded that day. What a run fetches is still decided by what is missing.
 */
class BotSchedule
{
    public const AUTO = 'auto';

    public const INTERVAL = 'interval';

    public const DAILY = 'daily';

    public static function isCustom(IntegrationAccount $account): bool
    {
        return in_array($account->schedule_mode, [self::INTERVAL, self::DAILY], true);
    }

    public function due(IntegrationAccount $account, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return match ($account->schedule_mode) {
            self::INTERVAL => $this->intervalDue($account, $now),
            self::DAILY => $this->dailyDue($account, $now),
            default => false,
        };
    }

    /**
     * A short description for the accounts list.
     */
    public static function label(IntegrationAccount $account): string
    {
        return match ($account->schedule_mode) {
            self::INTERVAL => self::everyLabel((int) $account->schedule_every_minutes),
            self::DAILY => 'Daily at '.$account->schedule_at,
            default => 'Automatic',
        };
    }

    private static function everyLabel(int $minutes): string
    {
        return $minutes % 60 === 0 ? 'Every '.($minutes / 60 === 1 ? 'hour' : ($minutes / 60).' hours') : "Every {$minutes} min";
    }

    private function intervalDue(IntegrationAccount $account, CarbonImmutable $now): bool
    {
        $minutes = max(1, (int) $account->schedule_every_minutes);
        $last = $account->runs()->latest('id')->value('created_at');

        return $last === null || CarbonImmutable::parse($last)->addMinutes($minutes)->lessThanOrEqualTo($now);
    }

    private function dailyDue(IntegrationAccount $account, CarbonImmutable $now): bool
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) $account->schedule_at) + [0, 0]);
        $zone = config('sterling.timezone');
        $slot = $now->setTimezone($zone)->setTime($hour, $minute)->utc();

        if ($now->lessThan($slot)) {
            return false;
        }

        $runs = $account->runs()->where('created_at', '>=', $slot);

        // Done for today once a run has succeeded; otherwise (nothing yet, no file yet, a failure) try again each hour.
        if ((clone $runs)->where('status', BotRunStatus::Succeeded->value)->exists()) {
            return false;
        }

        $last = $runs->latest('id')->value('created_at');

        return $last === null || CarbonImmutable::parse($last)->addHour()->lessThanOrEqualTo($now);
    }
}
