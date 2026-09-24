<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'employee_code' => fake()->unique()->bothify('EMP-####'),
            'job_title' => fake()->jobTitle(), 'department' => null, 'phone' => null];
    }
}
