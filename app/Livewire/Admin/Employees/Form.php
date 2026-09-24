<?php

namespace App\Livewire\Admin\Employees;

use App\Models\Company;
use App\Models\Employee;
use App\Support\CompanyContext;
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

    /** The employee's company, or the header company when creating. */
    #[Locked]
    public ?int $companyId = null;

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
            $this->companyId = $employee->company_id;
            $this->employeeCode = $employee->employee_code;
            $this->name = $employee->name;
            $this->designation = $employee->designation ?? '';
            $this->department = $employee->department ?? '';
            $this->phone = $employee->phone ?? '';
            $this->monthlySalary = Money::toInput($employee->monthly_salary);
            $this->joinedOn = $employee->joined_on?->toDateString() ?? '';
            $this->isActive = $employee->is_active;
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize($this->employeeId ? 'employees.update' : 'employees.create');
        $actor = auth()->user();
        $existing = $this->employeeId ? Employee::visibleTo($actor)->findOrFail($this->employeeId) : null;
        // An employee stays in their company; a new one goes to the header company the page was opened for.
        $context = app(CompanyContext::class)->company();
        $company = $existing?->company ?? ($context?->is_active && $context->id === $this->companyId ? $context : null);
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        foreach (['employeeCode', 'name', 'designation', 'department', 'phone', 'monthlySalary', 'joinedOn'] as $field) {
            $this->{$field} = trim($this->{$field});
        }
        $data = $this->validate([
            'employeeCode' => ['required', 'string', 'max:30', Rule::unique('employees', 'employee_code')->where('company_id', $company->id)->ignore($this->employeeId)],
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
            'employeeCode' => __('employee code'), 'monthlySalary' => __('monthly salary'), 'joinedOn' => __('joining date'),
        ]);
        $attributes = [
            'company_id' => $company->id, 'employee_code' => $data['employeeCode'], 'name' => $data['name'],
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
            'companyName' => Company::visibleTo(auth()->user())->whereKey($this->companyId)->value('name'),
        ])->layout('layouts.admin');
    }
}
