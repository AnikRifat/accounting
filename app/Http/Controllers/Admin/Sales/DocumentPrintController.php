<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The print page of a document of any company the user can access (not only the header one). `?embed=1` leaves out
 * the toolbar, for an iframe preview.
 */
class DocumentPrintController extends Controller
{
    public function __invoke(Request $request, int $document, DocumentRenderer $renderer): Response
    {
        Gate::authorize('sales.view');
        $document = Document::visibleTo($request->user())->findOrFail($document);
        $toolbar = $request->boolean('embed') ? null : view('documents.partials.toolbar', ['links' => [
            ['label' => __('Back'), 'href' => route('admin.sales.documents.show', $document), 'back' => true],
            ['label' => __('Download PDF'), 'href' => route('admin.sales.documents.pdf', $document)],
            ['label' => __('Print'), 'print' => true],
        ]]);

        return response($renderer->html($document, false, $toolbar))->header('X-Robots-Tag', 'noindex');
    }
}
