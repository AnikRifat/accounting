<?php

namespace Tests\Feature;

use App\Enums\EntryType;
use App\Livewire\Admin\Dashboard;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00'));
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    private function user(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    private function record(Company $company, EntryType $type, string $debit, string $credit, int $amount, string $date, array $extra = []): JournalEntry
    {
        return app(LedgerService::class)->record($company, $type, $extra + ['entry_date' => $date, 'amount' => $amount,
            'debit_account_id' => $company->accounts()->where('code', $debit)->value('id'),
            'credit_account_id' => $company->accounts()->where('code', $credit)->value('id')], $this->owner);
    }

    public function test_dashboard_renders_for_each_role_with_role_specific_actions(): void
    {
        $company = Company::factory()->create();
        $this->record($company, EntryType::Income, '1000', '4000', 1_000_00, '2026-09-02', ['description' => 'First receipt']);

        foreach (['owner', 'administrator', 'accountant', 'data-entry'] as $role) {
            $this->actingAs($this->user($role, $company))->get('/admin')->assertOk()
                ->assertSee('Income this month')->assertSee('Cash and bank balances')->assertSee('First receipt')
                ->assertSee('href="'.route('admin.entries.create', 'income').'"', false);
        }
    }

    public function test_figures_are_scoped_to_visible_companies_and_exclude_voided_entries(): void
    {
        $mine = Company::factory()->create(['name' => 'Mine Ltd']);
        $other = Company::factory()->create(['name' => 'Other Ltd']);
        app(LedgerService::class)->recordOpening($mine->accounts()->where('code', '1010')->sole(), 20_000_00, '2026-04-01', $this->owner);
        $this->record($mine, EntryType::Income, '1000', '4000', 10_000_00, '2026-09-02');
        $this->record($mine, EntryType::Expense, '5100', '1010', 4_000_00, '2026-09-30');
        $this->record($mine, EntryType::Income, '1010', '4000', 7_000_00, '2026-08-31');
        $this->record($mine, EntryType::Expense, '5200', '1000', 1_000_00, '2026-04-01');
        $this->record($mine, EntryType::Income, '1000', '4900', 3_000_00, '2026-03-31');
        app(LedgerService::class)->void($this->record($mine, EntryType::Income, '1000', '4000', 50_000_00, '2026-09-03'), 'Duplicate', $this->owner);
        $this->record($other, EntryType::Income, '1000', '4000', 77_000_00, '2026-09-04', ['description' => 'Secret income']);
        Employee::factory()->for($mine)->count(2)->create();
        Employee::factory()->for($mine)->create(['is_active' => false]);
        Employee::factory()->for($other)->count(5)->create();
        $this->actingAs($this->user('accountant', $mine));

        Livewire::test(Dashboard::class)
            ->assertViewHas('months', fn (array $months): bool => array_column($months, 'income') === [0, 0, 0, 0, 7_000_00, 10_000_00]
                && array_column($months, 'expense') === [1_000_00, 0, 0, 0, 0, 4_000_00] && $months[0]['label'] === 'April 2026')
            ->assertViewHas('cash', fn (array $cash): bool => $cash['total'] === 20_000_00 + 10_000_00 - 4_000_00 + 7_000_00 - 1_000_00 + 3_000_00
                && count($cash['companies']) === 1)
            ->assertViewHas('activeEmployees', 2)
            ->assertViewHas('recent', fn ($recent): bool => $recent->count() === 6 && $recent->every(fn (JournalEntry $entry): bool => $entry->company_id === $mine->id && ! $entry->isVoided()))
            ->assertSee('Mine Ltd')->assertDontSee('Other Ltd')->assertDontSee('Secret income')
            ->set('company', (string) $other->id)->assertSet('company', '')->assertViewHas('activeEmployees', 2)->assertDontSee('Secret income');

        $this->actingAs($this->owner);
        Livewire::test(Dashboard::class)->assertViewHas('activeEmployees', 7)->assertSee('Secret income')
            ->set('company', (string) $mine->id)->assertViewHas('activeEmployees', 2)->assertDontSee('Secret income')
            ->assertViewHas('months', fn (array $months): bool => $months[5]['income'] === 10_000_00);
    }

    public function test_first_run_empty_state_depends_on_the_role(): void
    {
        $this->actingAs($this->owner)->get('/admin')->assertOk()
            ->assertSee('Create your first company')->assertSee('href="'.route('admin.companies.create').'"', false);

        Company::factory()->create();
        $this->actingAs($this->user('accountant'))->get('/admin')->assertOk()
            ->assertSee('Ask your administrator to assign you a company.')->assertDontSee('Create your first company')
            ->assertDontSee('Income this month');
    }
}
