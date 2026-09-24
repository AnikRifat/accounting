<?php

namespace App\Livewire;

use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Header control that sets the company scope for every page. */
class CompanySwitcher extends Component
{
    public string $selected = '';

    public function mount(): void
    {
        $this->selected = (string) (app(CompanyContext::class)->selectedId() ?? '');
    }

    public function updatedSelected(): void
    {
        $context = app(CompanyContext::class);
        if (! $context->select($this->selected === '' ? null : (int) $this->selected)) {
            $this->selected = (string) ($context->selectedId() ?? '');

            return;
        }
        $this->redirect(CompanyContext::returnUrl(), navigate: true);
    }

    public function render(): View
    {
        $companies = app(CompanyContext::class)->options();

        return view('livewire.company-switcher', [
            'companies' => $companies,
            'options' => ['' => __('All companies')] + $companies->mapWithKeys(fn (Company $company): array => [
                $company->id => $company->name.($company->is_active ? '' : ' ('.__('inactive').')'),
            ])->all(),
        ]);
    }
}
