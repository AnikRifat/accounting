<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/** A document as a PDF, shown inline, for any company the user can access. */
class DocumentPdfController extends Controller
{
    public function __invoke(Request $request, int $document, DocumentRenderer $renderer): Response
    {
        Gate::authorize('sales.view');
        $document = Document::visibleTo($request->user())->findOrFail($document);

        return response($renderer->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $renderer->filename($document)),
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
