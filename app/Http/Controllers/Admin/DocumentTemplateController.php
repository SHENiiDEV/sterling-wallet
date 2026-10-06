<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentFileRules;
use App\Models\DocumentTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentTemplateController extends Controller
{
    private const DISK = 'local';

    public function index(): Response
    {
        return Inertia::render('admin/document-templates/index', [
            'templates' => DocumentTemplate::query()
                ->with('uploader:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (DocumentTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'original_name' => $t->original_name,
                    'size' => $t->size,
                    'uploader' => $t->uploader?->name,
                    'updated_at' => $t->updated_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            ...$this->rules(),
            'file' => ['required', 'file', 'max:'.DocumentFileRules::MAX_KB, 'mimes:'.DocumentFileRules::MIMES],
        ]);

        DocumentTemplate::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            ...$this->storeUpload($request->file('file')),
            'uploaded_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Template uploaded.']);

        return back();
    }

    /**
     * Rename, or replace the file with a newer version of the template.
     */
    public function update(Request $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        $data = $request->validate([
            ...$this->rules(),
            'file' => ['nullable', 'file', 'max:'.DocumentFileRules::MAX_KB, 'mimes:'.DocumentFileRules::MIMES],
        ]);

        $old = $documentTemplate->path;
        $documentTemplate->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        if ($request->hasFile('file')) {
            $documentTemplate->fill([...$this->storeUpload($request->file('file')), 'uploaded_by' => $request->user()->id]);
        }

        $documentTemplate->save();

        if ($old !== $documentTemplate->path) {
            Storage::disk(self::DISK)->delete($old);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Template saved.']);

        return back();
    }

    public function download(DocumentTemplate $documentTemplate): StreamedResponse
    {
        return Storage::disk(self::DISK)->download($documentTemplate->path, $documentTemplate->original_name);
    }

    public function destroy(DocumentTemplate $documentTemplate): RedirectResponse
    {
        Storage::disk(self::DISK)->delete($documentTemplate->path);
        $documentTemplate->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Template deleted.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeUpload(UploadedFile $upload): array
    {
        $extension = strtolower($upload->getClientOriginalExtension() ?: (string) $upload->extension());

        return [
            'path' => $upload->storeAs('document-templates', Str::uuid().'.'.$extension, self::DISK),
            'original_name' => Str::limit($upload->getClientOriginalName(), 250, ''),
            'mime_type' => $upload->getClientMimeType(),
            'size' => $upload->getSize(),
        ];
    }
}
