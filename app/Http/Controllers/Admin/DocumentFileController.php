<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentFileRules;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Services\Documents\DocumentFileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentFileController extends Controller
{
    public function store(Request $request, Document $document, DocumentFileStorage $storage): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.DocumentFileRules::MAX_KB, 'mimes:'.DocumentFileRules::MIMES],
        ]);

        foreach ($request->file('files') as $upload) {
            $storage->store($document, $upload, $request->user());
        }

        $document->touch();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Files uploaded.']);

        return back();
    }

    public function show(Document $document, DocumentFile $file): StreamedResponse
    {
        return Storage::disk(DocumentFileStorage::DISK)->download($file->path, $file->original_name);
    }

    public function destroy(Request $request, Document $document, DocumentFile $file, DocumentFileStorage $storage): RedirectResponse
    {
        $storage->delete($file, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'File removed.']);

        return back();
    }
}
