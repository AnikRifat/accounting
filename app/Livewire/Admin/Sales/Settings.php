<?php

namespace App\Livewire\Admin\Sales;

use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentField;
use App\Models\DocumentSequence;
use App\Models\DocumentTemplate;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Numbering, default template and default texts per document type, and the custom field definitions, of the one
 * header company (`sales.setup`). A prefix change never renumbers issued documents: the counter continues from the
 * highest number already issued with the new prefix (a fresh prefix starts at 1), and DocumentService skips any
 * number that is taken anyway.
 */
class Settings extends Component
{
    public const PREFIX_PATTERN = '/^[A-Za-z0-9\/_-]+$/';

    #[Locked]
    public ?int $companyId = null;

    /** @var array<string, array{prefix: string, padding: string, templateId: string, notes: string, terms: string}> keyed by DocumentType value */
    public array $rows = [];

    /** @var array{id: ?int, label: string, kind: string, documentType: string, isRequired: bool, isActive: bool} */
    public array $field = [];

    public function mount(): void
    {
        Gate::authorize('sales.setup');
        $company = app(CompanyContext::class)->company();
        $this->companyId = $company?->is_active ? $company->id : null;
        $this->resetField();
        if ($this->companyId) {
            $sequences = DocumentSequence::query()->where('company_id', $this->companyId)->get()->keyBy(fn (DocumentSequence $sequence): string => $sequence->type->value);
            foreach (DocumentType::cases() as $type) {
                $sequence = $sequences->get($type->value);
                $this->rows[$type->value] = ['prefix' => $sequence?->prefix ?? $type->defaultPrefix(), 'padding' => (string) ($sequence?->padding ?? 5),
                    'templateId' => (string) ($sequence?->template_id ?? ''), 'notes' => (string) $sequence?->default_notes, 'terms' => (string) $sequence?->default_terms];
            }
        }
    }

    public function saveNumbering(): void
    {
        Gate::authorize('sales.setup');
        $company = $this->contextCompany();
        if (! $company) {
            return;
        }
        $rules = [];
        foreach (DocumentType::cases() as $type) {
            $key = 'rows.'.$type->value;
            foreach (['prefix', 'padding', 'templateId', 'notes', 'terms'] as $name) {
                $this->rows[$type->value][$name] = trim((string) ($this->rows[$type->value][$name] ?? ''));
            }
            $rules += [
                $key.'.prefix' => ['required', 'string', 'max:20', 'regex:'.self::PREFIX_PATTERN],
                $key.'.padding' => ['required', 'integer', 'min:3', 'max:8'],
                $key.'.templateId' => ['nullable', 'integer', Rule::exists('document_templates', 'id')->where('company_id', $company->id)],
                $key.'.notes' => ['nullable', 'string', 'max:5000'],
                $key.'.terms' => ['nullable', 'string', 'max:5000'],
            ];
        }
        $this->validate($rules, ['rows.*.prefix.regex' => __('Use letters, digits, /, _ and - only.'), 'rows.*.templateId.exists' => __('Choose a template of this company.')],
            ['rows.*.prefix' => __('prefix'), 'rows.*.padding' => __('digits'), 'rows.*.notes' => __('notes'), 'rows.*.terms' => __('terms')]);

        DB::transaction(function () use ($company): void {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            foreach (DocumentType::cases() as $type) {
                $row = $this->rows[$type->value];
                $sequence = DocumentSequence::query()->where('company_id', $company->id)->where('type', $type)->lockForUpdate()->first()
                    ?? new DocumentSequence(['company_id' => $company->id, 'type' => $type, 'last_number' => 0]);
                if (! $sequence->exists || $sequence->prefix !== $row['prefix']) {
                    $sequence->last_number = self::highestUsed($company->id, $type, $row['prefix']);
                }
                $sequence->forceFill(['prefix' => $row['prefix'], 'padding' => (int) $row['padding'], 'template_id' => $row['templateId'] !== '' ? (int) $row['templateId'] : null,
                    'default_notes' => $row['notes'] !== '' ? $row['notes'] : null, 'default_terms' => $row['terms'] !== '' ? $row['terms'] : null])->save();
            }
        });
        session()->now('success', __('Numbering and defaults saved.'));
    }

    public function editField(int $fieldId): void
    {
        Gate::authorize('sales.setup');
        $field = DocumentField::query()->where('company_id', $this->companyId)->findOrFail($fieldId);
        $this->resetValidation();
        $this->field = ['id' => $field->id, 'label' => $field->label, 'kind' => $field->kind, 'documentType' => (string) $field->document_type?->value,
            'isRequired' => $field->is_required, 'isActive' => $field->is_active];
    }

    public function resetField(): void
    {
        $this->field = ['id' => null, 'label' => '', 'kind' => 'text', 'documentType' => '', 'isRequired' => false, 'isActive' => true];
        $this->resetValidation();
    }

