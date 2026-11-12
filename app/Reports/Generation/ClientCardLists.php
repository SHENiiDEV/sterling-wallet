<?php

namespace App\Reports\Generation;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantOperation;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The cards of a client over a period, as masks (first six digits, *, last four: 123456*7890), from the gateway's
 * attempts: how many times each card paid and how many times it was declined, and why.
 *
 * White lists: cards with at least N successful payments (1, 3, 5, 7, 10). Black list: cards that were declined again
 * and again and never paid, with the insufficient-funds ones apart. Routing errors never reached a bank and are left out.
 */
class ClientCardLists
{
    public const BLACK = 'black';

    public const INSUFFICIENT = 'insufficient';

    /**
     * @return array{title: string, from: CarbonImmutable, to: CarbonImmutable, cards: list<array<string, mixed>>, tiers: list<int>, min_declines: int, lists: array<string, list<array<string, mixed>>>}
     */
    public function data(Client $client, CarbonImmutable $from, CarbonImmutable $to, ?int $companyId = null): array
    {
        $scope = $companyId === null ? null : Company::query()->where('client_id', $client->id)->findOrFail($companyId);
        $companyIds = $scope === null ? Company::query()->where('client_id', $client->id)->pluck('id')->all() : [$scope->id];
        $merchantIds = Merchant::query()->whereIn('company_id', $companyIds)->pluck('id')->all();

        $zone = config('sterling.timezone');
        $start = CarbonImmutable::parse($from->toDateString(), $zone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to->toDateString(), $zone)->endOfDay()->utc();
        $ignored = array_map('strtolower', (array) config('sterling.traffic_report.ignore_resolutions', ['routing_error']));

        $rows = MerchantOperation::query()->toBase()
            ->where('role', ProviderType::Gate->value)
            ->whereIn('operation_type', [OperationType::Sale->value, OperationType::Decline->value])
            ->whereIn('merchant_id', $merchantIds)
            ->whereNotNull('card_bin')->whereNotNull('card_last4')
            ->whereBetween('transaction_at', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')])
            ->selectRaw('card_bin, card_last4, operation_type, resolution, count(*) as total, min(transaction_at) as first_at, max(transaction_at) as last_at')
            ->groupBy('card_bin', 'card_last4', 'operation_type', 'resolution')
            ->get();

        $cards = [];
        foreach ($rows as $row) {
            $resolution = strtolower(trim((string) $row->resolution));
            if (in_array($resolution, $ignored, true)) {
                continue;
            }

            $mask = $row->card_bin.'*'.$row->card_last4;
            $card = &$cards[$mask];
            $card ??= ['mask' => $mask, 'successes' => 0, 'declines' => 0, 'insufficient' => 0, 'reasons' => [], 'first_at' => null, 'last_at' => null];
            $count = (int) $row->total;

            if ($row->operation_type === OperationType::Sale->value) {
                $card['successes'] += $count;
            } else {
                $reason = $resolution === '' ? 'unknown' : str_replace('_', ' ', $resolution);
                $card['declines'] += $count;
                $card['reasons'][$reason] = ($card['reasons'][$reason] ?? 0) + $count;
                if (str_contains($reason, 'insufficient')) {
                    $card['insufficient'] += $count;
                }
            }
            $card['first_at'] = $card['first_at'] === null || $row->first_at < $card['first_at'] ? $row->first_at : $card['first_at'];
            $card['last_at'] = $card['last_at'] === null || $row->last_at > $card['last_at'] ? $row->last_at : $card['last_at'];
            unset($card);
        }

        $cards = collect($cards)->map(function (array $c) use ($zone) {
            arsort($c['reasons']);
            $attempts = $c['successes'] + $c['declines'];

            return [
                'mask' => $c['mask'],
                'attempts' => $attempts,
                'successes' => $c['successes'],
                'declines' => $c['declines'],
                'approval' => $attempts > 0 ? round($c['successes'] / $attempts * 100, 1) : null,
                'top_reason' => array_key_first($c['reasons']) ?? '',
                'top_reason_count' => $c['reasons'] === [] ? 0 : max($c['reasons']),
                'insufficient' => $c['insufficient'],
                'first_seen' => CarbonImmutable::parse($c['first_at'], 'UTC')->setTimezone($zone)->toDateString(),
                'last_seen' => CarbonImmutable::parse($c['last_at'], 'UTC')->setTimezone($zone)->toDateString(),
            ];
        })->sortBy([['successes', 'desc'], ['attempts', 'desc'], ['mask', 'asc']])->values();

        $tiers = array_map('intval', (array) config('sterling.card_lists.white_tiers', [1, 3, 5, 7, 10]));
        $minDeclines = (int) config('sterling.card_lists.blacklist_min_declines', 3);

        $lists = [];
        foreach ($tiers as $tier) {
            $lists['white'.$tier] = $cards->where('successes', '>=', $tier)->values()->all();
        }
        $black = $cards->filter(fn (array $c) => $c['successes'] === 0 && $c['declines'] >= $minDeclines)->sortByDesc('declines')->values();
        $lists[self::BLACK] = $black->all();
        $lists[self::INSUFFICIENT] = $black->filter(fn (array $c) => str_contains($c['top_reason'], 'insufficient'))->values()->all();

        return [
            'title' => $client->invoiceName().($scope ? ' · '.$scope->name : ''),
            'from' => $from,
            'to' => $to,
            'cards' => $cards->all(),
            'tiers' => $tiers,
            'min_declines' => $minDeclines,
            'lists' => $lists,
        ];
    }

    /**
     * Every list as a sheet of one workbook.
     *
     * @param  array<string, mixed>  $data  from data()
     */
    public function xlsx(array $data): string
    {
        $header = ['Card', 'Attempts', 'Successful', 'Declined', 'Approval, %', 'Main decline reason', 'Times', 'Insufficient funds', 'First seen', 'Last seen'];
        $line = fn (array $c) => [$c['mask'], $c['attempts'], $c['successes'], $c['declines'], $c['approval'], $c['top_reason'], $c['top_reason_count'], $c['insufficient'], $c['first_seen'], $c['last_seen']];

        $book = new Spreadsheet;
        $summary = $book->getActiveSheet()->setTitle('Summary');
        $summary->fromArray([
            [$data['title'].' — cards '.$data['from']->toDateString().' – '.$data['to']->toDateString()],
            [],
            ['Cards', count($data['cards'])],
            ...array_map(fn (int $t) => ["White list {$t}+ successful payments", count($data['lists']['white'.$t])], $data['tiers']),
            ["Black list: never paid, {$data['min_declines']}+ declines", count($data['lists'][self::BLACK])],
            ['…of them mostly insufficient funds', count($data['lists'][self::INSUFFICIENT])],
            [],
            ['A card is shown as its first six digits, *, and its last four. Routing errors are not counted.'],
        ]);

        $sheets = ['All cards' => $data['cards']];
        foreach ($data['tiers'] as $tier) {
            $sheets["White {$tier}+"] = $data['lists']['white'.$tier];
        }
        $sheets['Black list'] = $data['lists'][self::BLACK];
        $sheets['Insufficient funds'] = $data['lists'][self::INSUFFICIENT];

        foreach ($sheets as $title => $cards) {
            $book->createSheet()->setTitle($title)->fromArray([$header, ...array_map($line, $cards)]);
        }

        $path = tempnam(sys_get_temp_dir(), 'crd');
        (new Xlsx($book))->save($path);
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * One list as CSV: the card masks with their counts, ready to upload as a white or black list.
     *
     * @param  array<string, mixed>  $data  from data()
     */
    public function csv(array $data, string $list): string
    {
        $cards = $list === 'all' ? $data['cards'] : ($data['lists'][$list] ?? []);
        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, ['card', 'attempts', 'successful', 'declined', 'approval_percent', 'main_decline_reason', 'first_seen', 'last_seen'], escape: '');
        foreach ($cards as $c) {
            fputcsv($handle, [$c['mask'], $c['attempts'], $c['successes'], $c['declines'], $c['approval'], $c['top_reason'], $c['first_seen'], $c['last_seen']], escape: '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @return list<string> the lists a CSV can be made of
     */
    public static function lists(): array
    {
        return ['all', ...array_map(fn ($t) => 'white'.(int) $t, (array) config('sterling.card_lists.white_tiers', [1, 3, 5, 7, 10])), self::BLACK, self::INSUFFICIENT];
    }
}
