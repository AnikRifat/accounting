<?php

namespace App\Livewire\Admin\Sales\Documents;

use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentField;
use App\Models\DocumentLine;
use App\Models\DocumentSequence;
use App\Models\DocumentTemplate;
use App\Models\Item;
use App\Models\Party;
use App\Services\DocumentService;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use App\Support\Configuration;
use App\Support\DocumentMath;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Creates or edits a document of any type. Inputs stay strings on the component (taka, "1.5", "15") and are converted
 * with Money / DocumentMath on save; DocumentService validates against the company and recomputes every total.
 */
class Form extends Component
{
    /** Service line keys → this form's row keys, for mapping validation errors back onto the rows. */
    private const LINE_FIELDS = ['item_id' => 'item', 'account_id' => 'account', 'description' => 'description', 'quantity' => 'quantity',
        'unit' => 'unit', 'unit_price' => 'price', 'discount_type' => 'discountType', 'discount_value' => 'discount', 'tax_rate' => 'vat'];

    /** Service keys → this form's properties. */
    private const FIELDS = ['party_id' => 'partyId', 'issue_date' => 'issueDate', 'due_date' => 'dueDate', 'template_id' => 'templateId',
        'tax_inclusive' => 'taxInclusive', 'discount_type' => 'discountType', 'discount_value' => 'discountValue', 'post_to_accounts' => 'postToAccounts',
        'source_id' => 'sourceId', 'company' => 'document', 'document' => 'document'];

    #[Locked]
    public ?int $documentId = null;

    #[Locked]
    public string $type = '';

    /** The document's company: from the header context when creating (re-checked on save), from the record when editing. */
    #[Locked]
    public ?int $companyId = null;

    /** Whether the note's invoice or bill is fixed (it came with the page, or the note is saved). */
    #[Locked]
    public bool $sourceFixed = false;

    /** The invoice or bill a new credit or debit note is issued against. */
    public string $sourceId = '';

    public string $partyId = '';

    public string $issueDate = '';

    public string $dueDate = '';

    public string $title = '';

    public string $reference = '';

    public string $templateId = '';

    /**
     * Rows of the line editor. Amounts are taka, quantities decimal units, VAT and percent discounts percent.
     *
     * @var list<array{key: string, item: string, description: string, quantity: string, unit: string, price: string, discountType: string, discount: string, vat: string, account: string}>
     */
    public array $lines = [];

    public string $discountType = '';

    public string $discountValue = '';

    public bool $taxInclusive = false;

    public string $notes = '';

    public string $terms = '';

    public string $body = '';

    /** @var array<int|string, string> custom field id → value */
    public array $customValues = [];

    public bool $postToAccounts = false;

    public function mount(?string $type = null, ?Document $document = null): void
    {
        $user = auth()->user();
        if ($document?->exists) {
            Gate::authorize('sales.update');
            abort_unless(Document::query()->visibleTo($user)->whereKey($document->id)->exists(), 404);
            abort_if(in_array($document->status, [DocumentStatus::Void, DocumentStatus::Converted], true), 403,
                __('A :status document can no longer be edited.', ['status' => mb_strtolower($document->status->label())]));
            $this->fillFrom($document);

            return;
        }
        Gate::authorize('sales.create');
        $documentType = DocumentType::tryFrom((string) $type);
        abort_if($documentType === null, 404);
        $company = app(CompanyContext::class)->company();
        abort_unless($company?->is_active, 404);
        $this->type = $documentType->value;
        $this->companyId = $company->id;
        $this->issueDate = today()->toDateString();
        if ($documentType->dueLabel() !== null && ($dueDays = Configuration::get('sales.default_due_days')) !== null) {
            $this->dueDate = today()->addDays($dueDays)->toDateString();
        }
        $this->taxInclusive = Configuration::get('sales.tax_inclusive');
        $this->postToAccounts = $this->canPost($documentType) && Configuration::get('sales.post_to_accounts');
        $sequence = DocumentSequence::for($company->id, $documentType);
        $this->templateId = (string) $sequence->template_id;
        $this->notes = (string) $sequence->default_notes;
        $this->terms = (string) $sequence->default_terms;
        $this->lines = $documentType->hasLines() ? [$this->blankRow()] : [];
        if ($documentType->isNote() && request()->filled('source')) {
            $source = $this->sourceQuery()->find(request()->integer('source'));
            abort_if($source === null, 404);
            $this->sourceFixed = true;
            $this->prefillFrom($source);
        }
    }

