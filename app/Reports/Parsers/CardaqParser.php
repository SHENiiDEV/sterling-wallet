<?php

namespace App\Reports\Parsers;

use Carbon\CarbonImmutable;
use SplFileInfo;

/**
 * Cardaq clearing report (CSV or XLSX). The business date comes from the
 * "Processing date(s)" line above the table, e.g. `2026.08.07-2026.08.09`
 * for a weekend — the report belongs to the last day of that range.
 */
class CardaqParser extends HeaderMappedParser
{
    public function format(): string
    {
        return 'cardaq';
    }

    public function reportDate(SplFileInfo $file): ?CarbonImmutable
    {
        $scanned = 0;
        foreach ($this->reader->rows($file) as $cells) {
            if (++$scanned > static::HEADER_SCAN_ROWS) {
                break;
            }

            $line = implode(' ', array_filter(array_map(fn ($c) => Values::text($c), $cells)));
            if (preg_match('/processing\s*date/i', $line) && ($range = Values::dateRange($line)) !== null) {
                return $range[1];
            }
        }

        return null;
    }
}
