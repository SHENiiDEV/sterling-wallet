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
     */
    public function __construct(
        public BotRunStatus $status,
        public array $files = [],
        public ?int $rows = null,
        public ?CarbonImmutable $reportDate = null,
        public ?string $error = null,
        public ?string $screenshot = null,
        public string $log = '',
    ) {}

    public function isEmptyReport(): bool
    {
        return $this->status === BotRunStatus::Succeeded && $this->files === [] && $this->rows === 0;
    }
}
