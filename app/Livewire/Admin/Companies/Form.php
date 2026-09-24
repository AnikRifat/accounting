<?php

namespace App\Livewire\Admin\Companies;

use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'between:2,10', 'regex:/^[A-Z0-9]+$/', Rule::unique('companies', 'code')->ignore($this->companyId)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'isActive' => ['boolean'],
        ], ['code.regex' => __('Use only letters and digits.')]);
        DB::transaction(function () use ($data, $existing, $actor): void {
            $company = $existing ?? new Company;
            $company->fill(['name' => $data['name'], 'code' => $data['code'], 'address' => $data['address'] ?: null,
                'phone' => $data['phone'] ?: null, 'is_active' => $data['isActive']])->save();
            // A creator without all-company access must still be able to see what they created.
            if (! $existing && ! $actor->hasPermission('companies.all')) {
                $company->users()->attach($actor);
            }
        });
        session()->flash('success', __('Company saved.'));

        return redirect()->route('admin.companies.index');
    }

    public function render(): View
    {
        return view('livewire.admin.companies.form')->layout('layouts.admin');
    }
}
