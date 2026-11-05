<?php

namespace App\Reports\Parsers;

/**
 * Madfin acquirer report: an xlsx whose operations are on the "Transactions"
 * sheet (the "Main" sheet is their settlement statement, "RR" the reserve).
 * Column names live in config('sterling.parsers.madfin').
 */
class MadfinParser extends HeaderMappedParser
{
    protected const SHEETS = ['Transactions'];

    public function format(): string
    {
        return 'madfin';
    }

    protected function adjust(array $attributes, callable $get): ?array
    {
        // The portal export also lists payments the customer cancelled: they never reached the bank.
        if (in_array(strtolower((string) $attributes['resolution']), ['cancelled', 'canceled'], true)) {
            return null;
        }

        return $attributes;
    }
}
