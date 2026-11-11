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

/**
 * Corefy over its API instead of the dashboard (no browser). One integration account is one commerce account:
 * Login = the commerce account id (coma_…), Password = its API key, both encrypted; settings may carry
 * `api_base_url` (default https://api.sterling-pay.com). The payments of the window are written as the same CSV the
 * dashboard export gives, so the Corefy parser, the day-of-payment booking and everything after it stay as they are.
 */
class CorefyApiConnector extends CorefyExportConnector
{
    public const DEFAULT_URL = 'https://api.sterling-pay.com';

    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 500;

    private const COLUMNS = ['created', 'updated', 'id', 'reference_id', 'amount', 'currency', 'status', 'account', 'resolution', 'payer', 'issuer_country', 'payment_card_issuer_name'];

    public function code(): string
    {
        return 'corefy-api';
    }

    public function label(): string
    {
        return 'Corefy API (no browser)';
    }

    /**
     * The account is the commerce account: its login names it.
     *
     * @param  Collection<int, MerchantMid>  $mids
     * @return array<string, list<MerchantMid>>
     */
    protected function commerceAccounts(IntegrationAccount $account, Collection $mids): array
    {
        $id = trim((string) $account->username);

        return $id === '' ? [] : [$id => $mids->values()->all()];
    }

    public function run(Target $target, BotRun $run): BotResult
    {
        $account = $target->account;
        $zone = $account->provider->timezone ?: config('sterling.timezone');
        // A day and a day on each side, like the dashboard bot: every payment is booked to its own day on arrival.
        $from = CarbonImmutable::parse($target->period->from->toDateString(), $zone)->subDay()->startOfDay();
        $until = CarbonImmutable::parse($target->period->to->toDateString(), $zone)->addDays(2)->startOfDay();

        try {
            $items = $this->fetch($account, $from->getTimestamp(), $until->getTimestamp() - 1);
        } catch (ApiFailure $e) {
            return new BotResult(BotRunStatus::Failed, error: $e->getMessage());
        }

        $rows = [];
        foreach ($items as $item) {
            if ($row = $this->row($item, (string) $account->username, $zone)) {
                $rows[] = $row;
            }
        }

        $periods = (array) ($target->params['periods'] ?? []);
        $empty = [];
        foreach ($periods as $period) {
            $inside = array_filter($rows, fn (array $r) => substr($r['created'], 0, 10) >= $period['from'] && substr($r['created'], 0, 10) <= $period['to']);
            if ($inside === []) {
                $empty[] = $period['report_date'];
            }
        }

        $log = count($items).' payment(s) from the API for '.$from->toDateString().' – '.$until->subDay()->toDateString().', '.count($rows).' kept';
        if ($rows === []) {
            return new BotResult(BotRunStatus::Succeeded, rows: 0, reportDate: $target->period->reportDate, log: $log, emptyDates: $empty);
        }

        $directory = Storage::disk('local')->path("bots/{$run->id}");
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $file = $directory.'/corefy_api_'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $account->username).'_'.$target->period->reportDate->toDateString().'.csv';
        $handle = fopen($file, 'wb');
        fputcsv($handle, self::COLUMNS, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', self::COLUMNS), escape: '');
        }
        fclose($handle);

        return new BotResult(BotRunStatus::Succeeded, files: [$file], rows: count($rows), reportDate: $target->period->reportDate, log: $log, emptyDates: $empty);
    }

    /**
     * What the API gives for the account: the keys of one payment, to check the field names before relying on it.
     *
     * @return array{count: int, attributes: list<string>, payload: list<string>, sample: array<string, mixed>}
     */
    public function probe(IntegrationAccount $account, int $hours = 24): array
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $items = $this->fetch($account, $now - $hours * 3600, $now, 3);
        $first = $items[0] ?? null;
        $attributes = (array) ($first['attributes'] ?? []);

        return [
            'count' => count($items),
            'attributes' => array_keys($attributes),
            'payload' => array_keys((array) ($attributes['payload'] ?? [])),
            'sample' => $first === null ? [] : [
                'id' => $first['id'] ?? null,
                'created' => $attributes['created'] ?? $attributes['created_at'] ?? null,
                'status' => $attributes['status'] ?? null,
                'resolution' => $attributes['resolution'] ?? null,
                'amount' => $attributes['amount'] ?? null,
                'currency' => $attributes['currency'] ?? null,
            ],
        ];
    }

    /**
     * Every payment created in [gte, lte] (Unix seconds), all pages.
     *
     * @return list<array<string, mixed>>
     */
    private function fetch(IntegrationAccount $account, int $gte, int $lte, ?int $limit = null): array
    {
        $base = rtrim((string) $account->setting('api_base_url', self::DEFAULT_URL), '/');
        $url = $base.'/payment-invoices';
        $query = [
            'filter[created][gte]' => $gte,
            'filter[created][lte]' => $lte,
            'sort' => '-created',
            'page[size]' => $limit ?? self::PAGE_SIZE,
        ];

        $items = [];
        for ($page = 1; $url !== null && $page <= self::MAX_PAGES; $page++) {
            try {
                $response = Http::withBasicAuth((string) $account->username, (string) $account->password)
                    ->withHeaders(['Accept' => 'application/vnd.api+json'])
                    ->timeout(60)->retry(2, 500, throw: false)
                    ->get($url, $page === 1 ? $query : []);
            } catch (ConnectionException $e) {
                throw new ApiFailure('Corefy API is not reachable: '.mb_substr($e->getMessage(), 0, 150));
            }

            if (! $response->successful()) {
                // Never the key: only what the API said about the request.
                throw new ApiFailure("Corefy API answered HTTP {$response->status()} for {$account->username}: ".mb_substr(strip_tags($response->body()), 0, 200));
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
    private function row(array $item, string $account, string $zone): ?array
    {
        $a = (array) ($item['attributes'] ?? []);
        $created = $this->time($a['created'] ?? $a['created_at'] ?? null, $zone);
        if ($created === null || ! isset($item['id'])) {
            return null;
        }
        $card = (array) (($a['payload']['payment_card'] ?? null) ?: []);

        return [
            'created' => $created,
            'updated' => $this->time($a['updated'] ?? $a['updated_at'] ?? null, $zone) ?? '',
            'id' => (string) $item['id'],
            'reference_id' => (string) ($a['reference_id'] ?? ''),
            'amount' => (string) ($a['amount'] ?? ''),
            'currency' => (string) ($a['currency'] ?? ''),
            'status' => (string) ($a['status'] ?? ''),
            'account' => $account,
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
        } catch (\Throwable) {
            return null;
        }

        return $at->setTimezone($zone)->format('Y-m-d H:i:s');
    }
}
