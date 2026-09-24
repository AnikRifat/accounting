<?php

namespace Tests\Feature;

use App\Livewire\Admin\Reports\TrialBalance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_balanced_books_and_refuses_to_run_twice(): void
    {
        $this->seed(DemoSeeder::class);

        $owner = User::where('email', DemoSeeder::OWNER_EMAIL)->sole();
        $this->assertSame('owner', $owner->role);
        $this->assertFalse(Hash::check('password', $owner->password));
        $this->assertSame(2, User::where('email', DemoSeeder::ACCOUNTANT_EMAIL)->sole()->companies()->count());
        $this->assertSame(1, User::where('email', DemoSeeder::DATA_ENTRY_EMAIL)->sole()->companies()->count());
        $this->assertSame(4, Company::count());
        $this->assertSame(2, JournalEntry::whereNotNull('voided_at')->whereNotNull('void_reason')->count());
        $this->assertGreaterThan(0, JournalEntry::whereNotNull('employee_id')->count());

        $this->actingAs($owner);
        foreach (Company::all() as $company) {
            $employees = Employee::where('company_id', $company->id)->count();
            $this->assertTrue($employees >= 3 && $employees <= 5, "{$company->code} has {$employees} employees.");
            $this->assertGreaterThanOrEqual(3, $company->accounts()->where('is_cash', true)->count());
            Livewire::test(TrialBalance::class)->set('company', (string) $company->id)
                ->assertViewHas('balanced', true)->assertViewHas('debitTotal', fn (int $total): bool => $total > 0);
        }

        $entries = JournalEntry::count();
        $this->seed(DemoSeeder::class);
        $this->assertSame([4, $entries, $owner->password], [Company::count(), JournalEntry::count(), $owner->fresh()->password]);
    }

    public function test_demo_seeder_refuses_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->app->make(DemoSeeder::class)->setContainer($this->app)->__invoke();

        $this->assertSame(0, Company::count());
        $this->assertFalse(User::where('email', DemoSeeder::OWNER_EMAIL)->exists());
    }
}
