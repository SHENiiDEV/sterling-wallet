<?php

namespace App\Reports\Generation;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Support\PdfRenderer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
        $ignored = array_map('strtolower', (array) config('sterling.traffic_report.ignore_resolutions', ['routing_error']));

        $rows = MerchantOperation::query()->toBase()
            ->where('role', ProviderType::Gate->value)
            ->whereIn('operation_type', [OperationType::Sale->value, OperationType::Decline->value])
            ->whereIn('merchant_id', $merchantIds)
            ->whereBetween('transaction_at', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')])
            ->get(['merchant_id', 'merchant_mid_id', 'operation_type', 'resolution', 'amount', 'currency', 'ips', 'issuer_country', 'transaction_at']);

        $totals = $this->bucket();
        $days = [];
        $reasons = [];
        $schemes = [];
        $countries = [];
        $mids = [];
        $routingErrors = 0;

        foreach ($rows as $row) {
            $reason = $this->reason($row->resolution);
            if (in_array(strtolower(trim((string) $row->resolution)), $ignored, true)) {
                $routingErrors++;

                continue;
            }

            $success = $row->operation_type === OperationType::Sale->value;
            $amount = BigDecimal::of((string) $row->amount)->abs();
            $day = CarbonImmutable::parse($row->transaction_at, 'UTC')->setTimezone($zone)->toDateString();

            $this->add($totals, $success, $amount, $row->currency);
            $this->add($days[$day], $success, $amount, $row->currency);
            $this->add($schemes[$row->ips ?: 'other'], $success, $amount, $row->currency);
            $this->add($countries[$row->issuer_country ?: '—'], $success, $amount, $row->currency);
            $this->add($mids[$row->merchant_mid_id], $success, $amount, $row->currency);
            if (! $success) {
                $reasons[$reason]['count'] = ($reasons[$reason]['count'] ?? 0) + 1;
                $reasons[$reason]['amount'][$row->currency] = ($reasons[$reason]['amount'][$row->currency] ?? BigDecimal::zero())->plus($amount);
            }
        }

        ksort($days);
        $declined = $totals['declined'];

        return [
            'client' => $client,
            'scope' => $scope,
            'from' => $from,
            'to' => $to,
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
            'generatedAt' => now()->timezone($zone),
        ];
    }

    public function xlsx(Client $client, CarbonImmutable $from, CarbonImmutable $to, ?int $companyId = null): string
    {
        $data = $this->data($client, $from, $to, $companyId);
        $money = fn (array $amounts) => collect($amounts)->map(fn (BigDecimal $v, string $c) => PdfRenderer::money($v, $c))->implode(' · ');

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Summary');
        $sheet->fromArray([
            [$client->invoiceName().($data['scope'] ? ' · '.$data['scope']->name : '').' — traffic '.$from->toDateString().' – '.$to->toDateString()],
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
