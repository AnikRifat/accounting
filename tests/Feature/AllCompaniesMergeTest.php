<?php

namespace Tests\Feature;

use App\Enums\EntryType;
use App\Livewire\Admin\Accounts\Index as AccountIndex;
use App\Livewire\Admin\Reports\IncomeStatement;
use App\Livewire\Admin\Reports\TrialBalance;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

/** On "All companies", every combined view merges account names the same way: case and surrounding spaces are ignored. */
class AllCompaniesMergeTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    protected User $owner;

    public function test_chart_trial_balance_and_income_statement_merge_names_alike(): void
    {
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner);
        [$alpha, $beta] = Company::factory()->count(2)->create();
        $beta->accounts()->where('code', '5100')->update(['name' => ' office rent ']);
        $this->bill($alpha, EntryType::Expense, '5100', 30_000_00, now()->toDateString());
        $this->bill($beta, EntryType::Expense, '5100', 20_000_00, now()->toDateString());

        Livewire::test(IncomeStatement::class)->assertViewHas('expense', fn ($rows): bool => $rows->filter(fn (array $row): bool => mb_strtolower(trim($row['name'])) === 'office rent')->count() === 1
            && $rows->firstWhere('code', '5100')['total'] === 50_000_00);
        Livewire::test(TrialBalance::class)->assertViewHas('balanced', true)
            ->assertViewHas('rows', fn ($rows): bool => $rows->filter(fn (array $row): bool => mb_strtolower(trim($row['name'])) === 'office rent')->count() === 1);
        Livewire::test(AccountIndex::class)->assertSee('Office Rent');
    }
}