    /** Picking the invoice or bill of a new note fills the note from it. */
    public function updatedSourceId(): void
    {
        abort_if($this->sourceFixed || $this->documentId !== null, 403);
        $this->resetErrorBag('sourceId');
        $source = ctype_digit($this->sourceId) ? $this->sourceQuery()->find((int) $this->sourceId) : null;
        if ($source === null) {
            $this->sourceId = '';

            return;
        }
        $this->prefillFrom($source);
    }

    /** Choosing a catalogue item fills the row with its description, unit, price, VAT and category. */
    public function updatedLines(mixed $value, string $key = ''): void
    {
        if (! preg_match('/^(\d+)\.item$/', $key, $match) || ! isset($this->lines[(int) $match[1]]) || ! is_string($value) || ! ctype_digit($value)) {
            return;
        }
        $item = Item::query()->where('company_id', $this->visibleCompanyId())->where('is_active', true)->find((int) $value);
        if ($item === null) {
            return;
        }
        $row = (int) $match[1];
        $this->lines[$row] = array_merge($this->lines[$row], [
            'description' => $item->description ? $item->name.' — '.$item->description : $item->name,
            'unit' => (string) $item->unit,
            'price' => Money::toInput($item->price),
            'vat' => DocumentMath::formatBasisPoints($item->tax_rate),
            'account' => $item->account_id && $this->categories()->contains('id', $item->account_id) ? (string) $item->account_id : $this->lines[$row]['account'],
        ]);
    }

