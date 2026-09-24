<?php

namespace Database\Factories;

use App\Models\RolePermission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RolePermission> */
class RolePermissionFactory extends Factory
{
    public function definition(): array
    {
        return ['role' => fake()->unique()->slug(2), 'label' => fake()->jobTitle(), 'permissions' => [], 'is_active' => true];
    }
}
