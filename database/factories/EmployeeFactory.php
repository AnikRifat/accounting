<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'employee_code' => fake()->unique()->bothify('EMP-####'),
            'name' => fake()->name(), 'designation' => fake()->jobTitle(), 'department' => null, 'phone' => null,
            'monthly_salary' => fake()->numberBetween(15_000, 150_000) * 100, 'joined_on' => fake()->date(), 'is_active' => true];
    }
}
