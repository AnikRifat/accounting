<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Enums\EntryType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Services\DocumentRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * A receipt for one payment on a document: `payment` is a payment recorded on an unposted document, `entry` a ledger
 * receipt or payment (posted or voided) settling the posted document's entry. HTML with a print toolbar, or `?pdf=1`.
 */
class ReceiptController extends Controller
{
    public function __invoke(Request $request, int $document, string $kind, int $id, DocumentRenderer $renderer): Response
    {
        Gate::authorize('sales.view');
        $document = Document::visibleTo($request->user())->findOrFail($document);
        $payment = match ($kind) {
            'payment' => $document->payments()->findOrFail($id),
            'entry' => $document->journal_entry_id === null ? abort(404) : JournalEntry::query()->whereKey($id)
                ->where('company_id', $document->company_id)->where('bill_id', $document->journal_entry_id)
                ->whereIn('type', [EntryType::Receipt, EntryType::Payment])->firstOrFail(),
            default => abort(404),
        };

        if ($request->boolean('pdf')) {
            return response($renderer->receiptPdf($document, $payment), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $renderer->receiptFilename($document, $payment)),
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex',
            ]);
        }
        $toolbar = view('documents.partials.toolbar', ['links' => [
            ['label' => __('Back'), 'href' => route('admin.sales.documents.show', $document), 'back' => true],
            ['label' => __('Download PDF'), 'href' => route('admin.sales.documents.receipt', [$document, $kind, $id, 'pdf' => 1])],
            ['label' => __('Print'), 'print' => true],
        ]]);

        return response($renderer->receiptHtml($document, $payment, false, $toolbar))->header('X-Robots-Tag', 'noindex');
    }
}
