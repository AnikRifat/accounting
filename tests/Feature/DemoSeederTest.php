<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\DueStatus;
use App\Enums\EntryType;
use App\Livewire\Admin\Reports\TrialBalance;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_balanced_books_with_consistent_dues(): void
    {
        $this->seed(DemoSeeder::class);

        $owner = User::where('email', DemoSeeder::OWNER_EMAIL)->sole();
        // Anik's choice: a fixed, well-known demo password. The seeder is only safe because it refuses outside
        // local/testing and on non-empty databases (see the refusal tests below).
        $this->assertSame(['owner', true], [$owner->role, Hash::check('password', $owner->password)]);
        $this->assertSame(2, User::where('email', DemoSeeder::ACCOUNTANT_EMAIL)->sole()->parties()->where('is_active', true)->count());
        $this->assertSame(1, User::where('email', DemoSeeder::DATA_ENTRY_EMAIL)->sole()->parties()->where('is_active', true)->count());
        $this->assertSame(0, $owner->parties()->count());
        $staff = User::whereNotNull('employee_code')->get();
        $this->assertTrue($staff->every(fn (User $user): bool => $user->monthly_salary > 0 && $user->parties()->count() === 1 && $user->role === 'data-entry'));
        $this->assertSame(4, Company::count());
        $this->assertSame(2, JournalEntry::whereNotNull('voided_at')->whereNotNull('void_reason')->count());
        foreach (DueStatus::cases() as $status) {
            $this->assertTrue(JournalEntry::query()->dueStatus($status)->exists(), "No bill is {$status->value}.");
        }
        $this->assertTrue(JournalEntry::query()->posted()->where('type', EntryType::Expense)->whereIn('party_id', Party::whereNotNull('user_id')->select('id'))->exists());

        $ledger = app(LedgerService::class);
        $this->actingAs($owner);
        Livewire::test(TrialBalance::class)->assertViewHas('consolidated', true)->assertViewHas('balanced', true);
        foreach (Company::all() as $company) {
            // Three to five staff, plus the demo accountant or data-entry user where they are assigned.
            $employees = Party::where('company_id', $company->id)->whereNotNull('user_id')->count();
            $customParties = Party::where('company_id', $company->id)->whereNull('user_id')->count();
            $this->assertTrue($employees >= 3 && $employees <= 6, "{$company->code} has {$employees} employees.");
            $this->assertTrue($customParties >= 4 && $customParties <= 6, "{$company->code} has {$customParties} custom parties.");
            $this->assertGreaterThanOrEqual(4, $company->accounts()->paymentMethods()->count());
            $this->assertTrue($company->accounts()->paymentMethods()->where('code', '>', '1020')->whereNotNull('details')->exists());
            session([CompanyContext::SESSION_KEY => $company->id]);
            Livewire::test(TrialBalance::class)->assertViewHas('consolidated', false)
                ->assertViewHas('balanced', true)->assertViewHas('debitTotal', fn (int $total): bool => $total > 0);

            $dues = $ledger->dues([$company->id]);
            foreach ([[EntryType::Income, AccountType::Asset], [EntryType::Expense, AccountType::Liability]] as [$type, $accountType]) {
                $control = Account::where('company_id', $company->id)->where('is_system', true)->where('type', $accountType)->sole();
                $this->assertSame($ledger->balance($control), (int) $dues->where('type', $type)->sum('outstanding'), "{$company->code} {$type->value} control account");
            }
            foreach ($dues->groupBy('party_id') as $partyId => $partyDues) {
                $bills = JournalEntry::query()->posted()->where('party_id', $partyId)->whereIn('type', [EntryType::Income, EntryType::Expense])->get();
                $this->assertSame($bills->sum(fn (JournalEntry $bill): int => $ledger->outstanding($bill)), (int) $partyDues->sum('outstanding'));
            }
        }
    }

    public function test_demo_seeder_refuses_to_run_twice_without_changing_anything(): void
    {
        $this->seed(DemoSeeder::class);
        $owner = User::where('email', DemoSeeder::OWNER_EMAIL)->sole();
        $entries = JournalEntry::count();

        $this->assertRefuses(fn () => $this->seed(DemoSeeder::class), 'empty books');
        $this->assertSame([4, $entries, $owner->password], [Company::count(), JournalEntry::count(), $owner->fresh()->password]);
    }

    public function test_demo_seeder_refuses_outside_local_and_testing(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->assertRefuses(fn () => $this->app->make(DemoSeeder::class)->setContainer($this->app)->__invoke(), 'local or testing');
        }
        $this->assertSame([0, 0], [Company::count(), User::count()]);
    }

    public function test_demo_seeder_refuses_when_a_company_or_a_user_already_exists(): void
    {
        $real = Company::factory()->create(['name' => 'Real Books Ltd']);
        $this->assertRefuses(fn () => $this->seed(DemoSeeder::class), 'empty books');
        $this->assertSame([1, 0], [Company::count(), User::count()]);

        $real->accounts()->delete();
        $real->delete();
        User::factory()->create();
        $this->assertRefuses(fn () => $this->seed(DemoSeeder::class), 'empty books');
        $this->assertSame([0, 1], [Company::count(), User::count()]);
    }

    public function test_a_refused_seed_exits_the_command_with_a_failure_code(): void
    {
        config(['logging.default' => 'null']);
        User::factory()->create();

        $status = $this->app->make(ConsoleKernel::class)->handle(new ArrayInput(['command' => 'db:seed', '--class' => DemoSeeder::class, '--no-interaction' => true]), $output = new BufferedOutput);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('DemoSeeder needs empty books', $output->fetch());
        $this->assertSame(0, Company::count());
    }

    private function assertRefuses(callable $seed, string $reason): void
    {
        try {
            $seed();
            $this->fail('DemoSeeder did not refuse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
    }
}