    public function addLine(): void
    {
        if (count($this->lines) < DocumentService::MAX_LINES && DocumentType::from($this->type)->hasLines()) {
            $this->lines[] = $this->blankRow();
        }
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) > 1 && isset($this->lines[$index])) {
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
            $this->resetErrorBag();
        }
    }

    /** Moves a row one place up ($direction -1) or down (+1). */
    public function moveLine(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);
        if (isset($this->lines[$index], $this->lines[$target])) {
            [$this->lines[$index], $this->lines[$target]] = [$this->lines[$target], $this->lines[$index]];
            $this->resetErrorBag();
        }
    }

    /** Saves the draft or document; with $issue, then issues the draft (numbering it and, when switched on, posting it). */
    public function save(bool $issue = false): Redirector|RedirectResponse|null
    {
        $user = auth()->user();
        Gate::authorize($this->documentId ? 'sales.update' : 'sales.create');
        if ($issue) {
            Gate::authorize('sales.update');
        }
        $document = $this->documentId ? Document::query()->visibleTo($user)->findOrFail($this->documentId) : null;
        $company = $document ? Company::query()->findOrFail($document->company_id) : $this->writableCompany();
        if ($company === null) {
            return null;
        }
        $type = DocumentType::from($this->type);
        $this->resetErrorBag();
        $this->validate([
            'lines' => ['array', 'max:'.DocumentService::MAX_LINES], 'lines.*' => ['array'], 'lines.*.*' => ['nullable', 'string', 'max:500'],
            'customValues' => ['array'], 'customValues.*' => ['nullable', 'string', 'max:255'],
        ], [], ['lines.*.*' => __('value'), 'customValues.*' => __('value')]);
        $payload = $this->payload($type, $document);
        if ($payload === null) {
            return null;
        }
        [$data, $rowOf] = $payload;
        $service = app(DocumentService::class);
        try {
            $saved = $service->save($document, $company, $type, $data, $user);
        } catch (ValidationException $exception) {
            $this->mapErrors($exception, $rowOf);

            return null;
        }
        $this->documentId = $saved->id;
        $this->sourceFixed = true;
        if ($issue && $saved->isDraft()) {
            try {
                $saved = $service->issue($saved, $user);
            } catch (ValidationException $exception) {
                $this->mapErrors($exception, $rowOf);
                session()->now('success', __('The draft was saved but not issued.'));

                return null;
            }
            session()->flash('success', __(':type :number issued.', ['type' => $type->label(), 'number' => $saved->number]));
        } else {
            session()->flash('success', __(':type :number saved.', ['type' => $type->label(), 'number' => $saved->displayNumber()]));
        }

        return redirect()->route('admin.sales.documents.show', $saved);
    }

    public function render(): View
    {
        $type = DocumentType::from($this->type);
        $companyId = $this->visibleCompanyId();
        $company = $companyId ? Company::query()->find($companyId, ['id', 'name', 'is_active']) : null;
        $stored = $this->documentId ? Document::query()->with('source:id,number,journal_entry_id')->find($this->documentId) : null;
        $source = $stored?->source ?? ($type->isNote() && ctype_digit($this->sourceId) ? $this->sourceQuery()->find((int) $this->sourceId) : null);
        $preview = $this->preview();

        return view('livewire.admin.sales.documents.form', [
            'documentType' => $type,
            'stored' => $stored,
            'companyName' => $company?->name,
            'partyLabel' => $type->isPurchase() ? __('Supplier') : __('Customer'),
            'parties' => $this->partyOptions($companyId),
            'templates' => ['' => __('Default template')] + ($companyId ? DocumentTemplate::query()->where('company_id', $companyId)->orderBy('name')->pluck('name', 'id')->all() : []),
            'items' => ['' => __('Free text')] + $this->itemOptions($companyId),
            'categories' => ['' => __('Default category')] + $this->categories()->mapWithKeys(fn (Account $account): array => [$account->id => $account->name])->all(),
            'discountTypes' => ['' => __('No discount'), DocumentMath::DISCOUNT_AMOUNT => __('Amount (৳)'), DocumentMath::DISCOUNT_PERCENT => __('Percent (%)')],
            'fields' => $companyId ? DocumentField::query()->for($companyId, $type)->get() : collect(),
            'sources' => $type->isNote() && ! $this->sourceFixed && $companyId ? ['' => __('Choose one')] + $this->sourceQuery()->with('party:id,name')->orderByDesc('issue_date')->limit(200)->get()
                ->mapWithKeys(fn (Document $document): array => [$document->id => $document->number.' · '.($document->party?->name ?? '—').' · '.Money::format($document->total)])->all() : [],
            'source' => $source,
            'preview' => $preview,
            'canPost' => $this->canPost($type),
            'canIssue' => auth()->user()->can('sales.update') && ($stored === null || $stored->isDraft()),
            'isDraft' => $stored === null || $stored->isDraft(),
            'heading' => $stored ? __('Edit :type :number', ['type' => mb_strtolower($type->label()), 'number' => $stored->displayNumber()])
                : __('New :type', ['type' => mb_strtolower($type->label())]),
        ])->layout('layouts.admin');
    }

    /**
     * Converts the form to DocumentService::save() data. Malformed numbers become field errors (and null is returned).
     *
     * @return array{0: array<string, mixed>, 1: array<int, int>}|null data, and the service's line index → form row
     */
    private function payload(DocumentType $type, ?Document $document): ?array
    {
        $errors = [];
        $lines = [];
        $rowOf = [];
        foreach ($type->hasLines() ? $this->lines : [] as $row => $line) {
            if ($this->isBlankRow($line)) {
                continue;
            }
            $quantity = $this->milli($line['quantity'] ?? '');
            if ($quantity === null) {
                $errors["lines.{$row}.quantity"] = __('Enter a quantity such as 1 or 1.5.');
            }
            $price = $this->paisa($line['price'] ?? '');
            if ($price === null) {
                $errors["lines.{$row}.price"] = __('Enter a price in taka, for example 1,250.50.');
            }
            $discountType = $this->discountTypeOf($line['discountType'] ?? '');
            $discount = $this->discountOf($discountType, $line['discount'] ?? '');
            if ($discount === null) {
                $errors["lines.{$row}.discount"] = $this->discountError($discountType);
            }
            $vat = trim($line['vat'] ?? '') === '' ? 0 : $this->basisPoints($line['vat']);
            if ($vat === null) {
                $errors["lines.{$row}.vat"] = __('Enter a VAT rate from 0 to 100, for example 15 or 7.5.');
            }
            $rowOf[count($lines)] = $row;
            $lines[] = [
                'item_id' => ctype_digit($line['item'] ?? '') ? (int) $line['item'] : null,
                'account_id' => ctype_digit($line['account'] ?? '') ? (int) $line['account'] : null,
                'description' => trim($line['description'] ?? ''),
                'quantity' => (int) $quantity,
                'unit' => trim($line['unit'] ?? ''),
                'unit_price' => (int) $price,
                'discount_type' => $discountType,
                'discount_value' => (int) $discount,
                'tax_rate' => (int) $vat,
            ];
        }
        $discountType = $this->discountTypeOf($this->discountType);
        $discountValue = $this->discountOf($discountType, $this->discountValue);
        if ($discountValue === null) {
            $errors['discountValue'] = $this->discountError($discountType);
        }
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError($field, $message);
            }

            return null;
        }
        $canPost = $this->canPost($type);

        return [[
            'party_id' => ctype_digit($this->partyId) ? (int) $this->partyId : null,
            'issue_date' => $this->issueDate,
            'due_date' => $type->dueLabel() !== null && $this->dueDate !== '' ? $this->dueDate : null,
            'title' => $this->title,
            'reference' => $this->reference,
            'template_id' => ctype_digit($this->templateId) ? (int) $this->templateId : null,
            'tax_inclusive' => $this->taxInclusive,
            'discount_type' => $discountType,
            'discount_value' => (int) $discountValue,
            'notes' => $this->notes,
            'terms' => $this->terms,
            'body' => $type === DocumentType::Contract ? $this->body : null,
            'custom_values' => $this->customValues,
            // Without the ledger permission the switch keeps whatever the document already had.
            'post_to_accounts' => $canPost ? $this->postToAccounts : (bool) $document?->post_to_accounts,
            'source_id' => $type->isNote() && ctype_digit($this->sourceId) ? (int) $this->sourceId : null,
            'lines' => $lines,
        ], $rowOf];
    }

    /** Puts the service's errors on this form's fields. @param array<int, int> $rowOf */
    private function mapErrors(ValidationException $exception, array $rowOf): void
    {
        foreach ($exception->errors() as $key => $messages) {
            if (preg_match('/^lines\.(\d+)\.(\w+)$/', $key, $match)) {
                $field = 'lines.'.($rowOf[(int) $match[1]] ?? $match[1]).'.'.(self::LINE_FIELDS[$match[2]] ?? $match[2]);
            } elseif (preg_match('/^custom_values\.(\d+)$/', $key, $match)) {
                $field = 'customValues.'.$match[1];
            } else {
                $field = self::FIELDS[$key] ?? Str::camel($key);
            }
            $this->addError($field, $messages[0]);
        }
    }

    /**
     * Figures for the live totals panel, from whatever is typed so far (unreadable numbers count as zero). Display
     * only: the service recomputes on save.
     *
     * @return array{lines: array<int, int>, subtotal: int, discount_total: int, tax_total: int, total: int}
     */
    private function preview(): array
    {
        $rows = [];
        foreach ($this->lines as $row => $line) {
            $line = is_array($line) ? $line : [];
            $discountType = $this->discountTypeOf($line['discountType'] ?? '');
            $rows[$row] = ['quantity' => $this->milli($line['quantity'] ?? '') ?? 0, 'unit_price' => $this->paisa($line['price'] ?? '') ?? 0,
                'discount_type' => $discountType, 'discount_value' => $this->discountOf($discountType, $line['discount'] ?? '') ?? 0,
                'tax_rate' => $this->basisPoints($line['vat'] ?? '') ?? 0];
        }
        $discountType = $this->discountTypeOf($this->discountType);
        $priced = DocumentMath::calculate(array_values($rows), $discountType, $this->discountOf($discountType, $this->discountValue) ?? 0,
            $this->taxInclusive, LedgerService::MAX_AMOUNT);

        return ['lines' => array_combine(array_keys($rows), array_column($priced['lines'], 'total')) ?: [], 'subtotal' => $priced['subtotal'],
            'discount_total' => $priced['discount_total'], 'tax_total' => $priced['tax_total'], 'total' => $priced['total']];
    }

    private function fillFrom(Document $document): void
    {
        $this->documentId = $document->id;
        $this->type = $document->type->value;
        $this->companyId = $document->company_id;
        $this->sourceFixed = true;
        $this->sourceId = (string) $document->source_id;
        $this->partyId = (string) $document->party_id;
        $this->issueDate = $document->issue_date->toDateString();
        $this->dueDate = (string) $document->due_date?->toDateString();
        $this->title = (string) $document->title;
        $this->reference = (string) $document->reference;
        $this->templateId = (string) $document->template_id;
        $this->taxInclusive = $document->tax_inclusive;
        $this->discountType = (string) $document->discount_type;
        $this->discountValue = $this->discountInput($document->discount_type, $document->discount_value);
        $this->notes = (string) $document->notes;
        $this->terms = (string) $document->terms;
        $this->body = (string) $document->body;
        $this->customValues = array_map('strval', $document->custom_values ?? []);
        $this->postToAccounts = $document->post_to_accounts;
        $this->lines = $document->type->hasLines() ? ($document->lines()->get()->map(fn (DocumentLine $line): array => $this->rowOf($line))->all() ?: [$this->blankRow()]) : [];
    }

    /** Fills a new note from its invoice or bill: party, lines and the source number as reference. */
    private function prefillFrom(Document $source): void
    {
        $this->sourceId = (string) $source->id;
        $this->partyId = (string) $source->party_id;
        $this->reference = (string) $source->number;
        $this->taxInclusive = $source->tax_inclusive;
        $this->lines = $source->lines()->get()->map(fn (DocumentLine $line): array => $this->rowOf($line))->all() ?: [$this->blankRow()];
    }

    /** @return array{key: string, item: string, description: string, quantity: string, unit: string, price: string, discountType: string, discount: string, vat: string, account: string} */
    private function rowOf(DocumentLine $line): array
    {
        return ['key' => Str::random(8), 'item' => (string) $line->item_id, 'description' => $line->description,
            'quantity' => DocumentMath::formatMilli($line->quantity), 'unit' => (string) $line->unit, 'price' => Money::toInput($line->unit_price),
            'discountType' => (string) $line->discount_type, 'discount' => $this->discountInput($line->discount_type, $line->discount_value),
            'vat' => DocumentMath::formatBasisPoints($line->tax_rate), 'account' => (string) $line->account_id];
    }

    /** @return array{key: string, item: string, description: string, quantity: string, unit: string, price: string, discountType: string, discount: string, vat: string, account: string} */
    private function blankRow(): array
    {
        // D2: sales lines start at the VAT rate chosen in Settings (15% standard); purchases start at none.
        return ['key' => Str::random(8), 'item' => '', 'description' => '', 'quantity' => '1', 'unit' => '', 'price' => '', 'discountType' => '',
            'discount' => '', 'vat' => DocumentType::from($this->type)->isPurchase() ? '0' : Configuration::get('sales.default_vat'), 'account' => (string) $this->categories()->first()?->id];
    }

    /** A row nobody has filled in (no item, description or price) is left out on save. */
    private function isBlankRow(mixed $line): bool
    {
        return ! is_array($line) || (trim($line['item'] ?? '') === '' && trim($line['description'] ?? '') === '' && trim($line['price'] ?? '') === '');
    }

    private function discountTypeOf(mixed $type): ?string
    {
        return in_array($type, [DocumentMath::DISCOUNT_AMOUNT, DocumentMath::DISCOUNT_PERCENT], true) ? $type : null;
    }

    /** The discount in paisa or basis points; 0 without a type or value; null when it can't be read. */
    private function discountOf(?string $type, mixed $value): ?int
    {
        if ($type === null || ! is_string($value) || trim($value) === '') {
            return 0;
        }

        return $type === DocumentMath::DISCOUNT_PERCENT ? $this->basisPoints($value) : $this->paisa($value);
    }

    private function discountError(?string $type): string
    {
        return $type === DocumentMath::DISCOUNT_PERCENT ? __('Enter a percentage from 0 to 100, for example 10 or 7.5.') : __('Enter an amount in taka, for example 1,250.50.');
    }

    private function discountInput(?string $type, int $value): string
    {
        return match ($type) {
            DocumentMath::DISCOUNT_PERCENT => DocumentMath::formatBasisPoints($value),
            DocumentMath::DISCOUNT_AMOUNT => Money::toInput($value),
            default => '',
        };
    }

    private function paisa(mixed $value): ?int
    {
        return is_string($value) && Money::isValidInput($value) ? Money::toPaisa($value) : null;
    }

    private function milli(mixed $value): ?int
    {
        try {
            return is_string($value) ? DocumentMath::toMilli($value) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function basisPoints(mixed $value): ?int
    {
        try {
            return is_string($value) ? DocumentMath::toBasisPoints($value) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** Whether this user may switch "Post to accounts" here: invoices and bills, with the ledger's entries.create. Notes follow their source. */
    private function canPost(DocumentType $type): bool
    {
        return $type->isPostable() && ! $type->isNote() && auth()->user()->can('entries.create');
    }

    /** @return Builder<Document> issued invoices or bills of the form's company that a new note can be issued against */
    private function sourceQuery(): Builder
    {
        return Document::query()->visibleTo(auth()->user())->where('company_id', $this->visibleCompanyId() ?? 0)
            ->where('type', DocumentType::from($this->type)->noteFor())
            ->whereNotIn('status', [DocumentStatus::Draft, DocumentStatus::Void]);
    }

    /** @return Collection<int, Account> active categories of the document's side (income for sales, expense for purchases), plus chosen ones */
    private function categories(): Collection
    {
        $companyId = $this->visibleCompanyId();
        if ($companyId === null) {
            return collect();
        }
        $chosen = array_values(array_filter(array_map(fn (mixed $line): int => is_array($line) ? (int) ($line['account'] ?? 0) : 0, $this->lines)));

        return Account::query()->where('company_id', $companyId)
            ->categories(DocumentType::from($this->type)->isPurchase() ? AccountType::Expense : AccountType::Income)
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereIn('id', $chosen))->orderBy('code')->get(['id', 'code', 'name']);
    }

    /** @return array<int|string, string> active parties of the company, plus the chosen one */
    private function partyOptions(?int $companyId): array
    {
        $none = DocumentType::from($this->type) === DocumentType::Contract ? __('No party') : __('Choose one');
        if ($companyId === null) {
            return ['' => $none];
        }
        $parties = Party::query()->where('company_id', $companyId)
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhere('id', (int) $this->partyId))
            ->orderBy('name')->get(['id', 'name', 'phone']);

        return ['' => $none] + $parties->mapWithKeys(fn (Party $party): array => [$party->id => $party->name.($party->phone ? ' · '.$party->phone : '')])->all();
    }

    /** @return array<int, string> active items of the company, plus those already on the lines */
    private function itemOptions(?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }
        $chosen = array_values(array_filter(array_map(fn (mixed $line): int => is_array($line) ? (int) ($line['item'] ?? 0) : 0, $this->lines)));

        return Item::query()->where('company_id', $companyId)->where(fn (Builder $query) => $query->where('is_active', true)->orWhereIn('id', $chosen))
            ->orderBy('name')->get(['id', 'name', 'price'])
            ->mapWithKeys(fn (Item $item): array => [$item->id => $item->name.' · '.Money::format($item->price)])->all();
    }

    private function visibleCompanyId(): ?int
    {
        return $this->companyId !== null && auth()->user()->canAccessCompany($this->companyId) ? $this->companyId : null;
    }

    /**
     * The header's company a new document goes to. Adds an error and returns null when the header changed in another
     * tab, or the company is no longer visible or active.
     */
    private function writableCompany(): ?Company
    {
        if (app(CompanyContext::class)->selectedId() !== $this->companyId) {
            $this->addError('document', __('The company in the header has changed since this page opened. Reload the page to continue.'));

            return null;
        }
        $company = Company::visibleTo(auth()->user())->find($this->companyId);
        if (! $company?->is_active) {
            $this->addError('document', __('This company is inactive or no longer available and does not accept new documents.'));

            return null;
        }

        return $company;
    }
}
