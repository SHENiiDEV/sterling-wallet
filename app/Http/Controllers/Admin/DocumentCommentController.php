<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentActivityType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentCommentController extends Controller
{
    public function __invoke(Request $request, Document $document): RedirectResponse
    {
        $data = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $document->log(DocumentActivityType::Comment, $request->user(), ['comment' => $data['comment']]);
        $document->touch();

        return back();
    }
}
