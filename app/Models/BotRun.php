<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BotRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $connector
 * @property int|null $integration_account_id
 * @property Carbon $report_date
 * @property string $target_key
 * @property array<string, mixed>|null $target
 * @property BotRunStatus $status
 * @property int $attempts
 * @property list<string>|null $files
 * @property int|null $rows_count
 * @property string|null $error
 * @property string|null $screenshot_path
 * @property string|null $log
 * @property int|null $duration_ms
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read IntegrationAccount|null $account
 */
#[Fillable([
    'connector', 'integration_account_id', 'report_date', 'target_key', 'target', 'status', 'attempts',
    'files', 'rows_count', 'error', 'screenshot_path', 'log', 'duration_ms', 'triggered_by', 'started_at', 'finished_at',
])]
class BotRun extends Model
{
    protected function casts(): array
    {
        return [
            'report_date' => DateOnly::class,
            'target' => 'array',
            'status' => BotRunStatus::class,
            'attempts' => 'integer',
            'files' => 'array',
            'rows_count' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IntegrationAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class, 'integration_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
