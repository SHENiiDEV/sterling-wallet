<?php

namespace App\Bots\Connectors;

use App\Bots\BotResult;
use App\Bots\Target;
use App\Enums\BotRunStatus;
use App\Models\BotRun;
use App\Models\IntegrationAccount;
use App\Models\MerchantMid;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Corefy over its API instead of the dashboard (no browser). The account lists no login: the commerce accounts come
 * from the MIDs it covers (Gate MID and Additional gateway MIDs), and each has its own API key, kept encrypted in
 * `integration_credentials`. The settings may carry `api_base_url` (default https://api.sterling-pay.com) and
 * `fetch_declines` (default true).
 *
 * A run fetches the successful payments (status processed) whose processing time falls on the report days, and,
 * because a payment that failed has no processing time, the failed ones by their creation time. They are written as
 * the same CSV the dashboard export gives, so the Corefy parser, the booking of each payment to the day it was made
 * on and everything after it stay as they are.
 */
class CorefyApiConnector extends CorefyExportConnector
{
    public const CODE = 'corefy-api';

    public const DEFAULT_URL = 'https://api.sterling-pay.com';

    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 500;

    private const COLUMNS = ['created', 'updated', 'id', 'reference_id', 'amount', 'currency', 'status', 'account', 'resolution', 'payer', 'issuer_country', 'payment_card_issuer_name'];

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Corefy export (API)';
    }

    /**
     * Only commerce accounts a MID names (Gate MID, Additional gateway MIDs) are fetched: each has its own key.
     *
     * @param  Collection<int, MerchantMid>  $mids
     * @return array<string, list<MerchantMid>>
     */
    protected function commerceAccounts(IntegrationAccount $account, Collection $mids): array
    {
        $byAccount = [];
        foreach ($mids as $mid) {
            foreach ($mid->gateAccounts() as $commerce) {
                $byAccount[$commerce][] = $mid;
            }
        }

        return $byAccount;
    }

    /**
     * The commerce accounts of the MIDs the bot account covers, with the MID numbers each fronts.
     *
     * @return array<string, list<string>>
     */
    public function commerceAccountsOf(IntegrationAccount $account): array
    {
        $out = [];
        foreach ($this->commerceAccounts($account, $account->coveredMids()->get()) as $commerce => $mids) {
            $out[$commerce] = array_map(fn (MerchantMid $m) => $m->mid, $mids);
        }
        ksort($out);

        return $out;
    }

    public function run(Target $target, BotRun $run): BotResult
    {
        $account = $target->account;
        $commerce = (string) ($target->params['commerce_account'] ?? '');
        $key = $account->credentials()->where('key', $commerce)->first()?->secret;
        if ($commerce === '' || $key === null || $key === '') {
            return new BotResult(BotRunStatus::Failed, error: "No API key for {$commerce}: add it in the bot account.");
        }

        $zone = $account->provider->timezone ?: config('sterling.timezone');
        // The report days as they are on the clock of the gateway's market: [from, until).
        $from = CarbonImmutable::parse($target->period->from->toDateString(), $zone)->startOfDay();
        $until = CarbonImmutable::parse($target->period->to->toDateString(), $zone)->addDay()->startOfDay();

        try {
            $items = $this->fetch($account, $commerce, $key, ['filter[processed][gte]' => $from->getTimestamp(), 'filter[processed][lte]' => $until->getTimestamp() - 1]);
            if ($account->setting('fetch_declines', true)) {
                // A failed payment has no processing time: it is found by when it was made.
                array_push($items, ...$this->fetch($account, $commerce, $key, ['filter[status]' => 'process_failed', 'filter[created][gte]' => $from->getTimestamp(), 'filter[created][lte]' => $until->getTimestamp() - 1]));
            }
        } catch (ApiFailure $e) {
            return new BotResult(BotRunStatus::Failed, error: $e->getMessage());
        }

        $rows = [];
        $skipped = 0;
        foreach ($items as $item) {
            if ($row = $this->row($item, $commerce, $zone)) {
                $rows[$row['id']] = $row;
            } else {
                $skipped++;
            }
        }
        $rows = array_values($rows);

        $empty = [];
        foreach ((array) ($target->params['periods'] ?? []) as $period) {
            $inside = array_filter($rows, fn (array $r) => substr($r['created'], 0, 10) >= $period['from'] && substr($r['created'], 0, 10) <= $period['to']);
            if ($inside === []) {
                $empty[] = $period['report_date'];
            }
        }

        $log = $commerce.': '.count($items).' payment(s) from the API for '.$from->toDateString().' – '.$until->subDay()->toDateString().', '.count($rows).' kept'
            .($skipped > 0 ? ", {$skipped} skipped (no id or time)" : '')
            .(count($items) - $skipped > count($rows) ? ', '.(count($items) - $skipped - count($rows)).' repeated ids' : '');
        // Where the payments fall, by the provider's day and kind, to see at a glance what a run really brought.
        $byDay = [];
        foreach ($rows as $row) {
            $kind = $row['status'] === 'process_failed' ? 'failed' : 'ok';
            $byDay[substr($row['created'], 0, 10)][$kind] = ($byDay[substr($row['created'], 0, 10)][$kind] ?? 0) + 1;
        }
        ksort($byDay);
        $log .= ' · by day: '.implode(', ', array_map(fn ($day, $n) => "{$day} ".($n['ok'] ?? 0).' ok/'.($n['failed'] ?? 0).' failed', array_keys($byDay), $byDay));
        if ($rows === []) {
            return new BotResult(BotRunStatus::Succeeded, rows: 0, reportDate: $target->period->reportDate, log: $log, emptyDates: $empty);
        }

        $directory = Storage::disk('local')->path("bots/{$run->id}");
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $file = $directory.'/corefy_api_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $commerce).'_'.$target->period->reportDate->toDateString().'.csv';
        $handle = fopen($file, 'wb');
        fputcsv($handle, self::COLUMNS, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', self::COLUMNS), escape: '');
        }
        fclose($handle);

        return new BotResult(BotRunStatus::Succeeded, files: [$file], rows: count($rows), reportDate: $target->period->reportDate, log: $log, emptyDates: $empty);
    }

    /**
     * What the API gives for one commerce account: the keys of one payment, to check the field names before relying
     * on it. The key itself is never returned.
     *
     * @return array{count: int, attributes: list<string>, payload: list<string>, sample: array<string, mixed>}
     */
    public function probe(IntegrationAccount $account, string $commerce, int $hours = 24): array
    {
        $key = $account->credentials()->where('key', $commerce)->first()?->secret;
        if ($key === null) {
            throw new ApiFailure("No API key for {$commerce} in this bot account.");
        }

        $now = CarbonImmutable::now()->getTimestamp();
        $items = $this->fetch($account, $commerce, $key, ['filter[processed][gte]' => $now - $hours * 3600, 'filter[processed][lte]' => $now], 3);
        $first = $items[0] ?? null;
        $attributes = (array) ($first['attributes'] ?? []);

        return [
            'count' => count($items),
            'attributes' => array_keys($attributes),
            'payload' => array_keys((array) ($attributes['payload'] ?? [])),
            'sample' => $first === null ? [] : [
                'id' => $first['id'] ?? null,
                'created' => $attributes['created'] ?? $attributes['created_at'] ?? null,
                'processed' => $attributes['processed'] ?? null,
                'status' => $attributes['status'] ?? null,
                'resolution' => $attributes['resolution'] ?? null,
                'amount' => $attributes['amount'] ?? null,
                'currency' => $attributes['currency'] ?? null,
            ],
        ];
    }

    /**
     * What the API gives for one day by different filters: how many payments, of which statuses, and between which
     * creation and processing times (UTC), to see whether a filter finds what it should or lets others through.
     *
     * @return array<string, array{count: int, statuses: string, created: string, processed: string}>
     */
    public function dayCounts(IntegrationAccount $account, string $commerce, string $day): array
    {
        $key = $account->credentials()->where('key', $commerce)->first()?->secret;
        if ($key === null) {
            throw new ApiFailure("No API key for {$commerce} in this bot account.");
        }

        $zone = $account->provider->timezone ?: config('sterling.timezone');
        $local = CarbonImmutable::parse($day, $zone)->startOfDay();
        $utc = CarbonImmutable::parse($day, 'UTC')->startOfDay();
        $range = fn (string $field, CarbonImmutable $from) => ["filter[{$field}][gte]" => $from->getTimestamp(), "filter[{$field}][lte]" => $from->addDay()->getTimestamp() - 1];
        $stamp = fn (mixed $v) => is_numeric($v) ? CarbonImmutable::createFromTimestampUTC((int) $v)->format('m-d H:i') : (string) $v;

        $summary = function (array $filters) use ($account, $commerce, $key, $stamp): array {
            $items = $this->fetch($account, $commerce, $key, $filters);
            $attributes = array_map(fn ($i) => (array) ($i['attributes'] ?? []), $items);
            $range = fn (string $field) => ($values = array_filter(array_column($attributes, $field), fn ($v) => $v !== null && $v !== '')) === []
                ? '—'
                : $stamp(min($values)).' … '.$stamp(max($values));

            return [
                'count' => count($items),
                'statuses' => collect($attributes)->pluck('status')->countBy()->map(fn ($n, $s) => "{$s} {$n}")->implode(', '),
                'created' => $range('created'),
                'processed' => $range('processed'),
            ];
        };

        return [
            "processed in the {$zone} day" => $summary($range('processed', $local)),
            'processed in the UTC day' => $summary($range('processed', $utc)),
            "created in the {$zone} day" => $summary($range('created', $local)),
            "failed, created in the {$zone} day" => $summary(['filter[status]' => 'process_failed', ...$range('created', $local)]),
        ];
    }

    /**
     * Every payment of one query, all pages.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function fetch(IntegrationAccount $account, string $commerce, string $key, array $filters, ?int $limit = null): array
    {
        $base = rtrim((string) $account->setting('api_base_url', self::DEFAULT_URL), '/');
        $url = $base.'/payment-invoices';
        $query = [...$filters, 'sort' => '-created', 'page[size]' => $limit ?? self::PAGE_SIZE];

        $items = [];
        $seen = [];
        for ($page = 1; $url !== null && $page <= self::MAX_PAGES; $page++) {
            try {
                $request = Http::withBasicAuth($commerce, $key)
                    ->withHeaders(['Accept' => 'application/vnd.api+json'])
                    ->timeout(60)->retry(2, 500, throw: false);
                // The next page is the API's own link, filters and page number in it. Passing a query array with it,
                // even an empty one, replaces that query string: the page then comes back unfiltered, as the first one.
                $response = $page === 1 ? $request->get($url, $query) : $request->get($url);
            } catch (ConnectionException $e) {
                throw new ApiFailure('Corefy API is not reachable: '.mb_substr($e->getMessage(), 0, 150));
            }

            if (! $response->successful()) {
                // Never the key: only what the API said about the request.
                throw new ApiFailure("Corefy API answered HTTP {$response->status()} for {$commerce}: ".mb_substr(strip_tags($response->body()), 0, 200));
            }

            $data = (array) $response->json();
            $batch = (array) ($data['data'] ?? []);
            // Only what is new: some APIs keep a "next" link on the last page and serve it again.
            $fresh = [];
            foreach ($batch as $item) {
                $id = is_array($item) ? (string) ($item['id'] ?? '') : '';
                if ($id !== '' && ! isset($seen[$id])) {
                    $seen[$id] = true;
                    $fresh[] = $item;
                }
            }
            array_push($items, ...$fresh);
            if ($fresh === [] || $limit !== null) {
                break;
            }

            $next = $data['links']['next'] ?? null;
            $url = is_string($next) && $next !== '' ? (str_starts_with($next, '/') ? $base.$next : $next) : null;
        }

        return $items;
    }

    /**
     * One payment as a row of the dashboard export: the time in the provider's time zone, the card mask as the payer.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, string>|null
     */
    private function row(array $item, string $commerce, string $zone): ?array
    {
        $a = (array) ($item['attributes'] ?? []);
        // The payment's own time; when the API gives no creation time, the processing time stands in for it.
        $created = $this->time($a['created'] ?? $a['created_at'] ?? $a['processed'] ?? null, $zone);
        if ($created === null || ! isset($item['id'])) {
            return null;
        }
        $card = (array) (($a['payload']['payment_card'] ?? null) ?: []);

        return [
            'created' => $created,
            'updated' => $this->time($a['processed'] ?? $a['updated'] ?? $a['updated_at'] ?? null, $zone) ?? '',
            'id' => (string) $item['id'],
            'reference_id' => (string) ($a['reference_id'] ?? ''),
            'amount' => (string) ($a['amount'] ?? ''),
            'currency' => (string) ($a['currency'] ?? ''),
            'status' => (string) ($a['status'] ?? ''),
            'account' => $commerce,
            'resolution' => (string) ($a['resolution'] ?? ''),
            'payer' => (string) ($card['mask'] ?? ''),
            'issuer_country' => (string) ($card['issuer_country'] ?? ''),
            'payment_card_issuer_name' => (string) ($card['issuer_name'] ?? ''),
        ];
    }

    /**
     * A Unix timestamp or an ISO text as local time "Y-m-d H:i:s".
     */
    private function time(mixed $value, string $zone): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $at = is_numeric($value) ? CarbonImmutable::createFromTimestampUTC((int) $value) : CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }

        return $at->setTimezone($zone)->format('Y-m-d H:i:s');
    }
}
