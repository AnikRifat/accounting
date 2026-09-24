<?php

namespace App\Livewire\Admin\Parties;

use App\Models\Company;
use App\Models\Party;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Form extends Component
{
    #[Locked]
    public ?int $partyId = null;

    public string $companyId = '';

    public string $name = '';

    public string $phone = '';

    public string $address = '';

    public string $notes = '';

    public bool $isActive = true;

    public function mount(?Party $party = null): void
    {
        $this->partyId = $party?->exists ? $party->id : null;
        Gate::authorize($this->partyId ? 'parties.update' : 'parties.create');
        if ($this->partyId) {
            // Employee parties change only through their employee.
            abort_unless(auth()->user()->canAccessCompany($party->company_id) && ! $party->isEmployee(), 404);
            $this->companyId = (string) $party->company_id;
            $this->name = $party->name;
            $this->phone = $party->phone ?? '';
            $this->address = $party->address ?? '';
            $this->notes = $party->notes ?? '';
            $this->isActive = $party->is_active;
        } else {
            $companyIds = $this->activeCompanies()->pluck('id')->all();
            $remembered = (int) session('ledger.company_id');
            $this->companyId = (string) (in_array($remembered, $companyIds, true) ? $remembered : ($companyIds[0] ?? ''));
        }
    }

    public function save(): Redirector|RedirectResponse
    {
        Gate::authorize($this->partyId ? 'parties.update' : 'parties.create');
        $existing = $this->partyId ? Party::visibleTo(auth()->user())->whereNull('employee_id')->findOrFail($this->partyId) : null;
        // A party stays in the company whose entries may already reference it.
        if ($existing) {
            $this->companyId = (string) $existing->company_id;
        }
        foreach (['name', 'phone', 'address', 'notes'] as $field) {
            $this->{$field} = trim($this->{$field});
        }
        $data = $this->validate([
            'companyId' => ['required', Rule::in($existing ? [$existing->company_id] : $this->activeCompanies()->pluck('id')->all())],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'isActive' => ['boolean'],
        ], [], ['companyId' => __('company'), 'name' => __('name'), 'phone' => __('phone'), 'address' => __('address'), 'notes' => __('notes')]);
        $attributes = ['name' => $data['name'], 'phone' => $data['phone'] ?: null, 'address' => $data['address'] ?: null,
            'notes' => $data['notes'] ?: null, 'is_active' => $data['isActive']];
        $existing ? $existing->update($attributes) : Party::create(['company_id' => (int) $data['companyId'], ...$attributes]);
        session()->flash('success', __('Party saved.'));

        return redirect()->route('admin.parties.index');
    }

    public function render(): View
    {
        $companies = $this->partyId ? Company::visibleTo(auth()->user()) : $this->activeCompanies();

        return view('livewire.admin.parties.form', [
            'companyOptions' => ['' => __('Select a company')] + $companies->orderBy('name')->pluck('name', 'id')->all(),
        ])->layout('layouts.admin');
    }

    /** New parties can only be added to active companies the user can access. */
    private function activeCompanies(): Builder
    {
        return Company::visibleTo(auth()->user())->where('is_active', true);
    }
}
