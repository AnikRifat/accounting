<?php

namespace Tests\Feature;

use App\Enums\EntryType;
use App\Livewire\Admin\Dashboard;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00'));
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    public function test_dashboard_renders_for_each_role_with_role_specific_actions(): void
    {
        $company = Company::factory()->create();
        $this->bill($company, EntryType::Income, '4000', 1_000_00, '2026-09-02', ['description' => 'First receipt']);

        foreach (['owner', 'administrator', 'accountant', 'data-entry'] as $role) {
            $this->actingAs($this->user($role, $company))->get('/admin')->assertOk()
                ->assertSee('Income this month')->assertSee('Cash position')->assertSee('bKash')->assertSee('Receivable (owed to us)')->assertSee('First receipt')
                ->assertSee('href="'.route('admin.entries.create', 'income').'"', false);
        }
    }

    public function test_figures_are_scoped_to_visible_companies_and_exclude_voided_entries(): void
    {
        $mine = Company::factory()->create(['name' => 'Mine Ltd']);
        $other = Company::factory()->create(['name' => 'Other Ltd']);
        $customer = Party::factory()->for($mine)->create();
        $this->opening($mine, '1010', 20_000_00, '2026-04-01');
        $this->bill($mine, EntryType::Income, '4000', 10_000_00, '2026-09-02');
        $this->bill($mine, EntryType::Expense, '5100', 4_000_00, '2026-09-30', ['method' => '1010']);
        $credit = $this->bill($mine, EntryType::Income, '4000', 7_000_00, '2026-08-31', ['paid' => 2_000_00, 'method' => '1010', 'party' => $customer, 'due' => '2026-09-30']);
        $this->settle($credit, 1_000_00, '2026-09-05', '1020');
        $this->bill($mine, EntryType::Expense, '5200', 1_000_00, '2026-04-01');
        $this->bill($mine, EntryType::Income, '4900', 3_000_00, '2026-03-31');
        $this->void($this->bill($mine, EntryType::Income, '4000', 50_000_00, '2026-09-03'), 'Duplicate');
        $this->bill($other, EntryType::Income, '4000', 77_000_00, '2026-09-04', ['description' => 'Secret income']);
        Employee::factory()->for($mine)->count(2)->create();
        Employee::factory()->for($mine)->create(['is_active' => false]);
        Employee::factory()->for($other)->count(5)->create();
        $this->actingAs($this->user('accountant', $mine));

        Livewire::test(Dashboard::class)
            ->assertViewHas('months', fn (array $months): bool => array_column($months, 'income') === [0, 0, 0, 0, 7_000_00, 10_000_00]
                && array_column($months, 'expense') === [1_000_00, 0, 0, 0, 0, 4_000_00] && $months[0]['label'] === 'April 2026')
            ->assertViewHas('cash', fn (array $cash): bool => $cash['total'] === 20_000_00 + 10_000_00 - 4_000_00 + 2_000_00 + 1_000_00 - 1_000_00 + 3_000_00
                && count($cash['companies']) === 1 && collect($cash['companies'][0]['accounts'])->pluck('account.code')->all() === ['1000', '1010', '1020'])
            ->assertViewHas('activeEmployees', 2)
            ->assertViewHas('recent', fn ($recent): bool => $recent->count() === 7 && $recent->every(fn (JournalEntry $entry): bool => $entry->company_id === $mine->id && ! $entry->isVoided()))
            ->assertSee('Mine Ltd')->assertDontSee('Other Ltd')->assertDontSee('Secret income')
            ->set('company', (string) $other->id)->assertSet('company', '')->assertViewHas('activeEmployees', 2)->assertDontSee('Secret income');

        $this->actingAs($this->owner);
        Livewire::test(Dashboard::class)->assertViewHas('activeEmployees', 7)->assertSee('Secret income')
            ->set('company', (string) $mine->id)->assertViewHas('activeEmployees', 2)->assertDontSee('Secret income')
            ->assertViewHas('months', fn (array $months): bool => $months[5]['income'] === 10_000_00);
    }

    public function test_dues_blocks_show_scoped_totals_overdue_count_and_next_dues(): void
    {
        $mine = Company::factory()->create();
        $other = Company::factory()->create();
        $customer = Party::factory()->for($mine)->create(['name' => 'Rahman Traders']);
        $supplier = Party::factory()->for($mine)->create(['name' => 'Noor Rice Mills']);
        $foreign = Party::factory()->for($other)->create(['name' => 'Secret Buyer']);
        $late = $this->bill($mine, EntryType::Income, '4000', 9_000_00, '2026-08-01', ['paid' => 0, 'party' => $customer, 'due' => '2026-08-31']);
        $this->settle($late, 4_000_00, '2026-09-01');
        $this->bill($mine, EntryType::Expense, '5400', 3_000_00, '2026-09-01', ['paid' => 1_000_00, 'party' => $supplier, 'due' => '2026-09-23']);
        $this->bill($mine, EntryType::Income, '4000', 6_000_00, '2026-09-20', ['paid' => 0, 'party' => $customer, 'due' => '2026-10-20']);
        foreach (range(1, 4) as $day) {
            $this->bill($mine, EntryType::Income, '4900', 1_000_00, '2026-09-2'.$day, ['paid' => 0, 'party' => $customer, 'due' => '2026-11-0'.$day]);
        }
        $this->void($this->bill($mine, EntryType::Income, '4000', 99_000_00, '2026-09-02', ['paid' => 0, 'party' => $customer, 'due' => '2026-09-10']));
        $this->bill($other, EntryType::Income, '4000', 77_000_00, '2026-09-01', ['paid' => 0, 'party' => $foreign, 'due' => '2026-09-10']);
        $this->actingAs($this->user('accountant', $mine));

        Livewire::test(Dashboard::class)
            ->assertViewHas('dues', fn (array $dues): bool => [$dues['receivable'], $dues['payable'], $dues['overdue']] === [15_000_00, 2_000_00, 2]
                && $dues['next']->pluck('due_date')->map->toDateString()->all() === ['2026-08-31', '2026-09-23', '2026-10-20', '2026-11-01', '2026-11-02'])
            ->assertSee(route('admin.reports.dues', ['overdue' => 1]))->assertSee('Rahman Traders')->assertDontSee('Secret Buyer');

        $this->actingAs($this->user('data-entry', $mine));
        Livewire::test(Dashboard::class)->assertViewHas('dues', fn (array $dues): bool => $dues['overdue'] === 2)
            ->assertSee(route('admin.entries.index', ['status' => 'overdue']))->assertDontSee(route('admin.reports.dues', ['overdue' => 1]));

        $viewer = RolePermission::factory()->create(['role' => 'viewer', 'permissions' => ['admin.access', 'dashboard.view', 'accounts.view']]);
        $this->actingAs($this->user($viewer->role, $mine));
        Livewire::test(Dashboard::class)->assertViewHas('dues', null)->assertViewHas('months', null)->assertViewHas('recent', null)
            ->assertDontSee('Receivable (owed to us)')->assertSee('Cash position');
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
