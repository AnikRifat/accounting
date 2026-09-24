<?php

namespace App\Livewire\Admin\Employees;

use App\Models\Company;
use App\Models\Employee;
use App\Support\Money;
use Closure;
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
    public ?int $employeeId = null;

    public string $companyId = '';

    public string $employeeCode = '';

    public string $name = '';

    public string $designation = '';

    public string $department = '';

    public string $phone = '';

    public string $monthlySalary = '0.00';

    public string $joinedOn = '';

    public bool $isActive = true;

    public function mount(?Employee $employee = null): void
    {
        $this->employeeId = $employee?->exists ? $employee->id : null;
        Gate::authorize($this->employeeId ? 'employees.update' : 'employees.create');
        $actor = auth()->user();
        if ($this->employeeId) {
            abort_unless($actor->canAccessCompany($employee->company_id), 404);
            $this->companyId = (string) $employee->company_id;
            $this->employeeCode = $employee->employee_code;
            $this->name = $employee->name;
            $this->designation = $employee->designation ?? '';
            $this->department = $employee->department ?? '';
            $this->phone = $employee->phone ?? '';
            $this->monthlySalary = Money::toInput($employee->monthly_salary);
            $this->joinedOn = $employee->joined_on?->toDateString() ?? '';
            $this->isActive = $employee->is_active;
        } elseif (count($visible = $actor->accessibleCompanyIds()) === 1) {
            $this->companyId = (string) $visible[0];
        }
    }

    public function save(): Redirector|RedirectResponse
    {
        Gate::authorize($this->employeeId ? 'employees.update' : 'employees.create');
        $actor = auth()->user();
        $existing = $this->employeeId ? Employee::visibleTo($actor)->findOrFail($this->employeeId) : null;
        // An employee stays in the company whose entries may already reference them.
        if ($existing) {
            $this->companyId = (string) $existing->company_id;
        }
        foreach (['employeeCode', 'name', 'designation', 'department', 'phone', 'monthlySalary', 'joinedOn'] as $field) {
            $this->{$field} = trim($this->{$field});
        }
        // Uniqueness is only checked inside an accessible company, so a forged company id cannot probe other companies' codes.
        $accessibleCompanyId = $actor->canAccessCompany((int) $this->companyId) ? (int) $this->companyId : 0;
        $data = $this->validate([
            'companyId' => ['bail', 'required', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($actor): void {
                if (! $actor->canAccessCompany((int) $value)) {
                    $fail(__('Choose a company you have access to.'));
                }
            }],
            'employeeCode' => ['required', 'string', 'max:30', Rule::unique('employees', 'employee_code')->where('company_id', $accessibleCompanyId)->ignore($this->employeeId)],
            'name' => ['required', 'string', 'max:150'],
            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'monthlySalary' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Money::isValidInput($value)) {
                    $fail(__('Enter an amount in taka with up to two decimals, e.g. 25,000.50.'));
                }
            }],
            'joinedOn' => ['nullable', 'date_format:Y-m-d'],
            'isActive' => ['boolean'],
        ], [], [
            'companyId' => __('company'), 'employeeCode' => __('employee code'), 'monthlySalary' => __('monthly salary'), 'joinedOn' => __('joining date'),
        ]);
        $attributes = [
            'company_id' => (int) $data['companyId'], 'employee_code' => $data['employeeCode'], 'name' => $data['name'],
            'designation' => $data['designation'] ?: null, 'department' => $data['department'] ?: null, 'phone' => $data['phone'] ?: null,
            'monthly_salary' => Money::toPaisa($data['monthlySalary']), 'joined_on' => $data['joinedOn'] ?: null, 'is_active' => $data['isActive'],
        ];
        // The employee's party is written by model events, so it commits or rolls back with the employee.
        DB::transaction(fn () => $existing ? $existing->update($attributes) : Employee::create($attributes));
        session()->flash('success', __('Employee saved.'));

        return redirect()->route('admin.employees.index');
    }

    public function render(): View
    {
        return view('livewire.admin.employees.form', [
            'companyOptions' => ['' => __('Select a company')] + Company::visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id')->all(),
        ])->layout('layouts.admin');
    }
}
