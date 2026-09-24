<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Livewire\Admin\Entries\Index as EntriesIndex;
use App\Livewire\Admin\Reports\AccountLedger;
use App\Livewire\Admin\Reports\EmployeeCost;
use App\Livewire\Admin\Reports\IncomeStatement;
use App\Livewire\Admin\Reports\TrialBalance;
use App\Models\Account;
use App\Models\Company;
use App\Models\Party;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00'));
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    public function test_income_statement_totals_per_company_and_consolidated_exclude_voided_and_invisible_companies(): void
    {
        $alpha = Company::factory()->create(['name' => 'Alpha Ltd', 'code' => 'ALP']);
        $beta = Company::factory()->create(['name' => 'Beta Ltd', 'code' => 'BET']);
        $hidden = Company::factory()->create(['name' => 'Hidden Ltd', 'code' => 'HID']);
        $this->opening($alpha, '1010', 900_000_00, '2026-09-01');
        $this->bill($alpha, EntryType::Income, '4000', 100_000_00, '2026-09-02');
        $this->bill($alpha, EntryType::Expense, '5100', 30_000_00, '2026-09-03');
        $this->transfer($alpha, '1000', '1010', 12_000_00, '2026-09-04');
        $this->void($this->bill($alpha, EntryType::Income, '4000', 50_000_00, '2026-09-05'), 'Duplicate');
        $this->bill($beta, EntryType::Income, '4000', 40_000_00, '2026-09-06', ['method' => '1010']);
        $this->bill($beta, EntryType::Expense, '5100', 5_000_00, '2026-09-07', ['method' => '1010']);
        $this->bill($beta, EntryType::Expense, '5000', 20_000_00, '2026-09-08', ['method' => '1010']);
        $this->bill($hidden, EntryType::Income, '4000', 77_000_00, '2026-09-09');
        $this->actingAs($this->user('accountant', $alpha, $beta));

        $consolidated = Livewire::test(IncomeStatement::class)
            ->assertViewHas('totalIncome', [$alpha->id => 100_000_00, $beta->id => 40_000_00])
            ->assertViewHas('totalExpense', [$alpha->id => 30_000_00, $beta->id => 25_000_00])
            ->assertViewHas('income', fn ($rows): bool => $rows->count() === 1 && $rows[0]['name'] === 'Sales & Service Income' && $rows[0]['total'] === 140_000_00)
            ->assertViewHas('expense', fn ($rows): bool => $rows->pluck('name')->all() === ['Salaries & Wages', 'Office Rent'])
            ->assertSeeInOrder(['Net profit / (loss)', '৳70,000.00', '৳15,000.00', '৳85,000.00'])
            ->assertDontSee('Hidden Ltd')->assertDontSee('৳77,000.00');

        $this->assertThrows(fn () => $consolidated->set('company', (string) $hidden->id), PublicPropertyNotFoundException::class);

        session([CompanyContext::SESSION_KEY => $alpha->id]);
        Livewire::test(IncomeStatement::class)
            ->assertViewHas('consolidated', false)->assertViewHas('columns', fn ($columns): bool => $columns->pluck('id')->all() === [$alpha->id])
            ->assertViewHas('totalIncome', [$alpha->id => 100_000_00])->assertViewHas('totalExpense', [$alpha->id => 30_000_00])
            ->assertSee('৳70,000.00')->assertDontSee('৳85,000.00')->assertDontSee('Beta Ltd');

        session([CompanyContext::SESSION_KEY => $hidden->id]);
        Livewire::withQueryParams(['company' => $hidden->id])->test(IncomeStatement::class)->assertViewHas('consolidated', true)
            ->assertViewHas('totalIncome', [$alpha->id => 100_000_00, $beta->id => 40_000_00])->assertDontSee('৳77,000.00');
    }

    public function test_income_statement_is_accrual_and_never_counts_receipts_or_payments(): void
    {
        $company = Company::factory()->create();
        $customer = Party::factory()->for($company)->create();
        $supplier = Party::factory()->for($company)->create();
        $sale = $this->bill($company, EntryType::Income, '4000', 10_000_00, '2026-08-20', ['paid' => 0, 'party' => $customer, 'due' => '2026-09-20']);
        $purchase = $this->bill($company, EntryType::Expense, '5400', 4_000_00, '2026-08-21', ['paid' => 1_000_00, 'party' => $supplier, 'due' => '2026-09-21']);
        $this->settle($sale, 6_000_00, '2026-09-05');
        $this->settle($purchase, 3_000_00, '2026-09-06', '1020');
        $this->actingAs($this->owner);

        Livewire::test(IncomeStatement::class)->set('period', 'last_month')
            ->assertViewHas('totalIncome', [$company->id => 10_000_00])->assertViewHas('totalExpense', [$company->id => 4_000_00])
            ->set('period', 'this_month')
            ->assertViewHas('income', fn ($rows): bool => $rows->isEmpty())->assertViewHas('expense', fn ($rows): bool => $rows->isEmpty())
            ->assertSee('No income or expense was posted in this period.');
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
        $this->bill($company, EntryType::Income, '4000', 1_000_00, '2026-07-31');
        $this->bill($company, EntryType::Income, '4000', 100_00, '2026-08-01');
        $this->bill($company, EntryType::Income, '4000', 200_00, '2026-08-31');
        $this->bill($company, EntryType::Income, '4000', 5_000_00, '2026-09-01');
        $this->actingAs($this->owner);

        Livewire::test(IncomeStatement::class)->set('from', '2026-08-01')->assertSet('period', 'custom')->set('to', '2026-08-31')
            ->assertViewHas('totalIncome', [$company->id => 300_00])
            ->set('from', '2026-09-02')->assertHasErrors('to')->assertViewHas('income', fn ($rows): bool => $rows->isEmpty());
    }

    public function test_account_ledger_shows_opening_running_and_closing_balances(): void
    {
        $company = Company::factory()->create();
        $cash = $company->accounts()->where('code', '1000')->sole();
        $this->opening($company, '1000', 10_000_00, '2026-07-01');
        $this->bill($company, EntryType::Income, '4000', 5_000_00, '2026-08-15');
        $expense = $this->bill($company, EntryType::Expense, '5300', 1_000_00, '2026-09-02');
        $voided = $this->void($this->bill($company, EntryType::Expense, '5300', 500_00, '2026-09-05'), 'Typo');
        $this->bill($company, EntryType::Income, '4000', 2_000_00, '2026-09-10');
        $this->transfer($company, '1010', '1000', 3_000_00, '2026-09-20');
        $this->actingAs($this->user('accountant', $company));

        Livewire::test(AccountLedger::class)->assertViewHas('selectedCompany', fn (Company $selected): bool => $selected->is($company))->set('account', (string) $cash->id)
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

    public function test_account_ledger_ignores_crafted_context_and_accounts(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $this->bill($other, EntryType::Income, '4000', 77_000_00, '2026-09-10', ['description' => 'Secret income']);
        $this->actingAs($this->user('accountant', $mine));
        $foreignCash = $this->accountId($other, '1000');
        session([CompanyContext::SESSION_KEY => $other->id]);

        Livewire::test(AccountLedger::class)->assertViewHas('selectedCompany', fn (Company $company): bool => $company->is($mine))
            ->assertViewHas('accountOptions', fn (array $options): bool => array_keys(array_slice($options, 1, null, true)) === $mine->accounts()->orderBy('code')->pluck('id')->all())
            ->set('account', (string) $foreignCash)->assertSet('account', '')->assertViewHas('report', null)
            ->assertDontSee('Secret income');
        Livewire::withQueryParams(['company' => $other->id, 'account' => $foreignCash])->test(AccountLedger::class)
            ->assertSet('account', '')->assertViewHas('report', null)->assertDontSee('Secret income');
    }

    public function test_account_ledger_asks_for_one_company_in_all_mode(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->bill($first, EntryType::Income, '4000', 5_000_00, '2026-09-10');
        $this->actingAs($this->user('accountant', $first, $second));
        $cash = $this->accountId($first, '1000');

        Livewire::withQueryParams(['account' => $cash])->test(AccountLedger::class)
            ->assertViewHas('selectedCompany', null)->assertViewHas('report', null)->assertSet('account', '')
            ->assertSee('Choose a company')
            ->assertSee(route('admin.choose-company', ['next' => route('admin.reports.account-ledger', ['period' => 'this_month'], false)]));

        session([CompanyContext::SESSION_KEY => $first->id]);
        Livewire::test(AccountLedger::class)->set('account', (string) $cash)->assertViewHas('report', fn (array $report): bool => $report['closing'] === 5_000_00)
            ->assertDontSee(route('admin.choose-company'));
    }

    public function test_trial_balance_balances_and_matches_ledger_balances(): void
    {
        [$company, $other] = Company::factory()->count(2)->create();
        $customer = Party::factory()->for($company)->create();
        $this->opening($company, '1010', 50_000_00, '2026-07-01');
        $this->bill($company, EntryType::Income, '4000', 20_000_00, '2026-08-10', ['method' => '1010']);
        $this->bill($company, EntryType::Expense, '5000', 3_000_00, '2026-08-11');
        $this->transfer($company, '1000', '1010', 8_000_00, '2026-09-01');
        $this->void($this->bill($company, EntryType::Expense, '5100', 9_999_00, '2026-09-02', ['method' => '1010']), 'Wrong company');
        $credit = $this->bill($company, EntryType::Income, '4900', 6_000_00, '2026-09-03', ['paid' => 1_000_00, 'party' => $customer, 'due' => '2026-10-03']);
        $this->settle($credit, 2_000_00, '2026-09-10', '1020');
        $this->bill($other, EntryType::Income, '4000', 77_000_00, '2026-09-03');
        $this->actingAs($this->user('accountant', $company));

        $ledger = app(LedgerService::class);
        $matchesLedger = fn (string $asOf) => function ($rows) use ($ledger, $asOf, $company): bool {
            foreach ($rows as $row) {
                $account = Account::query()->where('company_id', $company->id)->where('code', $row['code'])->sole();
                if ($row['debit'] - $row['credit'] !== ($account->type->isDebitNormal() ? 1 : -1) * $ledger->balance($account, CarbonImmutable::parse($asOf))) {
                    return false;
                }
            }

            return $rows->isNotEmpty();
        };

        Livewire::test(TrialBalance::class)->assertSet('asOf', '2026-09-24')
            ->assertViewHas('balanced', true)->assertViewHas('debitTotal', 76_000_00)->assertViewHas('creditTotal', 76_000_00)
            ->assertViewHas('rows', $matchesLedger('2026-09-24'))
            ->assertViewHas('rows', fn ($rows): bool => $rows->firstWhere('code', '1000')['debit'] === 6_000_00
                && $rows->firstWhere('code', '1200')['debit'] === 3_000_00)
            ->assertSee('Balanced')->assertDontSee('Out of balance')
            ->set('asOf', '2026-08-10')->assertViewHas('debitTotal', 70_000_00)->assertViewHas('rows', $matchesLedger('2026-08-10'))
            ->assertViewHas('rows', fn ($rows): bool => $rows->firstWhere('code', '1000') === null)
            ->assertDontSee('৳77,000.00')
            ->set('asOf', '2026-02-30')->assertHasErrors('asOf');

        session([CompanyContext::SESSION_KEY => $other->id]);
        Livewire::test(TrialBalance::class)->assertViewHas('consolidated', false)->assertViewHas('debitTotal', 76_000_00)->assertDontSee('৳77,000.00');
    }

    public function test_trial_balance_consolidates_all_companies_by_type_and_name_and_stays_balanced(): void
    {
        [$alpha, $beta, $hidden] = Company::factory()->count(3)->create();
        foreach ([$alpha, $beta] as $index => $company) {
            $company->accounts()->forceCreate(['code' => $index === 0 ? '5901' : '5905', 'name' => 'Courier Charges', 'type' => AccountType::Expense, 'is_cash' => false, 'is_system' => false, 'is_active' => true]);
        }
        $customer = Party::factory()->for($beta)->create();
        $this->opening($alpha, '1000', 10_000_00, '2026-07-01');
        $this->opening($beta, '1000', 4_000_00, '2026-07-01');
        $this->bill($alpha, EntryType::Income, '4000', 3_000_00, '2026-08-01');
        $this->bill($beta, EntryType::Income, '4000', 2_000_00, '2026-08-02', ['paid' => 500_00, 'party' => $customer, 'due' => '2026-09-02']);
        $this->bill($alpha, EntryType::Expense, '5901', 700_00, '2026-08-03');
        $this->bill($beta, EntryType::Expense, '5905', 300_00, '2026-08-04');
        $this->transfer($beta, '1010', '1000', 1_000_00, '2026-08-05');
        $this->bill($hidden, EntryType::Income, '4000', 77_000_00, '2026-08-06');
        $this->actingAs($this->user('accountant', $alpha, $beta));
        session([CompanyContext::SESSION_KEY => $hidden->id]);

        $row = fn ($rows, string $name): array => $rows->firstWhere('name', $name);
        Livewire::test(TrialBalance::class)->assertViewHas('consolidated', true)
            ->assertViewHas('balanced', true)->assertViewHas('debitTotal', 19_000_00)->assertViewHas('creditTotal', 19_000_00)
            ->assertViewHas('rows', fn ($rows): bool => $rows->where('name', 'Courier Charges')->count() === 1
                && $row($rows, 'Courier Charges')['debit'] === 1_000_00 && $row($rows, 'Cash in Hand')['debit'] === (10_000_00 + 3_000_00 - 700_00) + (4_000_00 + 500_00 - 300_00 - 1_000_00)
                && $row($rows, 'Sales & Service Income')['credit'] === 5_000_00 && $row($rows, 'Accounts Receivable')['debit'] === 1_500_00
                && $row($rows, 'Opening Balance Equity')['credit'] === 14_000_00)
            ->assertSee('Balanced')->assertDontSee('৳77,000.00');

        foreach ([$alpha, $beta] as $company) {
            session([CompanyContext::SESSION_KEY => $company->id]);
            Livewire::test(TrialBalance::class)->assertViewHas('consolidated', false)->assertViewHas('balanced', true);
        }
    }

    public function test_employee_cost_uses_employee_parties_with_paid_and_outstanding_amounts(): void
    {
        $alpha = Company::factory()->create();
        $beta = Company::factory()->create();
        $hidden = Company::factory()->create();
        $rahim = User::factory()->employeeOf($alpha)->create(['name' => 'Rahim Uddin'])->parties()->sole();
        $karim = User::factory()->employeeOf($alpha)->create(['name' => 'Karim Mia'])->parties()->sole();
        // Salma works for both companies and has a party in each; only her Beta party has expenses.
        $salma = User::factory()->employeeOf($alpha, $beta)->create(['name' => 'Salma Khatun'])->parties()->where('company_id', $beta->id)->sole();
        $secret = User::factory()->employeeOf($hidden)->create(['name' => 'Secret Person'])->parties()->sole();
        $vendor = Party::factory()->for($alpha)->create(['name' => 'Office Vendor']);
        $this->bill($alpha, EntryType::Expense, '5000', 25_000_00, '2026-09-01', ['party' => $rahim]);
        $advance = $this->bill($alpha, EntryType::Expense, '5300', 25_000_00, '2026-09-15', ['party' => $rahim, 'paid' => 10_000_00, 'due' => '2026-10-15']);
        $this->settle($advance, 5_000_00, '2026-09-20');
        $this->bill($alpha, EntryType::Expense, '5000', 30_000_00, '2026-09-10', ['party' => $karim]);
        $this->bill($alpha, EntryType::Expense, '5000', 60_000_00, '2026-08-31', ['party' => $karim]);
        $this->void($this->bill($alpha, EntryType::Expense, '5000', 99_000_00, '2026-09-11', ['party' => $karim]), 'Duplicate');
        $this->bill($alpha, EntryType::Expense, '5400', 44_000_00, '2026-09-12', ['party' => $vendor]);
        $this->bill($beta, EntryType::Expense, '5000', 10_000_00, '2026-09-12', ['party' => $salma, 'method' => '1010']);
        $this->bill($hidden, EntryType::Expense, '5000', 88_000_00, '2026-09-12', ['party' => $secret]);
        $this->actingAs($this->user('accountant', $alpha, $beta));

        $link = route('admin.entries.index', ['party' => $rahim->id, 'type' => 'expense', 'from' => '2026-09-01', 'to' => '2026-09-30']);
        Livewire::test(EmployeeCost::class)
            ->assertViewHas('rows', fn ($rows): bool => $rows->map(fn (array $row): array => [$row['party']->id, $row['total'], $row['paid'], $row['outstanding'], $row['count']])->all()
                === [[$rahim->id, 50_000_00, 40_000_00, 10_000_00, 2], [$karim->id, 30_000_00, 30_000_00, 0, 1], [$salma->id, 10_000_00, 10_000_00, 0, 1]])
            ->assertSee($link)->assertSee('৳90,000.00')->assertSee('<th scope="col">Company</th>', false)->assertDontSee('Secret Person')->assertDontSee('Office Vendor');

        session([CompanyContext::SESSION_KEY => $beta->id]);
        Livewire::test(EmployeeCost::class)->assertViewHas('rows', fn ($rows): bool => $rows->pluck('party.id')->all() === [$salma->id])
            ->assertViewHas('consolidated', false)->assertDontSee('<th scope="col">Company</th>', false);
        session([CompanyContext::SESSION_KEY => $hidden->id]);
        Livewire::test(EmployeeCost::class)->assertViewHas('rows', fn ($rows): bool => $rows->count() === 3)->assertDontSee('Secret Person');

        Livewire::withQueryParams(['party' => $rahim->id, 'type' => 'expense', 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->test(EntriesIndex::class)->assertSet('party', (string) $rahim->id)->assertViewHas('expense', 50_000_00);
    }

    public function test_employee_cost_also_requires_the_users_view_permission(): void
    {
        $company = Company::factory()->create();
        $analyst = RolePermission::factory()->create(['role' => 'analyst', 'permissions' => ['admin.access', 'reports.view']]);
        $this->actingAs($this->user($analyst->role, $company));

        $this->get(route('admin.reports.employee-cost'))->assertForbidden();
        Livewire::test(EmployeeCost::class)->assertForbidden();
        $this->get(route('admin.reports.index'))->assertOk()->assertDontSee(route('admin.reports.employee-cost'))
            ->assertDontSee(route('admin.reports.party-statement'))->assertDontSee(route('admin.accounts.index'))->assertSee(route('admin.reports.dues'));

        $this->actingAs($this->user('accountant', $company))->get(route('admin.reports.index'))
            ->assertSee(route('admin.reports.employee-cost'))->assertSee(route('admin.reports.party-statement'))->assertSee(route('admin.accounts.index'));
    }

    public function test_reports_require_the_reports_view_permission(): void
    {
        $company = Company::factory()->create();
        $routes = ['index', 'income-statement', 'account-ledger', 'trial-balance', 'employee-cost', 'dues', 'party-statement'];

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
