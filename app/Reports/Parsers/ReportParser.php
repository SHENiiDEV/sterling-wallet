<?php

namespace App\Reports\Parsers;

use Carbon\CarbonImmutable;
use SplFileInfo;

/**
 * Reads one provider's report format. Adding a provider = one implementation
 * registered in ParserRegistry, selected by `providers.report_format`.
 */
interface ReportParser
{
    public function supports(string $format): bool;

    /**
     * The business date the file itself declares (e.g. Cardaq's
     * "Processing date(s)" line), or null when the file doesn't say.
     */
    public function reportDate(SplFileInfo $file): ?CarbonImmutable;

    /**
     * @param  string  $timezone  zone of the times in the file
     * @return iterable<OperationRow>
     */
    public function rows(SplFileInfo $file, string $timezone): iterable;
}
