<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\SystemAccount;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentField;
use App\Models\DocumentLine;
use App\Models\DocumentPayment;
use App\Models\DocumentSequence;
use App\Models\DocumentTemplate;
use App\Models\JournalEntry;
use App\Models\Media;
use App\Models\Party;
use App\Support\DocumentMath;
use App\Support\Money;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Mpdf\Config\FontVariables;
use Mpdf\Language\LanguageToFont;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

/**
 * Renders a document (and a receipt for one of its payments) as a standalone HTML page and as a PDF, from the same
 * Blade views (resources/views/documents), so the preview, the print page, the share page and the emailed PDF match.
 * The views use table layout, inline CSS and pt sizes only, which both browsers and mPDF understand.
 */
class DocumentRenderer
{
    /** mPDF font family for Bengali text and the taka sign. */
    public const BENGALI_FONT = 'frishbengali';

    public function __construct(private readonly MediaService $media) {}

    /**
     * A complete HTML page. $toolbar is shown above the page on screen and hidden when printing; $template overrides
     * the document's own (used by the template preview).
     */
    public function html(Document $document, bool $pdf = false, ?Htmlable $toolbar = null, ?DocumentTemplate $template = null): string
    {
        $template ??= DocumentTemplate::forDocument($document->company_id, $document->template_id, $document->type);
        $layout = in_array($template->layout, DocumentTemplate::LAYOUTS, true) ? $template->layout : 'classic';

        return view('documents.layouts.'.$layout, [...$this->documentData($document, $template, $pdf), 'toolbar' => $toolbar])->render();
    }

    /** The document as a PDF (binary string). */
    public function pdf(Document $document): string
    {
        $template = DocumentTemplate::forDocument($document->company_id, $document->template_id, $document->type);

        return $this->toPdf($this->html($document, true, null, $template), $template, $document->displayNumber(), $this->watermark($document));
    }

    /** "INV-00012.pdf"; drafts have no number yet: "draft-5.pdf". */
    public function filename(Document $document): string
    {
        return $document->number === null ? 'draft-'.$document->id.'.pdf' : $this->safeName($document->number).'.pdf';
    }

    /** A receipt for money received for (or paid on) the document: a document payment or a ledger settlement. */
    public function receiptHtml(Document $document, DocumentPayment|JournalEntry $payment, bool $pdf = false, ?Htmlable $toolbar = null): string
    {
        $template = DocumentTemplate::forDocument($document->company_id, $document->template_id, $document->type);

        return view('documents.receipt', [...$this->receiptData($document, $payment, $template, $pdf), 'toolbar' => $toolbar])->render();
    }

    public function receiptPdf(Document $document, DocumentPayment|JournalEntry $payment): string
    {
        $template = DocumentTemplate::forDocument($document->company_id, $document->template_id, $document->type);
        $data = $this->receiptData($document, $payment, $template, true);

        return $this->toPdf($this->receiptHtml($document, $payment, true), $template, $data['number'], $data['watermark']);
    }

    public function receiptFilename(Document $document, DocumentPayment|JournalEntry $payment): string
    {
        return 'receipt-'.$this->safeName($this->receiptNumber($document, $payment)).'.pdf';
    }

