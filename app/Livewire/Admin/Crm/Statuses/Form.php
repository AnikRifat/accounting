<?php

namespace App\Livewire\Admin\Crm\Statuses;

use App\Enums\CrmStatusType;
use App\Models\Company;
use App\Models\CrmStatus;
use App\Support\CompanyContext;
use App\Support\Crm;
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
    public ?int $statusId = null;

    /** The status's own company, or the header company when creating. */
    #[Locked]
    public ?int $companyId = null;

    /** Fixed once created: leads and calls read a status by its type. */
    public string $type = 'lead';

    public string $name = '';

    public string $tone = 'neutral';

    public string $position = '0';

    public bool $isClosed = false;

    public bool $isActive = true;

    public function mount(?CrmStatus $status = null, string $type = 'lead'): void
    {
        Gate::authorize('crm.setup.manage');
        $this->statusId = $status?->exists ? $status->id : null;
        if ($this->statusId) {
            abort_unless(auth()->user()->canAccessCompany($status->company_id), 404);
            $this->companyId = $status->company_id;
            [$this->type, $this->name, $this->tone, $this->position, $this->isClosed, $this->isActive]
                = [$status->type->value, $status->name, $status->tone, (string) $status->position, $status->is_closed, $status->is_active];
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
            $this->type = CrmStatusType::tryFrom($type)?->value ?? 'lead';
            $this->position = (string) ((int) CrmStatus::query()->where('company_id', $this->companyId)->where('type', $this->type)->max('position') + 1);
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('crm.setup.manage');
        $existing = $this->statusId ? CrmStatus::visibleTo(auth()->user())->findOrFail($this->statusId) : null;
        $company = $existing?->company ?? $this->contextCompany();
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        $this->name = trim($this->name);
        $type = $existing?->type->value ?? $this->type;
        $data = $this->validate([
            'type' => ['required', Rule::enum(CrmStatusType::class)],
            'name' => ['required', 'string', 'max:60', Rule::unique('crm_statuses', 'name')->where('company_id', $company->id)->where('type', $type)->ignore($existing?->id)],
            'tone' => ['required', Rule::in(Crm::TONES)],
            'position' => ['required', 'integer', 'min:0', 'max:999'],
            'isClosed' => ['boolean'],
            'isActive' => ['boolean'],
        ], [], ['name' => __('name'), 'tone' => __('colour'), 'position' => __('order')]);
        $attributes = ['name' => $data['name'], 'tone' => $data['tone'], 'position' => (int) $data['position'],
            'is_closed' => $type === CrmStatusType::Lead->value && $data['isClosed'], 'is_active' => $data['isActive']];
        $existing ? $existing->update($attributes) : CrmStatus::create(['company_id' => $company->id, 'type' => $type, ...$attributes]);
        session()->flash('success', __('Status saved.'));

        return redirect()->route('admin.crm.statuses.index', $type === 'lead' ? [] : ['type' => $type]);
    }

    public function render(): View
    {
        return view('livewire.admin.crm.statuses.form', [
            'companyName' => Company::visibleTo(auth()->user())->whereKey($this->companyId)->value('name'),
            'types' => collect(CrmStatusType::cases())->mapWithKeys(fn (CrmStatusType $type): array => [$type->value => $type->label()])->all(),
            'tones' => ['neutral' => __('Grey'), 'info' => __('Blue'), 'primary' => __('Green'), 'success' => __('Emerald'), 'warning' => __('Amber'), 'danger' => __('Red')],
        ])->layout('layouts.admin');
    }

    /** The header company, while it is still the one this page was opened for, visible and active. */
    private function contextCompany(): ?Company
    {
        $company = app(CompanyContext::class)->company();

        return $company?->is_active && $company->id === $this->companyId ? $company : null;
    }
}
