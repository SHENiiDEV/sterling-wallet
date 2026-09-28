<?php

namespace App\Reports\Parsers;

use Generator;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use SplFileInfo;
use SplFileObject;

/**
 * Yields rows (lists of cell strings) from CSV or XLSX. CSV is streamed line
 * by line; XLSX goes through PhpSpreadsheet in read-data-only mode.
 */
class TabularReader
{
    /**
     * @return Generator<int, list<string|null>>
     */
    public function rows(SplFileInfo $file): Generator
    {
        return $this->isSpreadsheet($file) ? $this->spreadsheetRows($file) : $this->csvRows($file);
    }

    private function isSpreadsheet(SplFileInfo $file): bool
    {
        $extension = strtolower($file instanceof UploadedFile
            ? ($file->getClientOriginalExtension() ?: $file->getExtension())
            : $file->getExtension());

        if (in_array($extension, ['xlsx', 'xls', 'ods'], true)) {
            return true;
        }

        if (in_array($extension, ['csv', 'txt'], true)) {
            return false;
        }

        // Unknown extension (e.g. a temp upload): sniff the ZIP / OLE signature.
        $head = (string) file_get_contents($file->getPathname(), false, null, 0, 8);

        return str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "\xD0\xCF\x11\xE0");
    }

    /**
     * @return Generator<int, list<string|null>>
     */
    private function csvRows(SplFileInfo $file): Generator
    {
        $handle = new SplFileObject($file->getPathname(), 'r');
        $delimiter = $this->detectDelimiter($handle);
        $handle->rewind();
        $handle->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $handle->setCsvControl($delimiter, '"', '');

        $first = true;
        foreach ($handle as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }

            if ($first && isset($row[0]) && is_string($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            }
            $first = false;

            yield array_map(fn ($cell) => $cell === null ? null : $this->utf8((string) $cell), array_values($row));
        }
    }

    private function detectDelimiter(SplFileObject $handle): string
    {
        $sample = '';
        for ($i = 0; $i < 10 && ! $handle->eof(); $i++) {
            $sample .= (string) $handle->fgets();
        }

        $counts = [];
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($sample, $candidate);
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /**
     * @return Generator<int, list<string|null>>
     */
    private function spreadsheetRows(SplFileInfo $file): Generator
    {
        $reader = IOFactory::createReaderForFile($file->getPathname());
        if ($reader instanceof Csv) {
            yield from $this->csvRows($file);

            return;
        }
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        $sheet = $reader->load($file->getPathname())->getSheet(0);

        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);
            foreach ($iterator as $cell) {
                $value = $cell->getValue();
                $cells[] = $value === null ? null : (is_float($value) ? $this->floatToString($value) : trim((string) $value));
            }

            yield $cells;
        }
    }

    /**
     * Spreadsheet numbers arrive as floats; print them without exponent and
     * without binary noise (0.1 + 0.2 style) before they become decimals.
     */
    private function floatToString(float $value): string
    {
        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.') ?: '0';
    }
}
