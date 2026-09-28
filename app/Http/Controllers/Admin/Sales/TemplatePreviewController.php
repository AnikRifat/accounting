<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Services\DocumentRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** A sample document (`?type=`, invoice by default) rendered with a saved template, for the builder's preview. Writes nothing. */
class TemplatePreviewController extends Controller
{
    public function __invoke(Request $request, string $template, DocumentRenderer $renderer): Response
    {
        Gate::authorize('sales.setup');
        abort_unless(ctype_digit($template), 404);
        $template = DocumentTemplate::visibleTo($request->user())->with('company')->findOrFail((int) $template);
        $type = DocumentType::tryFrom((string) $request->query('type')) ?? DocumentType::Invoice;

        return response($renderer->html($renderer->sample($template, $type), false, null, $template))->header('X-Robots-Tag', 'noindex');
    }
}
