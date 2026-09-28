<?php

namespace App\Livewire\Admin\Sales\Recurring;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\RecurringFrequency;
use App\Models\Company;
use App\Models\Document;
use App\Models\RecurringInvoice;
use App\Services\RecurringInvoices;
use App\Support\CompanyContext;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/** Adds or edits a recurring invoice schedule (`sales.update`); App\Services\RecurringInvoices validates and saves it. */
class Form extends Component
{
    /** Service data keys → this form's properties, for error messages. */
    private const FIELDS = ['source_id' => 'sourceId', 'name' => 'name', 'frequency' => 'frequency', 'day' => 'day', 'starts_on' => 'startsOn',
        'ends_on' => 'endsOn', 'is_active' => 'isActive', 'company' => 'company'];

    #[Locked]
    public ?int $scheduleId = null;

    /** The schedule's own company, or the header company when adding. */
    #[Locked]
    public ?int $companyId = null;

    public string $name = '';

    public string $sourceId = '';

    public string $frequency = 'monthly';

    public string $day = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public bool $isActive = true;

    public function mount(?RecurringInvoice $recurring = null, ?int $source = null): void
    {
        Gate::authorize('sales.update');
        $this->scheduleId = $recurring?->exists ? $recurring->id : null;
        if ($this->scheduleId) {
            abort_unless(auth()->user()->canAccessCompany($recurring->company_id), 404);
            $this->companyId = $recurring->company_id;
            [$this->name, $this->sourceId, $this->frequency, $this->day] = [$recurring->name, (string) $recurring->source_id, $recurring->frequency->value, (string) $recurring->day];
            [$this->startsOn, $this->endsOn, $this->isActive] = [$recurring->starts_on->toDateString(), (string) $recurring->ends_on?->toDateString(), $recurring->is_active];
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
            $this->startsOn = today()->toDateString();
            $this->day = (string) today()->day;
            if ($source && array_key_exists($source, $this->sourceOptions())) {
                $this->sourceId = (string) $source;
                $this->updatedSourceId();
            }
        }
    }

    /** Names a new schedule after the invoice's customer. */
    public function updatedSourceId(): void
    {
        if ($this->name !== '' || $this->sourceId === '') {
            return;
        }
        $party = Document::query()->where('company_id', $this->companyId)->with('party:id,name')->find((int) $this->sourceId)?->party;
        $this->name = $party ? mb_substr($party->name, 0, 100) : '';
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('sales.update');
        $existing = $this->scheduleId ? RecurringInvoice::query()->whereIn('company_id', auth()->user()->accessibleCompanyIds())->findOrFail($this->scheduleId) : null;
        $company = $existing?->company ?? $this->contextCompany();
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        $this->resetValidation();
        try {
            app(RecurringInvoices::class)->save($existing, $company, [
                'source_id' => $this->sourceId, 'name' => trim($this->name), 'frequency' => $this->frequency, 'day' => $this->day,
                'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn !== '' ? $this->endsOn : null, 'is_active' => $this->isActive,
            ], auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError(self::FIELDS[$key] ?? 'company', $messages[0]);
            }

            return null;
        }
        session()->flash('success', __('Recurring schedule saved.'));

        return redirect()->route('admin.sales.recurring.index');
    }

    public function render(): View
    {
        return view('livewire.admin.sales.recurring.form', [
            'companyName' => Company::visibleTo(auth()->user())->whereKey($this->companyId)->value('name'),
            'sourceOptions' => $this->sourceOptions(),
            'frequencyOptions' => collect(RecurringFrequency::cases())->mapWithKeys(fn (RecurringFrequency $frequency): array => [$frequency->value => $frequency->label()])->all(),
        ])->layout('layouts.admin');
    }

    /** @return array<int, string> draft and issued invoices of the company, newest first, plus the current source */
    private function sourceOptions(): array
    {
        return Document::query()->where('company_id', $this->companyId)->where('type', DocumentType::Invoice)
            ->where(fn ($query) => $query->whereIn('status', [DocumentStatus::Draft, DocumentStatus::Issued])->orWhere('id', (int) $this->sourceId))
            ->with('party:id,name')->orderByDesc('id')->limit(300)->get()
            ->mapWithKeys(fn (Document $invoice): array => [$invoice->id => $invoice->displayNumber().' · '.($invoice->party?->name ?? '—').' · '.Money::format($invoice->total)])->all();
    }

    /** The header company, while it is still the one this page was opened for, visible and active. */
    private function contextCompany(): ?Company
    {
        $company = app(CompanyContext::class)->company();

        return $company?->is_active && $company->id === $this->companyId ? $company : null;
    }
}
