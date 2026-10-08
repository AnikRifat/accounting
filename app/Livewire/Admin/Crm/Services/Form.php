<?php

namespace App\Livewire\Admin\Crm\Services;

use App\Models\Company;
use App\Models\CrmService;
use App\Support\CompanyContext;
use App\Support\Modules;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Form extends Component
{
    #[Locked]
    public ?int $serviceId = null;

    /** The service's own company, or the header company when creating. */
    #[Locked]
    public ?int $companyId = null;

    public string $name = '';

    public bool $isActive = true;

    public function mount(?CrmService $service = null): void
    {
        Gate::authorize('crm.setup.manage');
        $this->serviceId = $service?->exists ? $service->id : null;
        if ($this->serviceId) {
            abort_unless(auth()->user()->canAccessCompany($service->company_id, Modules::CRM), 404);
            [$this->companyId, $this->name, $this->isActive] = [$service->company_id, $service->name, $service->is_active];
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('crm.setup.manage');
        $existing = $this->serviceId ? CrmService::visibleTo(auth()->user())->findOrFail($this->serviceId) : null;
        $company = $existing?->company ?? $this->contextCompany();
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        $this->name = trim($this->name);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('crm_services', 'name')->where('company_id', $company->id)->ignore($existing?->id)],
            'isActive' => ['boolean'],
        ], [], ['name' => __('name')]);
        $attributes = ['name' => $data['name'], 'is_active' => $data['isActive']];
        $existing ? $existing->update($attributes) : CrmService::create(['company_id' => $company->id, ...$attributes]);
        session()->flash('success', __('Service saved.'));

        return redirect()->route('admin.crm.services.index');
    }

    public function render(): View
    {
        return view('livewire.admin.crm.services.form', [
            'companyName' => Company::visibleTo(auth()->user())->whereKey($this->companyId)->value('name'),
        ])->layout('layouts.admin');
    }

    /** The header company, while it is still the one this page was opened for, visible and active. */
    private function contextCompany(): ?Company
    {
        $company = app(CompanyContext::class)->company();

        return $company?->is_active && $company->id === $this->companyId ? $company : null;
    }
}
