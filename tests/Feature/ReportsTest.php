<?php

namespace Tests\Feature;

use App\Enums\EntryType;
use App\Livewire\Admin\Entries\Index as EntriesIndex;
use App\Livewire\Admin\Reports\AccountLedger;
use App\Livewire\Admin\Reports\EmployeeCost;
use App\Livewire\Admin\Reports\IncomeStatement;
use App\Livewire\Admin\Reports\TrialBalance;
use App\Models\Account;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
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

    private function account(Company $company, string $code): Account
    {
        return $company->accounts()->where('code', $code)->sole();
    }

    private function record(Company $company, EntryType $type, string $debit, string $credit, int $amount, string $date, array $extra = []): JournalEntry
    {
        return app(LedgerService::class)->record($company, $type, $extra + ['entry_date' => $date, 'amount' => $amount,
            'debit_account_id' => $this->account($company, $debit)->id, 'credit_account_id' => $this->account($company, $credit)->id], $this->owner);
    }

    public function test_income_statement_totals_per_company_and_consolidated_exclude_voided_and_invisible_companies(): void
    {
        $alpha = Company::factory()->create(['name' => 'Alpha Ltd', 'code' => 'ALP']);
        $beta = Company::factory()->create(['name' => 'Beta Ltd', 'code' => 'BET']);
        $hidden = Company::factory()->create(['name' => 'Hidden Ltd', 'code' => 'HID']);
        app(LedgerService::class)->recordOpening($this->account($alpha, '1010'), 900_000_00, '2026-09-01', $this->owner);
        $this->record($alpha, EntryType::Income, '1000', '4000', 100_000_00, '2026-09-02');
        $this->record($alpha, EntryType::Expense, '5100', '1000', 30_000_00, '2026-09-03');
        $this->record($alpha, EntryType::Transfer, '1000', '1010', 12_000_00, '2026-09-04');
        app(LedgerService::class)->void($this->record($alpha, EntryType::Income, '1000', '4000', 50_000_00, '2026-09-05'), 'Duplicate', $this->owner);
        $this->record($beta, EntryType::Income, '1010', '4000', 40_000_00, '2026-09-06');
        $this->record($beta, EntryType::Expense, '5100', '1010', 5_000_00, '2026-09-07');
        $this->record($beta, EntryType::Expense, '5000', '1010', 20_000_00, '2026-09-08');
        $this->record($hidden, EntryType::Income, '1000', '4000', 77_000_00, '2026-09-09');
        $this->actingAs($this->user('accountant', $alpha, $beta));

        $consolidated = Livewire::test(IncomeStatement::class)
            ->assertViewHas('totalIncome', [$alpha->id => 100_000_00, $beta->id => 40_000_00])
            ->assertViewHas('totalExpense', [$alpha->id => 30_000_00, $beta->id => 25_000_00])
            ->assertViewHas('income', fn ($rows): bool => $rows->count() === 1 && $rows[0]['name'] === 'Sales & Service Income' && $rows[0]['total'] === 140_000_00)
            ->assertViewHas('expense', fn ($rows): bool => $rows->pluck('name')->all() === ['Salaries & Wages', 'Office Rent'])
            ->assertSeeInOrder(['Net profit / (loss)', '৳70,000.00', '৳15,000.00', '৳85,000.00'])
            ->assertDontSee('Hidden Ltd')->assertDontSee('৳77,000.00');

        $consolidated->set('company', (string) $alpha->id)
            ->assertViewHas('totalIncome', [$alpha->id => 100_000_00])->assertViewHas('totalExpense', [$alpha->id => 30_000_00])
            ->assertSee('৳70,000.00')->assertDontSee('৳85,000.00');

        $consolidated->set('company', (string) $hidden->id)
            ->assertViewHas('income', fn ($rows): bool => $rows->isEmpty())->assertViewHas('columns', fn ($columns): bool => $columns->isEmpty())
            ->assertDontSee('৳77,000.00')->assertSee('No income or expense was posted in this period.');
    }

    public function test_period_presets_follow_the_bangladesh_fiscal_year(): void
    {
        $this->actingAs($this->owner);
        $component = Livewire::test(IncomeStatement::class)->assertSet('from', '2026-09-01')->assertSet('to', '2026-09-30');
        foreach ([
            'last_month' => ['2026-08-01', '2026-08-31'],
            'this_fiscal_year' => ['2026-07-01', '2027-06-30'],
            'last_fiscal_year' => ['2025-07-01', '2026-06-30'],
        ] as $preset => [$from, $to]) {
            $component->set('period', $preset)->assertSet('from', $from)->assertSet('to', $to);
        }

        $this->assertSame(['2025-07-01', '2026-06-30'], IncomeStatement::presetRange('this_fiscal_year', CarbonImmutable::parse('2026-03-10')));
        $this->assertSame(['2026-02-01', '2026-02-28'], IncomeStatement::presetRange('last_month', CarbonImmutable::parse('2026-03-31')));
    }

    public function test_custom_periods_include_both_boundary_dates_and_reject_reversed_dates(): void
    {
        $company = Company::factory()->create();
        $this->record($company, EntryType::Income, '1000', '4000', 1_000_00, '2026-07-31');
        $this->record($company, EntryType::Income, '1000', '4000', 100_00, '2026-08-01');
        $this->record($company, EntryType::Income, '1000', '4000', 200_00, '2026-08-31');
        $this->record($company, EntryType::Income, '1000', '4000', 5_000_00, '2026-09-01');
        $this->actingAs($this->owner);

        Livewire::test(IncomeStatement::class)->set('from', '2026-08-01')->assertSet('period', 'custom')->set('to', '2026-08-31')
            ->assertViewHas('totalIncome', [$company->id => 300_00])
            ->set('from', '2026-09-02')->assertHasErrors('to')->assertViewHas('income', fn ($rows): bool => $rows->isEmpty());
    }

    public function test_account_ledger_shows_opening_running_and_closing_balances(): void
    {
        $company = Company::factory()->create();
        $cash = $this->account($company, '1000');
        app(LedgerService::class)->recordOpening($cash, 10_000_00, '2026-07-01', $this->owner);
        $this->record($company, EntryType::Income, '1000', '4000', 5_000_00, '2026-08-15');
        $expense = $this->record($company, EntryType::Expense, '5300', '1000', 1_000_00, '2026-09-02');
        $voided = app(LedgerService::class)->void($this->record($company, EntryType::Expense, '5300', '1000', 500_00, '2026-09-05'), 'Typo', $this->owner);
        $this->record($company, EntryType::Income, '1000', '4000', 2_000_00, '2026-09-10');
        $this->record($company, EntryType::Transfer, '1010', '1000', 3_000_00, '2026-09-20');
        $this->actingAs($this->user('accountant', $company));

        Livewire::test(AccountLedger::class)->assertSet('company', (string) $company->id)->set('account', (string) $cash->id)
            ->assertViewHas('report', fn (array $report): bool => $report['opening'] === 15_000_00
                && array_column($report['rows'], 'balance') === [14_000_00, 16_000_00, 13_000_00]
                && [$report['debit'], $report['credit'], $report['closing']] === [2_000_00, 4_000_00, 13_000_00]
                && $report['closing'] === app(LedgerService::class)->balance($cash, CarbonImmutable::parse('2026-09-30')))
            ->assertSee(route('admin.entries.edit', $expense->id))->assertDontSee($voided->number);

        $auditor = RolePermission::factory()->create(['role' => 'auditor', 'permissions' => ['admin.access', 'reports.view']]);
        $this->actingAs($this->user($auditor->role, $company));
        Livewire::test(AccountLedger::class)->set('account', (string) $cash->id)
            ->assertSee($expense->number)->assertDontSee(route('admin.entries.edit', $expense->id));
    }

    public function test_account_ledger_ignores_crafted_companies_and_accounts(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $this->record($other, EntryType::Income, '1000', '4000', 77_000_00, '2026-09-10', ['description' => 'Secret income']);
        $this->actingAs($this->user('accountant', $mine));

        Livewire::test(AccountLedger::class)->set('company', (string) $other->id)->assertSet('company', (string) $mine->id)
            ->set('account', (string) $this->account($other, '1000')->id)->assertSet('account', '')->assertViewHas('report', null)
            ->assertDontSee('Secret income');
        Livewire::withQueryParams(['company' => $other->id, 'account' => $this->account($other, '1000')->id])->test(AccountLedger::class)
            ->assertSet('company', (string) $mine->id)->assertViewHas('report', null)->assertDontSee('Secret income');
    }

    public function test_trial_balance_balances_and_matches_ledger_balances(): void
    {
        [$company, $other] = Company::factory()->count(2)->create();
        app(LedgerService::class)->recordOpening($this->account($company, '1010'), 50_000_00, '2026-07-01', $this->owner);
        $this->record($company, EntryType::Income, '1010', '4000', 20_000_00, '2026-08-10');
        $this->record($company, EntryType::Expense, '5000', '1000', 3_000_00, '2026-08-11');
        $this->record($company, EntryType::Transfer, '1000', '1010', 8_000_00, '2026-09-01');
        app(LedgerService::class)->void($this->record($company, EntryType::Expense, '5100', '1010', 9_999_00, '2026-09-02'), 'Wrong company', $this->owner);
        $this->record($other, EntryType::Income, '1000', '4000', 77_000_00, '2026-09-03');
        $this->actingAs($this->user('accountant', $company));

        $ledger = app(LedgerService::class);
        $matchesLedger = fn (string $asOf) => function ($rows) use ($ledger, $asOf): bool {
            foreach ($rows as $row) {
                if ($row['debit'] - $row['credit'] !== ($row['account']->type->isDebitNormal() ? 1 : -1) * $ledger->balance($row['account'], CarbonImmutable::parse($asOf))) {
                    return false;
                }
            }

            return $rows->isNotEmpty();
        };

        Livewire::test(TrialBalance::class)->assertSet('asOf', '2026-09-24')
            ->assertViewHas('balanced', true)->assertViewHas('debitTotal', 70_000_00)->assertViewHas('creditTotal', 70_000_00)
            ->assertViewHas('rows', $matchesLedger('2026-09-24'))
            ->assertViewHas('rows', fn ($rows): bool => $rows->firstWhere('account.code', '1000')['debit'] === 5_000_00)
            ->assertSee('Balanced')->assertDontSee('Out of balance')
            ->set('asOf', '2026-08-10')->assertViewHas('debitTotal', 70_000_00)->assertViewHas('rows', $matchesLedger('2026-08-10'))
            ->assertViewHas('rows', fn ($rows): bool => $rows->firstWhere('account.code', '1000') === null)
            ->set('company', (string) $other->id)->assertSet('company', (string) $company->id)->assertDontSee('৳77,000.00')
            ->set('asOf', '2026-02-30')->assertHasErrors('asOf');
    }

    public function test_employee_cost_totals_are_sorted_scoped_and_linked_to_transactions(): void
    {
        $alpha = Company::factory()->create();
        $beta = Company::factory()->create();
        $hidden = Company::factory()->create();
        $rahim = Employee::factory()->for($alpha)->create(['name' => 'Rahim Uddin']);
        $karim = Employee::factory()->for($alpha)->create(['name' => 'Karim Mia']);
        $salma = Employee::factory()->for($beta)->create(['name' => 'Salma Khatun']);
        $secret = Employee::factory()->for($hidden)->create(['name' => 'Secret Person']);
        $this->record($alpha, EntryType::Expense, '5000', '1000', 25_000_00, '2026-09-01', ['employee_id' => $rahim->id]);
        $this->record($alpha, EntryType::Expense, '5300', '1000', 25_000_00, '2026-09-15', ['employee_id' => $rahim->id]);
        $this->record($alpha, EntryType::Expense, '5000', '1000', 30_000_00, '2026-09-10', ['employee_id' => $karim->id]);
        $this->record($alpha, EntryType::Expense, '5000', '1000', 60_000_00, '2026-08-31', ['employee_id' => $karim->id]);
        app(LedgerService::class)->void($this->record($alpha, EntryType::Expense, '5000', '1000', 99_000_00, '2026-09-11', ['employee_id' => $karim->id]), 'Duplicate', $this->owner);
        $this->record($beta, EntryType::Expense, '5000', '1010', 10_000_00, '2026-09-12', ['employee_id' => $salma->id]);
        $this->record($hidden, EntryType::Expense, '5000', '1000', 88_000_00, '2026-09-12', ['employee_id' => $secret->id]);
        $this->actingAs($this->user('accountant', $alpha, $beta));

        $link = route('admin.entries.index', ['company' => $alpha->id, 'employee' => $rahim->id, 'type' => 'expense', 'from' => '2026-09-01', 'to' => '2026-09-30']);
        Livewire::test(EmployeeCost::class)
            ->assertViewHas('rows', fn ($rows): bool => $rows->map(fn (array $row): array => [$row['employee']->id, $row['total'], $row['count']])->all()
                === [[$rahim->id, 50_000_00, 2], [$karim->id, 30_000_00, 1], [$salma->id, 10_000_00, 1]])
            ->assertSee($link)->assertSee('৳90,000.00')->assertDontSee('Secret Person')
            ->set('company', (string) $beta->id)->assertViewHas('rows', fn ($rows): bool => $rows->pluck('employee.id')->all() === [$salma->id])
            ->set('company', (string) $hidden->id)->assertViewHas('rows', fn ($rows): bool => $rows->isEmpty())->assertDontSee('Secret Person');

        Livewire::withQueryParams(['company' => $alpha->id, 'employee' => $rahim->id, 'type' => 'expense', 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->test(EntriesIndex::class)->assertSet('employee', (string) $rahim->id)->assertViewHas('expense', 50_000_00);
    }

    public function test_reports_require_the_reports_view_permission(): void
    {
        $company = Company::factory()->create();
        $routes = ['index', 'income-statement', 'account-ledger', 'trial-balance', 'employee-cost'];

        $this->actingAs($this->user('accountant', $company));
        foreach ($routes as $route) {
            $this->get(route('admin.reports.'.$route))->assertOk();
        }
        $this->get('/admin')->assertSee('href="'.route('admin.reports.index').'"', false);

        foreach (['data-entry', 'member'] as $role) {
            $this->actingAs($this->user($role, $company));
            foreach ($routes as $route) {
                $this->get(route('admin.reports.'.$route))->assertForbidden();
            }
        }
        $this->actingAs($this->user('data-entry', $company));
        $this->get('/admin')->assertDontSee('href="'.route('admin.reports.index').'"', false);
        foreach ([IncomeStatement::class, AccountLedger::class, TrialBalance::class, EmployeeCost::class] as $report) {
            Livewire::test($report)->assertForbidden();
        }
    }
}
