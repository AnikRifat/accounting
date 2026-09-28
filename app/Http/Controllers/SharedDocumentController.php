<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\DocumentRenderer;
use App\Services\DocumentService;
use App\Support\Modules;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, token-only view and PDF of one issued document. Missing, expired, draft and void documents are 404, and so is
 * every link while the Sales module is disabled. The
 * page shows the document itself and a PDF link, nothing else; views are logged at most once an hour.
 */
class SharedDocumentController extends Controller
{
    private const HEADERS = ['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'private, no-store'];

    public function __construct(private readonly DocumentRenderer $renderer) {}

    public function show(string $token): Response
    {
        $document = $this->shared($token);
        $recentlyViewed = $document->activities()->getQuery()->where('event', 'viewed')->where('created_at', '>=', now()->subHour())->exists();
        if (! $recentlyViewed) {
            app(DocumentService::class)->log($document, 'viewed', null);
        }
        $toolbar = view('documents.partials.toolbar', ['links' => [
            ['label' => __('Download PDF'), 'href' => route('documents.shared.pdf', $token), 'external' => true],
        ]]);

        return response($this->renderer->html($document, false, $toolbar), 200, self::HEADERS);
    }

    public function pdf(string $token): Response
    {
        $document = $this->shared($token);

        return response($this->renderer->pdf($document), 200, [...self::HEADERS,
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $this->renderer->filename($document)),
        ]);
    }

    private function shared(string $token): Document
    {
        $document = Document::query()->where('share_token', $token)->first();
        abort_if(! Modules::enabled(Modules::SALES) || $document === null || $document->isDraft() || $document->isVoid()
            || ($document->share_expires_at !== null && $document->share_expires_at->isPast()), 404);

        return $document;
    }
}
