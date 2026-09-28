<?php

namespace App\Services\Documents;

use App\Enums\DocumentActivityType;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentFileStorage
{
    public const DISK = 'local';

    public function store(Document $document, UploadedFile $upload, ?User $user): DocumentFile
    {
        $extension = strtolower($upload->getClientOriginalExtension() ?: (string) $upload->extension());
        $path = $upload->storeAs("documents/{$document->id}", Str::uuid().'.'.$extension, self::DISK);

        $file = $document->files()->create([
            'path' => $path,
            'original_name' => Str::limit($upload->getClientOriginalName(), 250, ''),
            'mime_type' => $upload->getClientMimeType(),
            'size' => $upload->getSize(),
            'uploaded_by' => $user?->id,
        ]);

        $document->log(DocumentActivityType::FileUploaded, $user, ['meta' => ['name' => $file->original_name]]);

        return $file;
    }

    public function delete(DocumentFile $file, ?User $user): void
    {
        Storage::disk(self::DISK)->delete($file->path);
        $file->delete();

        $file->document->log(DocumentActivityType::FileRemoved, $user, ['meta' => ['name' => $file->original_name]]);
    }
}
