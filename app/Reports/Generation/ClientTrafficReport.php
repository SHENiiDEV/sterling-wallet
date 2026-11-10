<?php

namespace App\Reports\Generation;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Reports\Parsers\HeaderMappedParser;
use App\Reports\Parsers\TabularReader;
use App\Reports\Parsers\Values;
use App\Support\PdfRenderer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SplFileInfo;

/**
 * Traffic of a client over a period, from what the gateway saw: every attempt, how many succeeded, why the rest
 * did not, by day, scheme, issuer country and terminal. Routing errors (the payment never reached a bank) are not
 * attempts of the client and are left out of every figure. Nothing about our costs or fees.
 */
class ClientTrafficReport
{
    private const COUNTRIES = 10;

    public function render(Client $client, CarbonImmutable $from, CarbonImmutable $to, ?int $companyId = null): string
    {
        return PdfRenderer::render('reports.client-traffic', $this->data($client, $from, $to, $companyId));
    }

    /**
     * @param  array<string, mixed>  $data  from data() or dataFromFile()
     */
    public function renderData(array $data): string
    {
        return PdfRenderer::render('reports.client-traffic', $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Client $client, CarbonImmutable $from, CarbonImmutable $to, ?int $companyId = null): array
    {
        $scope = $companyId === null ? null : Company::query()->where('client_id', $client->id)->findOrFail($companyId);
        $companyIds = $scope === null ? Company::query()->where('client_id', $client->id)->pluck('id')->all() : [$scope->id];
        $merchantIds = Merchant::query()->whereIn('company_id', $companyIds)->pluck('id')->all();

        $zone = config('sterling.timezone');
        $start = CarbonImmutable::parse($from->toDateString(), $zone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to->toDateString(), $zone)->endOfDay()->utc();

        $rows = MerchantOperation::query()->toBase()
            ->where('role', ProviderType::Gate->value)
            ->whereIn('operation_type', [OperationType::Sale->value, OperationType::Decline->value])
            ->whereIn('merchant_id', $merchantIds)
            ->whereBetween('transaction_at', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')])
            ->get(['merchant_mid_id', 'operation_type', 'resolution', 'amount', 'currency', 'ips', 'issuer_country', 'transaction_at'])
            ->map(fn ($row) => [
                'success' => $row->operation_type === OperationType::Sale->value,
                'resolution' => $row->resolution,
                'reason' => $row->resolution,
                'amount' => BigDecimal::of((string) $row->amount)->abs(),
                'currency' => $row->currency,
                'day' => CarbonImmutable::parse($row->transaction_at, 'UTC')->setTimezone($zone)->toDateString(),
                'scheme' => $row->ips,
                'country' => $row->issuer_country,
                'mid' => $row->merchant_mid_id,
            ]);

        return [
            'title' => $client->invoiceName().($scope ? ' · '.$scope->name : ''),
            'from' => $from,
            'to' => $to,
            ...$this->aggregate($rows),
        ];
    }

    /**
     * The same report from a Corefy payments export (CSV or XLSX): upload it, get the report. Nothing is stored.
     *
     * @return array<string, mixed>
     */
    public function dataFromFile(SplFileInfo $file, ?string $title = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $rows = collect($this->fileRows($file, $names));
        if ($from !== null || $to !== null) {
            $rows = $rows->filter(fn (array $r) => ($from === null || $r['day'] >= $from->toDateString()) && ($to === null || $r['day'] <= $to->toDateString()))->values();
        }
        if ($rows->isEmpty()) {
            throw new InvalidArgumentException('No payments found in this file for the period.');
        }

        $days = $rows->pluck('day');

        return [
            'title' => trim((string) $title) !== '' ? trim((string) $title) : (collect($names)->countBy()->sortDesc()->keys()->first() ?? 'Traffic'),
            'from' => CarbonImmutable::parse($from?->toDateString() ?? $days->min()),
            'to' => CarbonImmutable::parse($to?->toDateString() ?? $days->max()),
            ...$this->aggregate($rows),
        ];
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows  success, resolution, reason, amount, currency, day, scheme, country, mid
     * @return array<string, mixed>
     */
    private function aggregate(iterable $rows): array
    {
        $ignored = array_map('strtolower', (array) config('sterling.traffic_report.ignore_resolutions', ['routing_error']));

        $totals = $this->bucket();
        $days = [];
        $reasons = [];
        $schemes = [];
        $countries = [];
        $mids = [];
        $routingErrors = 0;

        foreach ($rows as $row) {
            if (in_array(strtolower(trim((string) $row['resolution'])), $ignored, true)) {
                $routingErrors++;

                continue;
            }

            $this->add($totals, $row['success'], $row['amount'], $row['currency']);
            $this->add($days[$row['day']], $row['success'], $row['amount'], $row['currency']);
            $this->add($schemes[$row['scheme'] ?: 'other'], $row['success'], $row['amount'], $row['currency']);
            $this->add($countries[$row['country'] ?: '—'], $row['success'], $row['amount'], $row['currency']);
            if ($row['mid'] !== null) {
                $this->add($mids[$row['mid']], $row['success'], $row['amount'], $row['currency']);
            }
            if (! $row['success']) {
                $reason = $this->reason($row['reason']);
                $reasons[$reason]['count'] = ($reasons[$reason]['count'] ?? 0) + 1;
                $reasons[$reason]['amount'][$row['currency'] ?? '—'] = ($reasons[$reason]['amount'][$row['currency'] ?? '—'] ?? BigDecimal::zero())->plus($row['amount']);
            }
        }

        ksort($days);
        $declined = $totals['declined'];

        return [
            'totals' => $this->finish($totals),
            'average' => $totals['successful'] > 0 ? $this->average($totals) : [],
            'routingErrors' => $routingErrors,
            'days' => array_map(fn (array $b) => $this->finish($b), $days),
            'reasons' => collect($reasons)
                ->map(fn (array $r, string $reason) => ['reason' => $reason, 'count' => $r['count'], 'share' => $declined > 0 ? round($r['count'] / $declined * 100, 1) : 0.0, 'amount' => $r['amount']])
                ->sortByDesc('count')->values()->all(),
            'schemes' => collect($schemes)->map(fn (array $b, string $name) => ['name' => $name, ...$this->finish($b)])->sortByDesc('attempts')->values()->all(),
            'countries' => collect($countries)->map(fn (array $b, string $name) => ['name' => $name, ...$this->finish($b)])->sortByDesc('attempts')->take(self::COUNTRIES)->values()->all(),
            'mids' => $this->midRows($mids),
            'generatedAt' => now()->timezone(config('sterling.timezone')),
        ];
    }

    /**
     * Payment attempts of a Corefy export, one array per payment worth counting: successful (processed, refunded) and
     * failed ones. Invoices that were never paid or tried (created, expired, pending) are not attempts.
     *
     * @param  list<string>|null  $names  commerce names seen, for the title
     * @return list<array<string, mixed>>
     */
    private function fileRows(SplFileInfo $file, ?array &$names = null): array
    {
        $names = [];
        $columns = null;
        $out = [];

        foreach ((new TabularReader)->rows($file) as $cells) {
            if ($columns === null) {
                $normalized = array_map(fn ($c) => HeaderMappedParser::normalizeHeader((string) $c), $cells);
                $found = [];
                foreach (['status', 'resolution', 'amount', 'currency', 'created', 'cardmask', 'payer', 'issuercountry', 'originalresolutionmessage', 'commercename'] as $name) {
                    $index = array_search($name, $normalized, true);
                    if ($index !== false) {
                        $found[$name] = $index;
                    }
                }
                if (isset($found['status'], $found['resolution'], $found['amount'], $found['created'])) {
                    $columns = $found;
                }

                continue;
            }

            $get = fn (string $name) => isset($columns[$name]) ? ($cells[$columns[$name]] ?? null) : null;
            $status = strtolower(trim((string) $get('status')));
            $success = in_array($status, ['processed', 'refunded'], true);
            if (! $success && $status !== 'process_failed') {
                continue;
            }

            $amount = Values::decimal($get('amount'));
            $day = $this->day($get('created'));
            if ($amount === null || $day === null) {
                continue;
            }

            [, $bin] = Values::card($get('cardmask') ?? $get('payer'));
            $resolution = Values::text($get('resolution'));
            $name = Values::text($get('commercename'));
            if ($name !== null) {
                $names[] = $name;
            }

            $out[] = [
                'success' => $success,
                'resolution' => $resolution,
                'reason' => Values::text($get('originalresolutionmessage')) ?? $resolution,
                'amount' => BigDecimal::of($amount)->abs(),
                'currency' => Values::currency($get('currency')),
                'day' => $day,
                'scheme' => Values::ips(null, $bin),
                'country' => Values::country($get('issuercountry')),
                'mid' => null,
            ];
        }

        if ($columns === null) {
            throw new InvalidArgumentException('This does not look like a Corefy payments export: it needs the columns status, resolution, amount and created.');
        }

        return $out;
    }

    /**
     * The day of a "created" cell: text like 2026-11-08 23:51:47.003 or an Excel date number.
     */
    private function day(mixed $value): ?string
    {
        $text = trim((string) $value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $m) === 1) {
            return $m[1];
        }
        if (is_numeric($text) && (float) $text > 20000) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $text))->toDateString();
        }

