<?php

namespace App\Livewire\Admin;

use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Asks for one company when a page needs a single company while the header is on "All companies" (create pages: active only). */
class ChooseCompany extends Component
{
    #[Url, Locked]
    public string $next = '';

    public function choose(int $companyId): void
    {
        abort_unless($this->companies()->contains('id', $companyId), 404);
        app(CompanyContext::class)->select($companyId);
        $this->redirect($this->safeNext(), navigate: true);
    }

    /** Create pages and create sheets need an active company; reports can open any visible one, including a closed company. */
    private function forCreate(): bool
    {
        return preg_match('#/(create|settle)(/|\?|$)|[?&]sheet=(create|settle)#', $this->next) === 1;
    }

    /** @return Collection<int, Company> */
    private function companies(): Collection
    {
        $companies = app(CompanyContext::class)->options();

        return $this->forCreate() ? $companies->where('is_active', true)->values() : $companies;
    }

    /** Only same-site admin paths are followed, so the picker can't be used as an open redirect. */
    private function safeNext(): string
    {
        return str_starts_with($this->next, '/admin/') && ! str_contains($this->next, '//') && ! str_contains($this->next, '\\')
            ? $this->next : route('admin.dashboard');
    }

    public function render(): View
    {
        return view('livewire.admin.choose-company', [
            'companies' => $this->companies(),
            'forCreate' => $this->forCreate(),
        ])->layout('layouts.admin');
    }
}
