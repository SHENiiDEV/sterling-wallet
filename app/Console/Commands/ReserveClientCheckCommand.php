<?php

namespace App\Console\Commands;

use App\Enums\ReportStatus;
use App\Models\Client;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * A client's own rolling reserve table (one sheet per terminal; MID, start, end, processed amount, reserve) lined up
 * with our finished reports of the same MID over the same days. Nothing is changed.
 */
#[Signature('reserve:client-check {file : The client\'s xlsx} {--client= : Client id} {--map=* : Sheet=MID pairs when the sheet name is not our MID (e.g. CySLCf-01=6741557)} {--tolerance=0.05 : Differences up to this are not marked}')]
#[Description('Compare a client\'s own rolling reserve table with our reports of the same MIDs (read only)')]
class ReserveClientCheckCommand extends Command
{
    public function handle(): int
    {
        $client = Client::query()->find($this->option('client'));
        if ($client === null) {
            $this->error('Give --client=<client id>.');

            return self::FAILURE;
        }
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        try {
            $book = IOFactory::load($file);
        } catch (Throwable $e) {
            $this->error('The file cannot be read: '.$e->getMessage());

            return self::FAILURE;
        }

        $mids = MerchantMid::query()->whereHas('merchant.company', fn ($q) => $q->where('client_id', $client->id))->get()->keyBy('mid');
        $map = [];
        foreach ((array) $this->option('map') as $pair) {
            [$sheet, $mid] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $map[trim($sheet)] = trim($mid);
        }
        $tolerance = BigDecimal::of((string) $this->option('tolerance'));

        $this->info("Client #{$client->id} {$client->name}: ".$mids->count().' MID(s): '.$mids->keys()->implode(', '));

        foreach ($book->getWorksheetIterator() as $sheet) {
            $rows = $sheet->toArray(null, true, false, false);
            array_shift($rows);
            $rows = array_values(array_filter($rows, fn ($r) => isset($r[0], $r[1], $r[2], $r[3], $r[4]) && is_numeric($r[3]) && is_numeric($r[4])));
            if ($rows === []) {
                continue;
            }

            $title = $sheet->getTitle();
            $number = $map[$title] ?? $this->midFromNotes($sheet->toArray(null, true, false, false), $mids->keys()->all());
            $mid = $number === null ? null : $mids->get($number);
            if ($mid === null) {
                $this->warn("Sheet {$title}: no MID of this client found for it ({$number}). Give --map={$title}=<MID>.");

                continue;
            }

            $this->newLine();
            $this->info("Sheet {$title} → MID {$mid->mid} ({$mid->merchant?->name}), reserve {$mid->merchant?->rolling_reserve_percent}%");

            $table = [];
            $totals = ['their' => BigDecimal::zero(), 'turnover' => BigDecimal::zero(), 'theirRr' => BigDecimal::zero(), 'ourRr' => BigDecimal::zero()];
            foreach ($rows as $row) {
                $from = $this->day($row[1]);
                $to = $this->day($row[2]);
                $their = BigDecimal::of((string) $row[3]);
                $theirRr = BigDecimal::of((string) $row[4]);
                // "25.593" is 25,593 written with a thousands point: the processed amount cannot be below the reserve.
                $scaled = $their->isLessThan($theirRr);
                if ($scaled) {
                    $their = $their->multipliedBy(1000);
                }

                $reports = DailyReportTask::query()
                    ->where('merchant_mid_id', $mid->id)->where('status', ReportStatus::Completed)
                    ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])->get();
                $turnover = $reports->reduce(fn (BigDecimal $c, DailyReportTask $r) => $c->plus((string) $r->getRawOriginal('turnover')), BigDecimal::zero());
                $ourRr = $reports->reduce(fn (BigDecimal $c, DailyReportTask $r) => $c->plus((string) $r->getRawOriginal('reserve_amount')), BigDecimal::zero());
                $base = $reports->first()?->summary_data['merchant_fee']['reserve_base'] ?? '';

                $diffProcessed = $turnover->minus($their);
                $diffRr = $ourRr->minus($theirRr);
                $mark = fn (BigDecimal $d) => $d->abs()->isGreaterThan($tolerance) ? ' ◄' : '';

                $table[] = [
                    $from->format('d.m').($to->equalTo($from) ? '' : '–'.$to->format('d.m')),
                    $their->toScale(2).($scaled ? '*' : ''), $reports->isEmpty() ? '—' : $turnover->toScale(2), $reports->isEmpty() ? '—' : $this->signed($diffProcessed).$mark($diffProcessed),
                    $theirRr->toScale(2), $reports->isEmpty() ? '—' : $ourRr->toScale(2), $reports->isEmpty() ? '—' : $this->signed($diffRr).$mark($diffRr),
                    $their->isZero() ? '' : $theirRr->multipliedBy(100)->dividedBy($their, 2, RoundingMode::HalfUp).'%',
                    $reports->isEmpty() ? 'no report' : ($base === 'processing' ? 'on sales' : 'after fees'),
                ];

                $totals['their'] = $totals['their']->plus($their);
                $totals['turnover'] = $totals['turnover']->plus($turnover);
                $totals['theirRr'] = $totals['theirRr']->plus($theirRr);
                $totals['ourRr'] = $totals['ourRr']->plus($ourRr);
            }

            $this->table(['Period', 'Their processed', 'Our turnover', 'Diff', 'Their reserve', 'Our reserve', 'Diff', 'Their %', 'Our reserve base'], $table);
            $this->line(sprintf(
                'Total: processed %s (ours %s), reserve %s (ours %s, difference %s). * = written with a thousands point, read as ×1000.',
                $totals['their']->toScale(2), $totals['turnover']->toScale(2), $totals['theirRr']->toScale(2), $totals['ourRr']->toScale(2), $this->signed($totals['ourRr']->minus($totals['theirRr'])),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * The sheet's own notes may name our daily report ("Daily report 6741557_2026-11-09"): the MID is in it.
     *
     * @param  list<list<mixed>>  $cells
     * @param  list<string>  $known
     */
    private function midFromNotes(array $cells, array $known): ?string
    {
        foreach ($cells as $row) {
            foreach ($row as $cell) {
                if (is_string($cell) && preg_match('/Daily report (.+?)_\d{4}-\d{2}-\d{2}/', $cell, $m) && in_array($m[1], $known, true)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    private function day(mixed $value): CarbonImmutable
    {
        return is_numeric($value)
            ? CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay()
            : CarbonImmutable::parse((string) $value)->startOfDay();
    }

    private function signed(BigDecimal $value): string
    {
        return ($value->isPositive() ? '+' : '').$value->toScale(2, RoundingMode::HalfUp);
    }
}
