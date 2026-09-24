<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable(['company_id', 'employee_code', 'name', 'designation', 'department', 'phone', 'monthly_salary', 'joined_on', 'is_active'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected $attributes = ['monthly_salary' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['monthly_salary' => 'integer', 'joined_on' => 'date', 'is_active' => 'boolean'];
    }

    /** Stores a plain Y-m-d so date comparisons behave the same on SQLite and MySQL. */
    protected function joinedOn(): Attribute
    {
        return Attribute::set(fn (mixed $value): ?string => $value === null || $value === '' ? null : Carbon::parse($value)->toDateString());
    }

    /** Every employee is a party of their company; name, phone and active status follow the employee. */
    protected static function booted(): void
    {
        static::created(function (Employee $employee): void {
            $employee->party()->create(['company_id' => $employee->company_id, ...$employee->partyAttributes()]);
        });
        static::updated(function (Employee $employee): void {
            if ($employee->wasChanged(['name', 'phone', 'is_active'])) {
                $employee->party()->firstOrFail()->update($employee->partyAttributes());
            }
        });
    }

    /** @return array{name: string, phone: ?string, is_active: bool} */
    private function partyAttributes(): array
    {
        return ['name' => $this->name, 'phone' => $this->phone, 'is_active' => $this->is_active];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Employees of the companies the user may access. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('company_id', $user->accessibleCompanyIds());
    }

    public function party(): HasOne
    {
        return $this->hasOne(Party::class);
    }
}