    public function saveField(): void
    {
        Gate::authorize('sales.setup');
        $company = $this->contextCompany();
        if (! $company) {
            return;
        }
        $this->field['label'] = trim((string) ($this->field['label'] ?? ''));
        $this->validate([
            'field.label' => ['required', 'string', 'max:60'],
            'field.kind' => ['required', Rule::in(DocumentField::KINDS)],
            'field.documentType' => ['nullable', Rule::enum(DocumentType::class)],
            'field.isRequired' => ['boolean'],
            'field.isActive' => ['boolean'],
        ], [], ['field.label' => __('label'), 'field.kind' => __('kind'), 'field.documentType' => __('document type')]);
        $attributes = ['label' => $this->field['label'], 'kind' => $this->field['kind'],
            'document_type' => ($this->field['documentType'] ?? '') !== '' ? $this->field['documentType'] : null,
            'is_required' => (bool) $this->field['isRequired'], 'is_active' => (bool) $this->field['isActive']];
        if ($this->field['id'] ?? null) {
            DocumentField::query()->where('company_id', $company->id)->findOrFail((int) $this->field['id'])->update($attributes);
        } else {
            $sort = (int) DocumentField::query()->where('company_id', $company->id)->max('sort') + 1;
            DocumentField::create(['company_id' => $company->id, 'sort' => $sort, ...$attributes]);
        }
        $this->resetField();
        session()->now('success', __('Custom field saved.'));
    }

    /** Deletes a field definition; values already stored on documents are no longer shown. */
    public function deleteField(int $fieldId): void
    {
        Gate::authorize('sales.setup');
        $company = $this->contextCompany();
        if (! $company) {
            return;
        }
        $field = DocumentField::query()->where('company_id', $company->id)->findOrFail($fieldId);
        $field->delete();
        if (($this->field['id'] ?? null) === $fieldId) {
            $this->resetField();
        }
        session()->now('success', __(':name deleted.', ['name' => $field->label]));
    }

    /** Moves a field one place up (-1) or down (1) and renumbers the company's fields 0…n. */
    public function moveField(int $fieldId, int $direction): void
    {
        Gate::authorize('sales.setup');
        $company = $this->contextCompany();
        if (! $company) {
            return;
        }
        $ids = DocumentField::query()->where('company_id', $company->id)->orderBy('sort')->orderBy('id')->pluck('id')->all();
        $index = array_search($fieldId, $ids, true);
        abort_if($index === false, 404);
        $target = $index + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= count($ids)) {
            return;
        }
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        DB::transaction(function () use ($ids): void {
            foreach ($ids as $sort => $id) {
                DocumentField::query()->whereKey($id)->update(['sort' => $sort]);
            }
        });
    }

    public function render(): View
    {
        Gate::authorize('sales.setup');
        if (! $this->companyId) {
            return view('livewire.admin.sales.settings', ['companyName' => null])->layout('layouts.admin');
        }
        $sequences = DocumentSequence::query()->where('company_id', $this->companyId)->get()->keyBy(fn (DocumentSequence $sequence): string => $sequence->type->value);
        $previews = [];
        foreach (DocumentType::cases() as $type) {
            $row = $this->rows[$type->value] ?? [];
            $prefix = trim((string) ($row['prefix'] ?? ''));
            $padding = (int) ($row['padding'] ?? 5);
            $saved = $sequences->get($type->value);
            if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1 || strlen($prefix) > 20 || $padding < 3 || $padding > 8) {
                $previews[$type->value] = null;

                continue;
            }
            $last = $saved && $saved->prefix === $prefix ? $saved->last_number : self::highestUsed($this->companyId, $type, $prefix);
            $previews[$type->value] = (new DocumentSequence(['prefix' => $prefix, 'padding' => $padding]))->format($last + 1);
        }

        return view('livewire.admin.sales.settings', [
            'companyName' => Company::visibleTo(auth()->user())->whereKey($this->companyId)->value('name'),
            'types' => DocumentType::cases(),
            'previews' => $previews,
            'templates' => ['' => __('Company default template')] + DocumentTemplate::query()->where('company_id', $this->companyId)->orderBy('name')->pluck('name', 'id')->all(),
            'fields' => DocumentField::query()->where('company_id', $this->companyId)->orderBy('sort')->orderBy('id')->get(),
            'kindOptions' => ['text' => __('Text'), 'number' => __('Number'), 'date' => __('Date')],
            'typeOptions' => ['' => __('All document types')] + collect(DocumentType::cases())->mapWithKeys(fn (DocumentType $type): array => [$type->value => $type->label()])->all(),
        ])->layout('layouts.admin');
    }

    /** The highest sequence number already used with this prefix by the company's documents of this type. */
    public static function highestUsed(int $companyId, DocumentType $type, string $prefix): int
    {
        // LIKE treats `_` and `%` as wildcards and is case-insensitive on MySQL, so the exact prefix is confirmed in PHP.
        return (int) Document::query()->where('company_id', $companyId)->where('type', $type)->where('number', 'like', $prefix.'%')->pluck('number')
            ->filter(fn (string $number): bool => str_starts_with($number, $prefix))
            ->map(fn (string $number): string => substr($number, strlen($prefix)))
            ->filter(fn (string $rest): bool => $rest !== '' && ctype_digit($rest))
            ->map(fn (string $rest): int => (int) $rest)->max();
    }

    /** The header company, while it is still the one this page was opened for, visible and active. */
    private function contextCompany(): ?Company
    {
        $company = app(CompanyContext::class)->company();
        if ($company?->is_active && $company->id === $this->companyId) {
            return $company;
        }
        $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

        return null;
    }
}
