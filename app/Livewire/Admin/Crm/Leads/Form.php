<?php

namespace App\Livewire\Admin\Crm\Leads;

use App\Livewire\Concerns\WithPhotoUpload;
use App\Models\Company;
use App\Models\CrmService;
use App\Models\CrmSource;
use App\Models\CrmStatus;
use App\Models\Lead;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\Configuration;
use App\Support\Crm;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Adds or edits a lead. A new lead goes to the header company; only users with `crm.leads.all` choose who it is
 * assigned to, everyone else keeps their own leads. Services, statuses and assignees must belong to the lead's company.
 */
class Form extends Component
{
    use WithPhotoUpload;

    #[Locked]
    public ?int $leadId = null;

    /** The lead's own company, or the header company when creating. */
    #[Locked]
    public ?int $companyId = null;

    /** Where to go after saving: the page the sheet was opened from. */
    #[Locked]
    public string $returnTo = '';

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $organization = '';

    public string $address = '';

    public string $sourceId = '';

    public string $serviceId = '';

    public string $statusId = '';

    public string $assignedTo = '';

    public string $nextCallOn = '';

    public string $notes = '';

    public function mount(?Lead $lead = null, ?string $returnTo = null): void
    {
        $this->leadId = $lead?->exists ? $lead->id : null;
        $this->returnTo = $returnTo !== null && str_starts_with($returnTo, url('/admin')) ? $returnTo : route('admin.crm.leads.index');
        Gate::authorize($this->leadId ? 'crm.leads.update' : 'crm.leads.create');
        $user = auth()->user();
        if ($this->leadId) {
            abort_unless(Lead::visibleTo($user)->whereKey($lead->id)->exists(), 404);
            $this->companyId = $lead->company_id;
            foreach (['name', 'phone', 'email', 'organization', 'address', 'notes'] as $field) {
                $this->{$field} = (string) $lead->{$field};
            }
            [$this->serviceId, $this->sourceId, $this->statusId, $this->assignedTo]
                = [(string) $lead->crm_service_id, (string) $lead->crm_source_id, (string) $lead->crm_status_id, (string) $lead->assigned_to];
            $this->nextCallOn = (string) $lead->next_call_on?->toDateString();
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
            $this->statusId = (string) ($this->companyId ? Crm::defaultLeadStatusId($this->companyId) : '');
            $this->assignedTo = $user->isRoot() || ! Configuration::get('crm.assign_to_creator') ? '' : (string) $user->id;
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize($this->leadId ? 'crm.leads.update' : 'crm.leads.create');
        $user = auth()->user();
        $existing = $this->leadId ? Lead::visibleTo($user)->findOrFail($this->leadId) : null;
        $company = $existing?->company ?? $this->contextCompany();
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        foreach (['name', 'email', 'organization', 'address', 'notes'] as $field) {
            $this->{$field} = trim($this->{$field});
        }
        $this->phone = Crm::normalizePhone($this->phone);
        $data = $this->validate($this->rules($company, $existing), [
            'phone.regex' => __('Enter a phone number of 6 to 15 digits, optionally starting with +.'),
            'phone.unique' => __('A lead with this phone number already exists in :company.', ['company' => $company->name]),
        ], ['phone' => __('phone'), 'email' => __('email'), 'serviceId' => __('service'), 'sourceId' => __('source'), 'statusId' => __('status'),
            'assignedTo' => __('assigned to'), 'nextCallOn' => __('next call'), 'notes' => __('notes'), 'photo' => __('photo')]);

        $closed = (bool) CrmStatus::query()->whereKey($data['statusId'])->value('is_closed');
        $attributes = [
            'name' => $data['name'] ?: null, 'phone' => $data['phone'], 'email' => $data['email'] ?: null,
            'organization' => $data['organization'] ?: null, 'address' => $data['address'] ?: null, 'crm_source_id' => $data['sourceId'] ?: null,
            'crm_service_id' => $data['serviceId'] ?: null, 'crm_status_id' => (int) $data['statusId'],
            'next_call_on' => $closed ? null : ($data['nextCallOn'] ?: null), 'notes' => $data['notes'] ?: null,
            // Without crm.leads.all a new lead is the creator's own and an existing one keeps its assignee.
            'assigned_to' => $user->hasPermission('crm.leads.all') ? ($data['assignedTo'] ?: null) : ($existing ? $existing->assigned_to : $user->id),
        ];
        $lead = $existing ? tap($existing)->update($attributes) : Lead::create(['company_id' => $company->id, 'created_by' => $user->id, ...$attributes]);
        $this->syncPhoto($lead, $user);
        session()->flash('success', __('Lead saved.'));

        return redirect()->to($this->returnTo);
    }

    public function render(): View
    {
        $companyId = (int) $this->companyId;
        $current = $this->leadId ? Lead::query()->find($this->leadId) : null;

        return view('livewire.admin.crm.leads.form', [
            'companyName' => Company::visibleTo(auth()->user())->whereKey($companyId)->value('name'),
            'currentPhoto' => $current?->photoUrl(),
            'canAssign' => Gate::allows('crm.leads.all'),
            'services' => ['' => __('No service')] + CrmService::query()->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $current?->crm_service_id))->orderBy('name')->pluck('name', 'id')->all(),
            'sources' => ['' => __('Not recorded')] + CrmSource::query()->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $current?->crm_source_id))->orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => CrmStatus::query()->where('company_id', $companyId)->lead()
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $current?->crm_status_id))->orderBy('position')->pluck('name', 'id')->all(),
            'assignees' => ['' => __('Unassigned')] + $this->assignees($companyId, $current)->all(),
        ])->layout('layouts.admin');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(Company $company, ?Lead $existing): array
    {
        return [
            'name' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'regex:/^\+?\d{6,15}$/', Rule::unique('leads', 'phone')->where('company_id', $company->id)->ignore($existing?->id)],
            'email' => ['nullable', 'email', 'max:150'],
            'organization' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'sourceId' => ['nullable', Rule::in(CrmSource::query()->where('company_id', $company->id)
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $existing?->crm_source_id))->pluck('id')->map(fn (mixed $id): string => (string) $id)->all())],
            'serviceId' => ['nullable', Rule::in(CrmService::query()->where('company_id', $company->id)
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $existing?->crm_service_id))->pluck('id')->map(fn (mixed $id): string => (string) $id)->all())],
            'statusId' => ['required', Rule::in(CrmStatus::query()->where('company_id', $company->id)->lead()
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $existing?->crm_status_id))->pluck('id')->map(fn (mixed $id): string => (string) $id)->all())],
            'assignedTo' => ['nullable', Rule::in($this->assignees($company->id, $existing)->keys()->map(fn (mixed $id): string => (string) $id)->all())],
            'nextCallOn' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...$this->photoRules(),
        ];
    }

    /**
     * Who the lead can be assigned to: the company's CRM users, plus the current assignee so an edit keeps them.
     *
     * @return Collection<int, string>
     */
    private function assignees(int $companyId, ?Lead $lead): Collection
    {
        $people = Crm::assignableUsers($companyId)->pluck('name', 'id');
        if ($lead?->assigned_to && ! $people->has($lead->assigned_to)) {
            $people->put($lead->assigned_to, (string) User::query()->whereKey($lead->assigned_to)->value('name'));
        }

        return $people;
    }

    /** The header company, while it is still the one this page was opened for, visible and active. */
    private function contextCompany(): ?Company
    {
        $company = app(CompanyContext::class)->company();

        return $company?->is_active && $company->id === $this->companyId ? $company : null;
    }
}
