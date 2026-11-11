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
            $items = $this->fetch($account, $commerce, $key, ['filter[status]' => 'processed', 'filter[processed][gte]' => $from->getTimestamp(), 'filter[processed][lte]' => $until->getTimestamp() - 1]);
            if ($account->setting('fetch_declines', true)) {
                // A failed payment has no processing time: it is found by when it was made.
                array_push($items, ...$this->fetch($account, $commerce, $key, ['filter[status]' => 'process_failed', 'filter[created][gte]' => $from->getTimestamp(), 'filter[created][lte]' => $until->getTimestamp() - 1]));
            }
        } catch (ApiFailure $e) {
            return new BotResult(BotRunStatus::Failed, error: $e->getMessage());
        }

        $rows = [];
        foreach ($items as $item) {
            if ($row = $this->row($item, $commerce, $zone)) {
                $rows[$row['id']] = $row;
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

        $log = $commerce.': '.count($items).' payment(s) from the API for '.$from->toDateString().' – '.$until->subDay()->toDateString().', '.count($rows).' kept';
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
        $items = $this->fetch($account, $commerce, $key, ['filter[status]' => 'processed', 'filter[processed][gte]' => $now - $hours * 3600, 'filter[processed][lte]' => $now], 3);
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
        for ($page = 1; $url !== null && $page <= self::MAX_PAGES; $page++) {
            try {
                $response = Http::withBasicAuth($commerce, $key)
                    ->withHeaders(['Accept' => 'application/vnd.api+json'])
                    ->timeout(60)->retry(2, 500, throw: false)
                    ->get($url, $page === 1 ? $query : []);
            } catch (ConnectionException $e) {
                throw new ApiFailure('Corefy API is not reachable: '.mb_substr($e->getMessage(), 0, 150));
            }

            if (! $response->successful()) {
                // Never the key: only what the API said about the request.
                throw new ApiFailure("Corefy API answered HTTP {$response->status()} for {$commerce}: ".mb_substr(strip_tags($response->body()), 0, 200));
            }

            $data = (array) $response->json();
            $batch = (array) ($data['data'] ?? []);
            array_push($items, ...$batch);
            if ($batch === [] || $limit !== null) {
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
        $created = $this->time($a['created'] ?? $a['created_at'] ?? null, $zone);
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
