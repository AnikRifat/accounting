<?php

namespace App\Livewire\Admin\Employees;

use App\Models\Employee;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        Gate::authorize('employees.view');
        $search = mb_substr(trim($this->search), 0, 100);

        return view('livewire.admin.employees.index', [
            'employees' => Employee::query()->whereIn('company_id', app(CompanyContext::class)->companyIds())->with('company')
                ->when($this->status !== '', fn ($query) => $query->where('is_active', $this->status === 'active'))
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('employee_code', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')))
                ->orderBy('name')->paginate(15),
        ])->layout('layouts.admin');
    }
}
