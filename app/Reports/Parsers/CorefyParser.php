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
        if (in_array($status, ['created', 'processing', 'pending', 'process_pending', 'new'], true)) {
            return null;
        }

        if ($attributes['resolution'] === null && $status !== '' && $status !== 'processed') {
            $attributes['resolution'] = $status;
        }

        // The invoice id (cpi_…) is the payment id. Corefy's reference_id is what the acquirer calls its own transaction
        // id, so keep it searchable and use it to pair the two sides exactly.
        $attributes['reference'] = Values::text($get('reference_id'));
        $attributes['paymentId'] ??= $attributes['reference'];

        return $attributes;
    }
}
