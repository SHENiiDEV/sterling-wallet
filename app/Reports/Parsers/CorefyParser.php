<?php

namespace App\Reports\Parsers;

/**
 * Corefy (Paycore) payment-invoices export. Its `status` column
 * (processed / process_failed / expired …) feeds classification through
 * `processing_code`; `resolution` carries the decline reason.
 */
class CorefyParser extends HeaderMappedParser
{
    /**
     * Columns only the Corefy dashboard export has. Its times are UTC, while the bot's CSV (and the API bot's) carry the
     * provider's local time: the header tells them apart, so a file uploaded by hand is read right either way.
     */
    private const UTC_EXPORT_MARKERS = ['processedamount', 'processedfee', 'cardreferenceid', 'servicemethod'];

    private bool $utcExport = false;

    private string $localZone = 'UTC';

    public function format(): string
    {
        return 'corefy';
    }

    protected function buildRow(array $cells, array $columns, array $headers, string $timezone): ?OperationRow
    {
        $this->localZone = $timezone;
        $this->utcExport = array_intersect(self::UTC_EXPORT_MARKERS, array_map(fn (string $h) => self::normalizeHeader($h), $headers)) !== [];

        return parent::buildRow($cells, $columns, $headers, $this->utcExport ? 'UTC' : $timezone);
    }

    protected function adjust(array $attributes, callable $get): ?array
    {
        // The instant is right (read as UTC); the report day is the day on the provider's clock.
        if ($this->utcExport && $attributes['transactedAt'] !== null) {
            $attributes['transactedOn'] = $attributes['transactedAt']->setTimezone($this->localZone)->startOfDay();
        }

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
