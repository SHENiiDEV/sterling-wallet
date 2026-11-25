<?php

namespace App\Console\Commands;

use App\Enums\Currency;
use App\Models\FxRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('fx:fetch {--from= : First day of a range to fill, YYYY-MM-DD (default: the latest rates)} {--to= : Last day of the range (default today)}')]
#[Description('Store the day\'s EUR, USD and GBP rates from the free Frankfurter API (ECB reference rates); a rate typed by hand is never overwritten')]
class FxFetchCommand extends Command
{
    public function handle(): int
    {
        $currencies = array_map(fn (Currency $c) => $c->value, Currency::cases());
        $symbols = implode(',', array_diff($currencies, ['EUR']));
        $base = rtrim((string) config('sterling.fx_api_url'), '/');

        $path = $this->option('from')
            ? '/v1/'.$this->option('from').'..'.($this->option('to') ?: now()->toDateString())
            : '/v1/latest';

        try {
            $response = Http::timeout(30)->retry(2, 500, throw: false)->get($base.$path, ['base' => 'EUR', 'symbols' => $symbols]);
        } catch (Throwable $e) {
            $this->error('The rates API is not reachable: '.mb_substr($e->getMessage(), 0, 150));

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("The rates API answered HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $data = (array) $response->json();
        // One day ("date" + "rates") or a range ("rates" keyed by day).
        $days = isset($data['date']) ? [(string) $data['date'] => (array) ($data['rates'] ?? [])] : array_map(fn ($r) => (array) $r, (array) ($data['rates'] ?? []));

        $stored = 0;
        foreach ($days as $day => $rates) {
            $rates = array_change_key_case(array_filter($rates, fn ($r) => is_numeric($r) && (float) $r > 0), CASE_UPPER);
            // The pairs the system asks for: EUR to each, and the cross rates between the others.
            $pairs = [];
            foreach ($rates as $quote => $rate) {
                $pairs[] = ['EUR', (string) $quote, BigDecimal::of((string) $rate)];
            }
            foreach ($rates as $from => $fromRate) {
                foreach ($rates as $to => $toRate) {
                    if ($from < $to) {
                        $pairs[] = [(string) $from, (string) $to, BigDecimal::of((string) $toRate)->dividedBy((string) $fromRate, 8, RoundingMode::HalfUp)];
                    }
                }
            }

            foreach ($pairs as [$from, $to, $rate]) {
                $row = FxRate::query()->firstOrCreate(
                    ['rate_date' => $day, 'base' => $from, 'quote' => $to],
                    ['rate' => (string) $rate->toScale(8, RoundingMode::HalfUp), 'source' => 'frankfurter'],
                );
                $stored += $row->wasRecentlyCreated ? 1 : 0;
            }
        }

        $this->info("Stored {$stored} new rate(s) for ".count($days).' day(s).');

        return self::SUCCESS;
    }
}
