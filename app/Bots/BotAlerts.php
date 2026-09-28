<?php

namespace App\Bots;

use App\Enums\BotRunStatus;
use App\Models\BotRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells people when a bot keeps failing: after N failed runs in a row for
 * the same account (config sterling.bots.alert_after_failures).
 */
class BotAlerts
{
    public function afterFailure(BotRun $run): void
    {
        $threshold = (int) config('sterling.bots.alert_after_failures', 3);

        $recent = BotRun::query()
            ->where('connector', $run->connector)
            ->where('integration_account_id', $run->integration_account_id)
            ->whereIn('status', [BotRunStatus::Succeeded, BotRunStatus::Failed])
            ->latest('id')
            ->limit($threshold)
            ->pluck('status');

        if ($recent->count() < $threshold || $recent->contains(BotRunStatus::Succeeded)) {
            return;
        }

        $account = $run->account?->name ?? 'account #'.$run->integration_account_id;
        $this->send(sprintf(
            "⚠️ %s bot failed %d times in a row\nAccount: %s\nReport date: %s\nError: %s",
            $run->connector, $threshold, $account, $run->report_date->toDateString(), mb_substr((string) $run->error, 0, 500),
        ), $run->screenshot_path);
    }

    private function send(string $text, ?string $screenshot): void
    {
        Log::warning($text);

        $token = config('sterling.bots.telegram_token');
        $chat = config('sterling.bots.telegram_chat_id');
        if (! $token || ! $chat) {
            return;
        }

        try {
            if ($screenshot && is_file($screenshot)) {
                Http::attach('photo', (string) file_get_contents($screenshot), 'screenshot.png')
                    ->post("https://api.telegram.org/bot{$token}/sendPhoto", ['chat_id' => $chat, 'caption' => mb_substr($text, 0, 1000)]);
            } else {
                Http::post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chat, 'text' => $text]);
            }
        } catch (Throwable $e) {
            Log::error('Telegram alert failed: '.$e->getMessage());
        }
    }
}
