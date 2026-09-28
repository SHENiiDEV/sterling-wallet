<?php

namespace App\Reports\Parsers;

/**
 * Madfin acquirer report. Column names live in config('sterling.parsers.madfin')
 * and should be checked against the first real file.
 */
class MadfinParser extends HeaderMappedParser
{
    public function format(): string
    {
        return 'madfin';
    }
}
