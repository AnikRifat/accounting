<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_administrator_see_every_company(): void
    {
        $companies = Company::factory()->count(3)->create();
        foreach (['owner', 'administrator'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertEqualsCanonicalizing($companies->modelKeys(), $user->accessibleCompanyIds());
        }
    }

    public function test_other_roles_only_see_assigned_companies(): void
    {
        [$assigned, $other] = Company::factory()->count(2)->create();
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->companies()->attach($assigned);
        $this->assertSame([$assigned->id], $accountant->accessibleCompanyIds());
        $this->assertTrue($accountant->canAccessCompany($assigned->id));
        $this->assertFalse($accountant->canAccessCompany($other->id));
        $this->assertSame([], User::factory()->create(['role' => 'data-entry'])->accessibleCompanyIds());
    }

    public function test_denying_all_companies_access_falls_back_to_assignments(): void
    {
        [$assigned] = Company::factory()->count(2)->create();
        $admin = User::factory()->create(['role' => 'administrator', 'denied_permissions' => ['companies.all']]);
        $admin->companies()->attach($assigned);
        $this->assertSame([$assigned->id], $admin->accessibleCompanyIds());
    }
}
