<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentActivityType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentStatusChangeController extends Controller
{
    public function __invoke(Request $request, Document $document): RedirectResponse
    {
        $data = $request->validate([
            'document_status_id' => ['required', 'exists:document_statuses,id'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $from = $document->document_status_id;

        if ($from !== (int) $data['document_status_id']) {
            $document->update([
                'document_status_id' => $data['document_status_id'],
                'status_changed_at' => now(),
            ]);

            $document->log(DocumentActivityType::StatusChanged, $request->user(), [
                'from_status_id' => $from,
                'to_status_id' => $document->document_status_id,
                'comment' => $data['comment'] ?? null,
            ]);

            Inertia::flash('toast', ['type' => 'success', 'message' => 'Status updated.']);
        }

        return back();
    }
}
