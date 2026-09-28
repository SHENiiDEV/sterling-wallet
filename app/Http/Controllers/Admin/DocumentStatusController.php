<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentStatusRequest;
use App\Http\Resources\DocumentStatusResource;
use App\Models\DocumentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DocumentStatusController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/document-statuses/index', [
            'statuses' => DocumentStatusResource::collection(
                DocumentStatus::query()->ordered()->withCount('documents')->get(),
            )->resolve(),
            'colors' => DocumentStatus::COLORS,
        ]);
    }

    public function store(DocumentStatusRequest $request): RedirectResponse
    {
        $this->save(new DocumentStatus, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Status created.']);

        return back();
    }

    public function update(DocumentStatusRequest $request, DocumentStatus $documentStatus): RedirectResponse
    {
        $this->save($documentStatus, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Status saved.']);

        return back();
    }

    public function destroy(DocumentStatus $documentStatus): RedirectResponse
    {
        if ($documentStatus->documents()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'This status is used by documents. Move them to another status first.',
            ]);
        }

        $documentStatus->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Status deleted.']);

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(DocumentStatus $status, array $data): void
    {
        DB::transaction(function () use ($status, $data) {
            $data['sort_order'] ??= (int) DocumentStatus::query()->max('sort_order') + 10;

            if ($data['is_default'] ?? false) {
                DocumentStatus::query()->whereKeyNot($status->id)->update(['is_default' => false]);
            }

            $status->fill($data)->save();
        });
    }
}
