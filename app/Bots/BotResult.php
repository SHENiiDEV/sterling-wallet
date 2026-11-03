<?php

namespace App\Bots;

use App\Enums\BotRunStatus;
use Carbon\CarbonImmutable;

/**
 * What a connector brought back. `files` are absolute local paths ready for
 * ingestion; `rows === 0` with no files means "the provider has nothing".
 */
final readonly class BotResult
{
    /**
     * @param  list<string>  $files
     * @param  array<string, string>  $fileDates  file path => the report day it belongs to (a range run brings one file per period)
     * @param  list<string>  $emptyDates  report days the provider returned nothing for
     */
    public function __construct(
        public BotRunStatus $status,
        public array $files = [],
        public ?int $rows = null,
        public ?CarbonImmutable $reportDate = null,
        public ?string $error = null,
        public ?string $screenshot = null,
        public string $log = '',
        public array $fileDates = [],
        public array $emptyDates = [],
    ) {}

    public function isEmptyReport(): bool
    {
        return $this->status === BotRunStatus::Succeeded && $this->files === [] && $this->rows === 0;
    }
}
