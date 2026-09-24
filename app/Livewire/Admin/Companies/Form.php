<?php

namespace App\Livewire\Admin\Companies;

use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Form extends Component
{
    #[Locked]
    public ?int $companyId = null;

    public string $name = '';

    public string $code = '';

    public string $address = '';

    public string $phone = '';

    public bool $isActive = true;

    public function mount(?Company $company = null): void
    {
        $this->companyId = $company?->exists ? $company->id : null;
        Gate::authorize($this->companyId ? 'companies.update' : 'companies.create');
        if ($this->companyId) {
            abort_unless(auth()->user()->canAccessCompany($this->companyId), 404);
            $this->name = $company->name;
            $this->code = $company->code;
            $this->address = $company->address ?? '';
            $this->phone = $company->phone ?? '';
            $this->isActive = $company->is_active;
        }
    }

    public function save(): Redirector|RedirectResponse
    {
        Gate::authorize($this->companyId ? 'companies.update' : 'companies.create');
        $actor = auth()->user();
        $existing = $this->companyId ? Company::visibleTo($actor)->findOrFail($this->companyId) : null;
        $this->code = strtoupper(trim($this->code));
        $data = $this->validate(Company::formRules($this->companyId), ['code.regex' => __('Use only letters and digits.')]);
        if ($existing) {
            $existing->update(['name' => $data['name'], 'code' => $data['code'], 'address' => $data['address'] ?: null,
                'phone' => $data['phone'] ?: null, 'is_active' => $data['isActive']]);
        } else {
            Company::createBy($actor, $data);
        }
        session()->flash('success', __('Company saved.'));

        return redirect()->route('admin.companies.index');
    }

    public function render(): View
    {
        return view('livewire.admin.companies.form')->layout('layouts.admin');
    }
}