    /**
     * An unsaved document of the template's company filled with sample data, for the template preview. Nothing is
     * written to the database.
     */
    public function sample(DocumentTemplate $template, DocumentType $type): Document
    {
        $company = $template->company ?? Company::query()->findOrFail($template->company_id);
        $lines = $type->hasLines() ? [
            ['description' => __('Website design and development'), 'quantity' => 1000, 'unit' => __('job'), 'unit_price' => 4_500_000, 'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 1500],
            ['description' => __('Hosting, one year'), 'quantity' => 12_000, 'unit' => __('month'), 'unit_price' => 150_000, 'discount_type' => DocumentMath::DISCOUNT_PERCENT, 'discount_value' => 1000, 'tax_rate' => 1500],
            ['description' => __('Support hours'), 'quantity' => 2500, 'unit' => __('hour'), 'unit_price' => 200_000, 'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 0],
        ] : [];
        $priced = DocumentMath::calculate($lines, null, 0, false, LedgerService::MAX_AMOUNT);
        $sequence = DocumentSequence::query()->where('company_id', $company->id)->where('type', $type)->first();
        $fields = DocumentField::query()->for($company->id, $type)->get();

        $document = (new Document)->forceFill([
            'company_id' => $company->id, 'type' => $type, 'status' => DocumentStatus::Issued, 'template_id' => $template->id,
            'number' => $sequence?->format($sequence->last_number + 1) ?? $type->defaultPrefix().'00001',
            'issue_date' => today()->toDateString(), 'due_date' => $type->dueLabel() ? today()->addDays(15)->toDateString() : null,
            'reference' => 'PO-1042', 'tax_inclusive' => false, 'discount_type' => null, 'discount_value' => 0,
            'subtotal' => $priced['subtotal'], 'discount_total' => $priced['discount_total'], 'tax_total' => $priced['tax_total'], 'total' => $priced['total'],
            'notes' => __('Thank you for your business.'), 'terms' => __('Payment within 15 days of the invoice date.'),
            'body' => $type === DocumentType::Contract ? __("This agreement is made between {company.name} and {party.name} of {party.address}.\n\nContract {document.number}, dated {document.date}.") : null,
            'custom_values' => $fields->mapWithKeys(fn (DocumentField $field): array => [$field->id => match ($field->kind) {
                'number' => '42', 'date' => today()->toDateString(), default => __('Sample'),
            }])->all(),
        ]);
        $document->setRelation('company', $company);
        $document->setRelation('party', new Party(['name' => 'Rahim Traders (রহিম ট্রেডার্স)', 'address' => __('House 12, Road 5, Dhanmondi, Dhaka'),
            'phone' => '01711-000000', 'email' => 'accounts@example.com']));
        $document->setRelation('lines', collect($lines)->map(fn (array $line, int $index): DocumentLine => (new DocumentLine)->forceFill([
            ...$line, ...$priced['lines'][$index], 'sort' => $index,
        ])));

        return $document;
    }

    /** @return array<string, mixed> */
    private function documentData(Document $document, DocumentTemplate $template, bool $pdf): array
    {
        if ($document->exists) {
            $document->loadMissing(['company', 'party', 'lines']);
        }
        $type = $document->type;
        $lines = $this->lines($document);
        $ownDiscounts = (int) $lines->sum('own_discount');

        return [
            ...$this->brand($template, $pdf),
            'document' => $document, 'type' => $type, 'company' => $document->company, 'party' => $document->party,
            'sections' => array_values(array_filter($template->orderedSections(), fn (array $section): bool => $section['visible'])),
            'heading' => $document->title ?: $type->label(),
            'partyLabel' => match (true) {
                $type === DocumentType::DeliveryNote => __('Deliver to'),
                $type->isPurchase() => __('Supplier'),
                default => __('Bill to'),
            },
            'showPrices' => $type !== DocumentType::DeliveryNote,
            'lines' => $lines,
            'hasLineDiscounts' => $ownDiscounts > 0,
            'hasTax' => $lines->contains(fn (array $line): bool => $line['tax_rate'] > 0) || $document->tax_total > 0,
            'totals' => [
                'subtotal' => (int) $lines->sum('line_amount'),
                'discount' => $document->discount_total - $ownDiscounts,
                'discountLabel' => $document->discount_type === DocumentMath::DISCOUNT_PERCENT
                    ? __('Discount (:rate%)', ['rate' => DocumentMath::formatBasisPoints($document->discount_value)]) : __('Discount'),
                'tax' => $document->tax_total, 'total' => $document->total,
            ],
            'payment' => $this->paymentSummary($document),
            'fields' => $this->fields($document),
            'body' => $type === DocumentType::Contract ? $this->contractBody($document) : null,
            'watermark' => $this->watermark($document),
        ];
    }

    /**
     * Lines with display values: the amount after the line's own discount (its share of the document discount is
     * shown once, in the totals).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function lines(Document $document): Collection
    {
        return collect($document->type->hasLines() ? $document->lines : [])->values()->map(function (DocumentLine $line): array {
            $own = match ($line->discount_type) {
                DocumentMath::DISCOUNT_PERCENT => DocumentMath::mulDivRound($line->amount, min($line->discount_value, DocumentMath::FULL), DocumentMath::FULL),
                DocumentMath::DISCOUNT_AMOUNT => min($line->discount_value, $line->amount),
                default => 0,
            };

            return [
                'description' => $line->description, 'quantity' => DocumentMath::formatMilli($line->quantity), 'unit' => $line->unit,
                'unit_price' => $line->unit_price, 'tax_rate' => $line->tax_rate, 'own_discount' => $own, 'line_amount' => $line->amount - $own,
                'discount_percent' => $line->discount_type === DocumentMath::DISCOUNT_PERCENT ? DocumentMath::formatBasisPoints($line->discount_value).'%' : null,
                'discount_amount' => $line->discount_type === DocumentMath::DISCOUNT_AMOUNT ? $line->discount_value : null,
            ];
        });
    }

    /**
     * Paid so far and the balance of an open invoice or bill; null for other documents.
     *
     * @return array{credited: int, paid: int, balance: int}|null
     */
    private function paymentSummary(Document $document): ?array
    {
        if (! $document->type->isPayable() || ! $document->isOpen()) {
            return null;
        }
        if (! $document->exists) {
            return ['credited' => 0, 'paid' => 0, 'balance' => $document->total];
        }
        $balance = (int) Document::query()->withBalance()->findOrFail($document->id)->balance();
        $credited = $this->issuedNotesTotal($document);

        return ['credited' => $credited, 'paid' => max(0, $document->total - $credited - $balance), 'balance' => $balance];
    }

    private function issuedNotesTotal(Document $document): int
    {
        return (int) Document::query()->where('source_id', $document->id)
            ->whereIn('type', [DocumentType::CreditNote, DocumentType::DebitNote])
            ->whereNotIn('status', [DocumentStatus::Draft, DocumentStatus::Void])->sum('total');
    }

    /** @return list<array{label: string, value: string}> */
    private function fields(Document $document): array
    {
        $values = array_filter((array) ($document->custom_values ?? []), fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '');
        if ($values === []) {
            return [];
        }

        return DocumentField::query()->where('company_id', $document->company_id)->whereIn('id', array_map('intval', array_keys($values)))
            ->orderBy('sort')->orderBy('id')->get()
            ->map(fn (DocumentField $field): array => ['label' => $field->label, 'value' => $field->kind === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values[$field->id]) === 1
                ? date('d M Y', strtotime((string) $values[$field->id])) : (string) $values[$field->id]])->all();
    }

    /** The contract text, escaped, with its placeholders filled in and paragraphs kept. */
    private function contractBody(Document $document): string
    {
        $values = [
            '{party.name}' => $document->party?->name ?? '', '{party.address}' => $document->party?->address ?? '',
            '{company.name}' => $document->company?->name ?? '', '{document.number}' => $document->displayNumber(),
            '{document.date}' => $document->issue_date?->format('d M Y') ?? '',
        ];
        $text = strtr(e(str_replace(["\r\n", "\r"], "\n", (string) $document->body)), array_map(fn (string $value): string => e($value), $values));

        return collect(preg_split('/\n\s*\n/', trim($text)) ?: [])->filter(fn (string $paragraph): bool => trim($paragraph) !== '')
            ->map(fn (string $paragraph): string => '<p>'.nl2br(trim($paragraph), false).'</p>')->join("\n");
    }

    private function watermark(Document $document): ?string
    {
        return match ($document->status) {
            DocumentStatus::Draft => __('DRAFT'),
            DocumentStatus::Void => __('VOID'),
            default => null,
        };
    }

    /**
     * Template branding shared by documents and receipts.
     *
     * @return array<string, mixed>
     */
    private function brand(DocumentTemplate $template, bool $pdf): array
    {
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $template->accent_color) === 1 ? $template->accent_color : '#166534';

        return [
            'template' => $template, 'pdf' => $pdf, 'accent' => $accent, 'fontStack' => $template->fontStack(),
            // The taka sign in an element of its own, so mPDF sets only the sign (not the digits) in the Bengali font.
            'money' => fn (int $paisa): HtmlString => new HtmlString(($paisa < 0 ? '-' : '').'<span class="currency">৳</span>'.Money::format(abs($paisa), false)),
            'logo' => $this->imageSource($template, DocumentTemplate::LOGO, $pdf),
            'signature' => $this->imageSource($template, DocumentTemplate::SIGNATURE, $pdf),
        ];
    }

