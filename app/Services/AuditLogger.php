<?php

namespace App\Services;

use App\Models\AdminAuditLog;
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
}
