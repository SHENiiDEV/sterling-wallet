<?php

namespace App\Models;

use App\Enums\ProviderType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $daily_report_task_id
 * @property int $provider_id
 * @property ProviderType $role
 * @property string|null $file_path
 * @property Carbon $received_at
 * @property int $rows_count
 * @property int|null $bot_run_id
 */
#[Fillable(['daily_report_task_id', 'provider_id', 'role', 'file_path', 'received_at', 'rows_count', 'bot_run_id'])]
class DailyReportSource extends Model
{
    protected function casts(): array
    {
        return [
            'role' => ProviderType::class,
            'received_at' => 'datetime',
            'rows_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DailyReportTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(DailyReportTask::class, 'daily_report_task_id');
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return BelongsTo<BotRun, $this>
     */
    public function botRun(): BelongsTo
    {
        return $this->belongsTo(BotRun::class);
    }
}