    /** A signed URL for the browser; for mPDF the file itself, read from its storage disk, as a data URI. */
    private function imageSource(DocumentTemplate $template, string $collection, bool $pdf): ?string
    {
        if (! $template->exists) {
            return null;
        }
        $media = $template->getMedia($collection)->first(fn (Media $file): bool => str_starts_with((string) $file->mime_type, 'image/'));
        if (! $media) {
            return null;
        }
        if (! $pdf) {
            return $this->media->url($media);
        }
        try {
            $contents = Storage::disk($media->disk)->get($media->path);
        } catch (Throwable) {
            return null;
        }

        return $contents === null ? null : 'data:'.$media->mime_type.';base64,'.base64_encode($contents);
    }

    /** @return array<string, mixed> */
    private function receiptData(Document $document, DocumentPayment|JournalEntry $payment, DocumentTemplate $template, bool $pdf): array
    {
        $document->loadMissing(['company', 'party']);
        if ($payment instanceof JournalEntry) {
            $payment->loadMissing('lines.account');
            [$date, $method, $voided] = [$payment->entry_date, $payment->paymentAccount()?->name, $payment->isVoided()];
        } else {
            $payment->loadMissing('account');
            [$date, $method, $voided] = [$payment->paid_on, $payment->account?->name, false];
        }
        $purchase = $document->type->isPurchase();

        return [
            ...$this->brand($template, $pdf),
            'document' => $document, 'company' => $document->company, 'party' => $document->party,
            'heading' => $purchase ? __('Payment voucher') : __('Payment receipt'),
            'partyLabel' => $purchase ? __('Paid to') : __('Received from'),
            'number' => $this->receiptNumber($document, $payment), 'date' => $date, 'amount' => (int) $payment->amount,
            'method' => $method, 'reference' => $payment->reference,
            'balanceAfter' => $voided || $document->isVoid() ? null : $this->balanceAfter($document, $payment),
            'watermark' => $voided || $document->isVoid() ? __('VOID') : null,
        ];
    }

