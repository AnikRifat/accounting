<?php

namespace App\Livewire;

use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Off-canvas "new company" form, opened from the header switcher or the Companies list via `open-create-company`. */
class CreateCompanyDrawer extends Component
{
    public bool $open = false;

    public string $name = '';

    public string $code = '';

    public string $address = '';

    public string $phone = '';

    public bool $isActive = true;

    public function save(): void
    {
        Gate::authorize('companies.create');
        $this->code = strtoupper(trim($this->code));
        $this->name = trim($this->name);
        $data = $this->validate(Company::formRules(), ['code.regex' => __('Use only letters and digits.')]);
        try {
            $company = Company::createBy(auth()->user(), $data);
        } catch (UniqueConstraintViolationException) {
            // Another request took the code between validation and insert.
            $this->addError('code', __('validation.unique', ['attribute' => __('code')]));

            return;
        }
        app(CompanyContext::class)->select($company->id);
        session()->flash('success', __('Company :name created. Its chart of accounts, categories and payment methods are ready.', ['name' => $company->name]));
        $this->redirect(CompanyContext::returnUrl(), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.create-company-drawer');
    }
}
