<?php

namespace App\Livewire\Admin\Companies;

use App\Models\Company;
use App\Support\Modules;
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

    public string $mailFromAddress = '';

    public string $mailFromName = '';

    public bool $isActive = true;

    public bool $salesEnabled = true;

    public bool $crmEnabled = true;

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
            $this->mailFromAddress = $company->mail_from_address ?? '';
            $this->mailFromName = $company->mail_from_name ?? '';
            $this->isActive = $company->is_active;
            $this->salesEnabled = $company->sales_enabled;
            $this->crmEnabled = $company->crm_enabled;
        }
    }

    public function save(): Redirector|RedirectResponse
    {
        Gate::authorize($this->companyId ? 'companies.update' : 'companies.create');
        $actor = auth()->user();
        $existing = $this->companyId ? Company::visibleTo($actor)->findOrFail($this->companyId) : null;
        [$this->code, $this->mailFromAddress, $this->mailFromName] = [strtoupper(trim($this->code)), trim($this->mailFromAddress), trim($this->mailFromName)];
        $data = $this->validate([...Company::formRules($this->companyId), ...Company::senderRules(), 'salesEnabled' => ['boolean'], 'crmEnabled' => ['boolean']],
            ['code.regex' => __('Use only letters and digits.')], ['mailFromAddress' => __('from address'), 'mailFromName' => __('from name')]);
        if ($existing) {
            $existing->update(['name' => $data['name'], 'code' => $data['code'], 'address' => $data['address'] ?: null,
                'phone' => $data['phone'] ?: null, 'mail_from_address' => $data['mailFromAddress'] ?: null,
                'mail_from_name' => $data['mailFromName'] ?: null, 'is_active' => $data['isActive'],
                'sales_enabled' => $data['salesEnabled'], 'crm_enabled' => $data['crmEnabled']]);
        } else {
            Company::createBy($actor, $data);
        }
        session()->flash('success', __('Company saved.'));

        return redirect()->route('admin.companies.index');
    }

    public function render(): View
    {
        return view('livewire.admin.companies.form', [
            'switchableModules' => array_filter(['salesEnabled' => Modules::enabled(Modules::SALES) ? [__('Sales'), __('Quotations, invoices, bills and recurring invoices.')] : null,
                'crmEnabled' => Modules::enabled(Modules::CRM) ? [__('CRM'), __('Leads, calls, emails and follow-ups.')] : null]),
        ])->layout('layouts.admin');
    }
}
