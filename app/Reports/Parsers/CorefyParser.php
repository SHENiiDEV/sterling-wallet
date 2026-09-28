<?php

namespace App\Reports\Parsers;

/**
 * Corefy (Paycore) payment-invoices export. Its `status` column
 * (processed / process_failed / expired …) feeds classification through
 * `processing_code`; `resolution` carries the decline reason.
 */
class CorefyParser extends HeaderMappedParser
{
    public function format(): string
    {
        return 'corefy';
    }

    protected function adjust(array $attributes, callable $get): ?array
    {
        $status = strtolower((string) $attributes['processingCode']);

        // Invoices still in flight are not operations yet.
        if (in_array($status, ['created', 'processing', 'pending', 'new'], true)) {
            return null;
        }

        if ($attributes['resolution'] === null && $status !== '' && $status !== 'processed') {
            $attributes['resolution'] = $status;
        }

        $attributes['paymentId'] ??= Values::text($get('reference_id'));

        return $attributes;
    }
}
