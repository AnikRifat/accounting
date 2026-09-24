<?php

namespace App\Livewire\Admin\Parties;

use App\Models\Company;
use App\Models\Party;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Form extends Component
{
    #[Locked]
    public ?int $partyId = null;

    /** The company the page was opened for: the party's own, or the header company when creating. */
    #[Locked]
    public ?int $companyId = null;

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
            // Employee parties change only through the employee's account.
            abort_unless(auth()->user()->canAccessCompany($party->company_id) && ! $party->isEmployee(), 404);
            $this->companyId = $party->company_id;
            $this->name = $party->name;
            $this->phone = $party->phone ?? '';
            $this->address = $party->address ?? '';
            $this->notes = $party->notes ?? '';
            $this->isActive = $party->is_active;
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize($this->partyId ? 'parties.update' : 'parties.create');
        $existing = $this->partyId ? Party::visibleTo(auth()->user())->whereNull('user_id')->findOrFail($this->partyId) : null;
        // A party stays in its company; a new one goes to the header company the page was opened for.
        $company = $existing?->company ?? $this->contextCompany();
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        foreach (['name', 'phone', 'address', 'notes'] as $field) {
            $this->{$field} = trim($this->{$field});
        }
        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'isActive' => ['boolean'],
        ], [], ['name' => __('name'), 'phone' => __('phone'), 'address' => __('address'), 'notes' => __('notes')]);
        $attributes = ['name' => $data['name'], 'phone' => $data['phone'] ?: null, 'address' => $data['address'] ?: null,
            'notes' => $data['notes'] ?: null, 'is_active' => $data['isActive']];
        $existing ? $existing->update($attributes) : Party::create(['company_id' => $company->id, ...$attributes]);
        session()->flash('success', __('Party saved.'));

        return redirect()->route('admin.parties.index');
    }

    public function render(): View
    {
        return view('livewire.admin.parties.form', [
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
