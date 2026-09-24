<?php

namespace App\Livewire\Admin;

use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Asks for one active company before a create page opens while the header is on "All companies". */
class ChooseCompany extends Component
{
    #[Url, Locked]
    public string $next = '';

    public function choose(int $companyId): void
    {
        $context = app(CompanyContext::class);
        abort_unless($context->options()->where('is_active', true)->contains('id', $companyId), 404);
        $context->select($companyId);
        $this->redirect($this->safeNext(), navigate: true);
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
            'companies' => app(CompanyContext::class)->options()->where('is_active', true),
        ])->layout('layouts.admin');
    }
}
