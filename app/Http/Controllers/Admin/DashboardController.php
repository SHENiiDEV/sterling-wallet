<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentStatusResource;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $open = fn (Builder $q) => $q->whereHas('status', fn (Builder $q) => $q->where('is_final', false));

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'documents_open' => Document::query()->where($open)->count(),
                'documents_overdue' => Document::query()->overdue()->count(),
                'documents_signed_30d' => Document::query()
                    ->whereHas('status', fn (Builder $q) => $q->where('is_final', true))
                    ->where('status_changed_at', '>=', now()->subDays(30))
                    ->count(),
                'staff' => User::query()->where('role', '!=', UserRole::Merchant)->where('is_active', true)->count(),
            ],
            'pipeline' => DocumentStatusResource::collection(
                DocumentStatus::query()->ordered()->withCount('documents')->get(),
            )->resolve(),
            'attention' => Document::query()
                ->with(['status', 'owner'])
                ->where($open)
                ->whereNotNull('due_date')
                ->orderBy('due_date')
                ->limit(6)
                ->get()
                ->map(fn (Document $document) => DocumentResource::make($document)->resolve()),
            'activity' => DocumentActivity::query()
                ->with(['user:id,name', 'toStatus', 'document:id,title'])
                ->whereHas('document')
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (DocumentActivity $activity) => [
                    'id' => $activity->id,
                    'type' => $activity->type->value,
                    'user' => $activity->user?->name,
                    'document' => ['id' => $activity->document->id, 'title' => $activity->document->title],
                    'to_status' => $activity->toStatus ? DocumentStatusResource::make($activity->toStatus)->resolve() : null,
                    'created_at' => $activity->created_at?->toIso8601String(),
                ]),
        ]);
    }
}
