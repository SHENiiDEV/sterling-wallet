<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentActivityType;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentStatusResource;
use App\Models\Document;
use App\Models\DocumentStatus;
use App\Models\User;
use App\Services\Documents\DocumentFileStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'owner' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
        ]);

        $documents = Document::query()
            ->with(['status', 'owner'])
            ->withCount('files')
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(
                fn (Builder $q) => $q->where('title', 'like', "%{$search}%")->orWhere('counterparty', 'like', "%{$search}%"),
            ))
            ->when($filters['status'] ?? null, fn (Builder $q, int $status) => $q->where('document_status_id', $status))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($filters['owner'] ?? null, fn (Builder $q, int $owner) => $q->where('owner_id', $owner))
            ->when($filters['overdue'] ?? false, fn (Builder $q) => $q->overdue())
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Document $document) => DocumentResource::make($document)->resolve());

        return Inertia::render('admin/documents/index', [
            'documents' => $documents,
            'statuses' => DocumentStatusResource::collection(DocumentStatus::query()->ordered()->withCount('documents')->get())->resolve(),
            'totals' => [
                'all' => Document::query()->count(),
                'overdue' => Document::query()->overdue()->count(),
            ],
            'filters' => (object) Arr::only($filters, ['search', 'status', 'type', 'owner', 'overdue']),
            'types' => DocumentType::options(),
            'staff' => $this->staff(),
        ]);
    }

    public function store(DocumentRequest $request, DocumentFileStorage $storage): RedirectResponse
    {
        $data = $request->validated();

        $document = DB::transaction(function () use ($data, $request, $storage) {
            $document = Document::query()->create([
                ...Arr::except($data, ['files']),
                'document_status_id' => $data['document_status_id'] ?? DocumentStatus::default()?->id,
                'created_by' => $request->user()->id,
                'status_changed_at' => now(),
            ]);

            $document->log(DocumentActivityType::Created, $request->user(), ['to_status_id' => $document->document_status_id]);

            foreach ($request->file('files', []) as $upload) {
                $storage->store($document, $upload, $request->user());
            }

            return $document;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document created.']);

        return to_route('admin.documents.show', $document);
    }

    public function show(Document $document): Response
    {
        $document->load([
            'status', 'owner',
            'files.uploader:id,name',
            'activities' => fn ($q) => $q->with(['user:id,name', 'fromStatus', 'toStatus'])->limit(100),
        ]);

        return Inertia::render('admin/documents/show', [
            'document' => DocumentResource::make($document)->resolve(),
            'files' => $document->files->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'uploaded_by' => $file->uploader?->name,
                'created_at' => $file->created_at?->toIso8601String(),
            ]),
            'activities' => $document->activities->map(fn ($activity) => [
                'id' => $activity->id,
                'type' => $activity->type->value,
                'user' => $activity->user?->name,
                'comment' => $activity->comment,
                'meta' => $activity->meta,
                'from_status' => $activity->fromStatus ? DocumentStatusResource::make($activity->fromStatus)->resolve() : null,
                'to_status' => $activity->toStatus ? DocumentStatusResource::make($activity->toStatus)->resolve() : null,
                'created_at' => $activity->created_at?->toIso8601String(),
            ]),
            'statuses' => DocumentStatusResource::collection(DocumentStatus::query()->ordered()->get())->resolve(),
            'types' => DocumentType::options(),
            'staff' => $this->staff(),
        ]);
    }

    public function update(DocumentRequest $request, Document $document): RedirectResponse
    {
        $document->fill($request->validated());

        if ($document->isDirty()) {
            $changed = array_keys($document->getDirty());
            $document->save();
            $document->log(DocumentActivityType::Updated, $request->user(), ['meta' => ['fields' => $changed]]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document saved.']);

        return back();
    }

    public function destroy(Document $document): RedirectResponse
    {
        $document->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document archived.']);

        return to_route('admin.documents.index');
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function staff(): array
    {
        return User::query()
            ->where('role', '!=', UserRole::Merchant)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