        return null;
    }

    public function xlsx(Client $client, CarbonImmutable $from, CarbonImmutable $to, ?int $companyId = null): string
    {
        return $this->xlsxData($this->data($client, $from, $to, $companyId));
    }

    /**
     * @param  array<string, mixed>  $data  from data() or dataFromFile()
     */
    public function xlsxData(array $data): string
    {
        $from = $data['from'];
        $to = $data['to'];
        $money = fn (array $amounts) => collect($amounts)->map(fn (BigDecimal $v, string $c) => PdfRenderer::money($v, $c))->implode(' · ');

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Summary');
        $sheet->fromArray([
            [$data['title'].' — traffic '.$from->toDateString().' – '.$to->toDateString()],
            [],
            ['Attempts', $data['totals']['attempts']],
            ['Successful', $data['totals']['successful']],
            ['Declined', $data['totals']['declined']],
            ['Approval rate, %', $data['totals']['rate']],
            ['Successful amount', $money($data['totals']['amount'])],
            ['Average ticket', $money($data['average'])],
            ['Routing errors (not counted)', $data['routingErrors']],
        ]);

        $this->sheet($book, 'By day', ['Day', 'Attempts', 'Successful', 'Declined', 'Approval, %', 'Successful amount'],
            array_map(fn (string $day, array $d) => [$day, $d['attempts'], $d['successful'], $d['declined'], $d['rate'], $money($d['amount'])], array_keys($data['days']), $data['days']));
        $this->sheet($book, 'Declines', ['Reason', 'Declined', '% of declines', 'Amount'],
            array_map(fn (array $r) => [$r['reason'], $r['count'], $r['share'], $money($r['amount'])], $data['reasons']));
        $this->sheet($book, 'Scheme', ['Scheme', 'Attempts', 'Successful', 'Declined', 'Approval, %'],
            array_map(fn (array $s) => [$s['name'], $s['attempts'], $s['successful'], $s['declined'], $s['rate']], $data['schemes']));
        $this->sheet($book, 'Issuer country', ['Country', 'Attempts', 'Successful', 'Declined', 'Approval, %'],
            array_map(fn (array $s) => [$s['name'], $s['attempts'], $s['successful'], $s['declined'], $s['rate']], $data['countries']));
        if (count($data['mids']) > 1) {
            $this->sheet($book, 'MID', ['MID', 'Merchant', 'Attempts', 'Successful', 'Declined', 'Approval, %', 'Successful amount'],
                array_map(fn (array $m) => [$m['mid'], $m['merchant'], $m['attempts'], $m['successful'], $m['declined'], $m['rate'], $money($m['amount'])], $data['mids']));
        }

        foreach ($book->getAllSheets() as $one) {
            foreach (range('A', 'G') as $column) {
                $one->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'trf');
        (new Xlsx($book))->save($path);
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<mixed>>  $rows
     */
    private function sheet(Spreadsheet $book, string $title, array $header, array $rows): void
    {
        $book->createSheet()->setTitle($title)->fromArray([$header, ...$rows]);
    }

    /**
     * @return array{attempts: int, successful: int, declined: int, amount: array<string, BigDecimal>}
     */
    private function bucket(): array
    {
        return ['attempts' => 0, 'successful' => 0, 'declined' => 0, 'amount' => []];
    }

    /**
     * @param  array<string, mixed>|null  $bucket
     */
    private function add(?array &$bucket, bool $success, BigDecimal $amount, ?string $currency): void
    {
        $bucket ??= $this->bucket();
        $bucket['attempts']++;
        if ($success) {
            $bucket['successful']++;
            $bucket['amount'][$currency ?? '—'] = ($bucket['amount'][$currency ?? '—'] ?? BigDecimal::zero())->plus($amount);
        } else {
            $bucket['declined']++;
        }
    }

    /**
     * @param  array{attempts: int, successful: int, declined: int, amount: array<string, BigDecimal>}  $bucket
     * @return array{attempts: int, successful: int, declined: int, rate: float, amount: array<string, BigDecimal>}
     */
    private function finish(array $bucket): array
    {
        ksort($bucket['amount']);

        return [...$bucket, 'rate' => $bucket['attempts'] > 0 ? round($bucket['successful'] / $bucket['attempts'] * 100, 1) : 0.0];
    }

    /**
     * @param  array{successful: int, amount: array<string, BigDecimal>}  $bucket
     * @return array<string, BigDecimal>
     */
    private function average(array $bucket): array
    {
        return array_map(fn (BigDecimal $v) => $v->dividedBy($bucket['successful'], 2, RoundingMode::HalfUp), $bucket['amount']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mids
     * @return list<array<string, mixed>>
     */
    private function midRows(array $mids): array
    {
        $names = MerchantMid::query()->with('merchant:id,name')->whereIn('id', array_keys($mids))->get()->keyBy('id');

        return collect($mids)
            ->map(fn (array $b, int $id) => ['mid' => $names[$id]->mid ?? "#{$id}", 'merchant' => $names[$id]->merchant->name ?? '', ...$this->finish($b)])
            ->sortByDesc('attempts')->values()->all();
    }

    private function reason(?string $resolution): string
    {
        $text = trim((string) $resolution);

        return $text === '' ? 'unknown' : strtolower(str_replace('_', ' ', $text));
    }
}
