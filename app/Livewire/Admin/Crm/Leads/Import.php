<?php

namespace App\Livewire\Admin\Crm\Leads;

use App\Models\Company;
use App\Models\CrmService;
use App\Models\CrmSource;
use App\Models\CrmStatus;
use App\Services\LeadImporter;
use App\Support\CompanyContext;
use App\Support\Crm;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Imports a CSV or Excel list of leads into the header company (see App\Services\LeadImporter). */
class Import extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $companyId = null;

    /** @var UploadedFile|null */
    public $file = null;

    public string $statusId = '';

    public string $serviceId = '';

    public string $sourceId = '';

    public string $assignTo = '';

    /** @var array{created: int, duplicates: int, invalid: list<int>}|null */
    public ?array $result = null;

    public function mount(): void
    {
        Gate::authorize('crm.leads.import');
        $this->companyId = app(CompanyContext::class)->company()?->id;
        $this->statusId = (string) ($this->companyId ? Crm::defaultLeadStatusId($this->companyId) : '');
        $this->assignTo = auth()->user()->isRoot() ? '' : (string) auth()->id();
    }

    public function import(LeadImporter $importer): void
    {
        Gate::authorize('crm.leads.import');
        $this->result = null;
        $company = app(CompanyContext::class)->company();
        if (! $company?->is_active || $company->id !== $this->companyId) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return;
        }
        $user = auth()->user();
        $data = $this->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx'],
            'statusId' => ['required', Rule::in($this->statuses($company)->keys()->map(fn (mixed $id): string => (string) $id)->all())],
            'serviceId' => ['nullable', Rule::in($this->services($company)->keys()->map(fn (mixed $id): string => (string) $id)->all())],
            'sourceId' => ['nullable', Rule::in($this->sources($company)->keys()->map(fn (mixed $id): string => (string) $id)->all())],
            'assignTo' => ['nullable', Rule::in(Crm::assignableUsers($company->id)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all())],
        ], [], ['file' => __('file'), 'statusId' => __('status'), 'serviceId' => __('service'), 'sourceId' => __('source'), 'assignTo' => __('assigned to')]);
        // Without crm.leads.all imported leads are the importer's own, like leads they add by hand.
        $assignTo = $user->hasPermission('crm.leads.all') ? ($data['assignTo'] ?: null) : $user->id;
        $extension = strtolower($this->file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        try {
            $this->result = $importer->import($this->file->getRealPath(), $extension, $company, (int) $data['statusId'],
                $data['serviceId'] ? (int) $data['serviceId'] : null,
                $data['sourceId'] ? (int) $data['sourceId'] : null, $assignTo, $user->id);
        } catch (ValidationException $exception) {
            $this->addError('file', collect($exception->errors())->flatten()->first());
        }
        $this->reset('file');
    }

    public function render(): View
    {
        $company = Company::visibleTo(auth()->user())->find($this->companyId);

        return view('livewire.admin.crm.leads.import', [
            'companyName' => $company?->name,
            'statuses' => $company ? $this->statuses($company)->all() : [],
            'services' => ['' => __('No service')] + ($company ? $this->services($company)->all() : []),
            'sources' => ['' => __('Not recorded')] + ($company ? $this->sources($company)->all() : []),
            'assignees' => ['' => __('Unassigned')] + ($company ? Crm::assignableUsers($company->id)->pluck('name', 'id')->all() : []),
            'canAssign' => Gate::allows('crm.leads.all'),
            'columns' => LeadImporter::COLUMNS,
        ])->layout('layouts.admin');
    }

    /** @return Collection<int, string> */
    private function statuses(Company $company): Collection
    {
        return CrmStatus::query()->where('company_id', $company->id)->lead()->where('is_active', true)->orderBy('position')->pluck('name', 'id');
    }

    /** @return Collection<int, string> */
    private function sources(Company $company): Collection
    {
        return CrmSource::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->pluck('name', 'id');
    }

    /** @return Collection<int, string> */
    private function services(Company $company): Collection
    {
        return CrmService::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->pluck('name', 'id');
    }
}
