<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who did what in the console: file downloads, exports, settlements, access changes.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = AdminAuditLog::query()
            ->with('user:id,name')
            ->when($filters['user'] ?? null, fn (Builder $q, int $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn (Builder $q, string $action) => $q->where('action', 'like', $action.'%'))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(
                fn (Builder $q) => $q->where('action', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('meta', 'like', "%{$search}%")
                    ->orWhere('subject_id', $search),
            ))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<', date('Y-m-d', strtotime($to.' +1 day'))))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AdminAuditLog $log) => [
                'id' => $log->id,
                'at' => $log->created_at?->toIso8601String(),
                'user' => $log->user?->name,
                'action' => $log->action,
                'subject' => $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : null,
                'ip' => $log->ip_address,
                'meta' => $log->meta,
            ]);

        return Inertia::render('admin/audit/index', [
            'logs' => $logs,
            'filters' => (object) $filters,
            'users' => User::query()->whereIn('role', [UserRole::SuperAdmin, UserRole::Admin])->orderBy('name')->get(['id', 'name']),
            // The first part of an action is its area: settlement.approved → settlement.
            'areas' => AdminAuditLog::query()->distinct()->pluck('action')
                ->map(fn (string $a) => explode('.', $a)[0])->unique()->sort()->values(),
        ]);
    }
}
