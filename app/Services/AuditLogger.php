<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function log(string $action, ?Model $subject = null, array $meta = []): AdminAuditLog
    {
        return AdminAuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Fills the model with the new values and returns what actually changes, as field => [before, after].
     * The caller saves the model afterwards.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function diff(Model $model, array $data): array
    {
        $model->fill($data);

        $plain = fn (mixed $value) => match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => json_encode($value),
            default => $value,
        };

        $changes = [];
        foreach (array_keys($model->getDirty()) as $field) {
            $changes[$field] = [$plain($model->getOriginal($field)), $plain($model->getAttribute($field))];
        }

        return $changes;
    }
}