    private function receiptNumber(Document $document, DocumentPayment|JournalEntry $payment): string
    {
        if ($payment instanceof JournalEntry) {
            return $payment->number;
        }
        $position = $document->payments()->get(['id'])->search(fn (DocumentPayment $row): bool => $row->id === $payment->id);

        return $document->displayNumber().'/'.(($position === false ? 0 : $position) + 1);
    }

    /** What the document still owed right after this payment, counting payments in date order. */
    private function balanceAfter(Document $document, DocumentPayment|JournalEntry $payment): int
    {
        if ($payment instanceof JournalEntry) {
            $due = (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->where('journal_lines.journal_entry_id', $payment->bill_id)->whereIn('accounts.system_key', SystemAccount::dueKeys())
                ->sum(DB::raw('journal_lines.debit + journal_lines.credit'));
            $date = $payment->entry_date->toDateString();
            $settled = (int) JournalEntry::query()->posted()->where('bill_id', $payment->bill_id)
                ->where(fn ($query) => $query->where('entry_date', '<', $date)->orWhere(fn ($same) => $same->where('entry_date', $date)->where('id', '<=', $payment->id)))
                ->sum('amount');

            return $due - $settled;
        }
        $date = $payment->paid_on->toDateString();
        $paid = (int) $document->payments()->getQuery()
            ->where(fn ($query) => $query->where('paid_on', '<', $date)->orWhere(fn ($same) => $same->where('paid_on', $date)->where('id', '<=', $payment->id)))
            ->sum('amount');

        return $document->total - $this->issuedNotesTotal($document) - $paid;
    }

    private function toPdf(string $html, DocumentTemplate $template, string $title, ?string $watermark): string
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);
        // Bengali (and ৳) is set in FreeSerif, whose bold and italic files have no Bengali glyphs: a family of its own
        // uses the regular file for every style (its own name, so mPDF's cached metrics of FreeSerif Bold are not reused).
        $fontdata = (new FontVariables)->getDefaults()['fontdata'];
        $fontdata[self::BENGALI_FONT] = ['R' => 'FreeSerif.ttf', 'B' => 'FreeSerif.ttf', 'I' => 'FreeSerif.ttf', 'BI' => 'FreeSerif.ttf', 'useOTL' => 0xFF];
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tempDir, 'default_font' => $template->pdfFont(), 'fontdata' => $fontdata,
            'languageToFont' => new class extends LanguageToFont
            {
                public function getLanguageOptions($llcc, $adobeCJK)
                {
                    [$coreSuitable, $font] = parent::getLanguageOptions($llcc, $adobeCJK);

                    return [$coreSuitable, preg_match('/^(bn|ben|as|asm)\b/i', (string) $llcc) === 1 ? DocumentRenderer::BENGALI_FONT : $font];
                }
            },
            'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 14,
            'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator(config('app.name'));
        if ($watermark !== null) {
            $mpdf->SetWatermarkText($watermark, 0.08);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function safeName(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value), '-.') ?: 'document';
    }
}
