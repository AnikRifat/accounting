<?php

namespace Tests\Feature;

use App\Enums\EntryType;
use App\Livewire\Admin\Reports\Dues;
use App\Livewire\Admin\Reports\PartyStatement;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

class DuesReportTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    private User $owner;

    private Company $alpha;

    private Company $beta;

    private Company $hidden;

    private Party $rahman;

    private Party $noor;

    private Party $betaBuyer;

    private Party $secret;

    /** @var array<string, JournalEntry> */
    private array $bills = [];

    protected function setUp(): void
    {
        parent::setUp();
        // 20:00 UTC on the 23rd is already 02:00 on the 24th in Asia/Dhaka.
        $this->travelTo(CarbonImmutable::parse('2026-09-23 20:00:00', 'UTC'));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->alpha = Company::factory()->create(['name' => 'Alpha Ltd']);
        $this->beta = Company::factory()->create(['name' => 'Beta Ltd']);
        $this->hidden = Company::factory()->create(['name' => 'Hidden Ltd']);
        $this->rahman = Party::factory()->for($this->alpha)->create(['name' => 'Rahman Traders']);
        $this->noor = Party::factory()->for($this->alpha)->create(['name' => 'Noor Rice Mills']);
        $this->betaBuyer = Party::factory()->for($this->beta)->create(['name' => 'Beta Buyer']);
        $this->secret = Party::factory()->for($this->hidden)->create(['name' => 'Secret Buyer']);

        $income = EntryType::Income;
        $expense = EntryType::Expense;
        // Overdue since 31 Aug; 30,000 received, a 20,000 receipt voided (reopened): 70,000 outstanding.
        $this->bills['overdue'] = $this->bill($this->alpha, $income, '4000', 100_000_00, '2026-08-01', ['paid' => 0, 'party' => $this->rahman, 'due' => '2026-08-31']);
        $this->settle($this->bills['overdue'], 30_000_00, '2026-09-01');
        $this->void($this->settle($this->bills['overdue'], 20_000_00, '2026-09-02'), 'Bounced cheque');
        // Due today (not overdue): 30,000 outstanding.
        $this->bills['dueToday'] = $this->bill($this->alpha, $income, '4000', 50_000_00, '2026-09-10', ['paid' => 20_000_00, 'party' => $this->rahman, 'due' => '2026-09-24']);
        // Fully paid, voided and fully settled bills never appear.
        $this->bill($this->alpha, $income, '4000', 10_000_00, '2026-09-15', ['party' => $this->rahman]);
        $this->void($this->bill($this->alpha, $income, '4000', 5_000_00, '2026-09-15', ['paid' => 0, 'party' => $this->rahman, 'due' => '2026-10-01']));
        $settled = $this->bill($this->alpha, $income, '4000', 8_000_00, '2026-09-16', ['paid' => 0, 'party' => $this->rahman, 'due' => '2026-09-30']);
        $this->settle($settled, 8_000_00, '2026-09-18', '1020');
        // Payables: overdue since yesterday (Dhaka), and a partly paid one with a partial payment.
        $this->bills['payableOverdue'] = $this->bill($this->alpha, $expense, '5400', 40_000_00, '2026-09-01', ['paid' => 0, 'party' => $this->noor, 'due' => '2026-09-23']);
        $this->bills['payablePartly'] = $this->bill($this->alpha, $expense, '5400', 10_000_00, '2026-09-05', ['paid' => 4_000_00, 'party' => $this->noor, 'due' => '2026-10-05']);
        $this->settle($this->bills['payablePartly'], 2_000_00, '2026-09-20');
        $this->bills['beta'] = $this->bill($this->beta, $income, '4000', 20_000_00, '2026-09-12', ['paid' => 0, 'party' => $this->betaBuyer, 'due' => '2026-10-10']);
        $this->bill($this->hidden, $income, '4000', 77_000_00, '2026-09-01', ['paid' => 0, 'party' => $this->secret, 'due' => '2026-09-10']);
    }

    public function test_dues_are_grouped_by_party_with_company_totals_after_partial_settlements_and_voids(): void
    {
        $this->actingAs($this->user('accountant', $this->alpha, $this->beta));

        Livewire::test(Dues::class)
            ->assertViewHas('sections', fn (array $sections): bool => $this->summary($sections['receivable']) === [
                ['Rahman Traders', 100_000_00, [[$this->bills['overdue']->id, 30_000_00, 70_000_00, 24], [$this->bills['dueToday']->id, 20_000_00, 30_000_00, 0]]],
                ['Beta Buyer', 20_000_00, [[$this->bills['beta']->id, 0, 20_000_00, 0]]],
            ] && $this->summary($sections['payable']) === [
                ['Noor Rice Mills', 44_000_00, [[$this->bills['payableOverdue']->id, 0, 40_000_00, 1], [$this->bills['payablePartly']->id, 6_000_00, 4_000_00, 0]]],
            ])
            ->assertViewHas('companyTotals', fn ($totals): bool => $totals->map(fn (array $row): array => [$row['company']->id, $row['receivable'], $row['payable']])->all()
                === [[$this->alpha->id, 100_000_00, 44_000_00], [$this->beta->id, 20_000_00, 0]])
            ->assertSee('৳1,20,000.00')->assertSee('৳44,000.00')->assertDontSee('Secret Buyer')
            ->assertSee(route('admin.entries.settle', $this->bills['overdue']->id))
            ->assertSee('wire:click="openStatement('.$this->rahman->id.')"', false)
            ->call('openStatement', $this->rahman->id)->assertRedirect(route('admin.reports.party-statement', ['party' => $this->rahman->id]));
        $this->assertSame($this->alpha->id, app(CompanyContext::class)->selectedId());

        Livewire::test(Dues::class)->assertViewHas('consolidated', false)
            ->assertViewHas('sections', fn (array $sections): bool => $sections['receivable']->pluck('party.name')->all() === ['Rahman Traders'])
            ->assertSee(route('admin.reports.party-statement', ['party' => $this->rahman->id]))->assertDontSee('Beta Buyer');

        $this->assertSame(100_000_00, app(LedgerService::class)->outstanding($this->bills['overdue']) + app(LedgerService::class)->outstanding($this->bills['dueToday']));
    }

    public function test_filters_narrow_by_type_party_company_and_overdue_relative_to_dhaka_today(): void
    {
        $this->actingAs($this->user('accountant', $this->alpha, $this->beta));

        Livewire::test(Dues::class)->set('overdue', true)
            ->assertViewHas('sections', fn (array $sections): bool => $sections['receivable']->pluck('bills.*.entry.id')->flatten()->all() === [$this->bills['overdue']->id]
                && $sections['payable']->pluck('bills.*.entry.id')->flatten()->all() === [$this->bills['payableOverdue']->id])
            ->set('overdue', false)->set('kind', 'payable')
            ->assertViewHas('sections', fn (array $sections): bool => $sections['receivable']->isEmpty() && $sections['payable']->count() === 1)
            ->set('kind', '')->set('party', (string) $this->rahman->id)
            ->assertViewHas('companyTotals', fn ($totals): bool => $totals->sum('receivable') === 100_000_00 && $totals->sum('payable') === 0);

        session([CompanyContext::SESSION_KEY => $this->beta->id]);
        Livewire::withQueryParams(['party' => $this->rahman->id])->test(Dues::class)->assertSet('party', '')
            ->assertViewHas('companyTotals', fn ($totals): bool => $totals->pluck('company.id')->all() === [$this->beta->id]);
    }

    public function test_dues_ignore_a_crafted_context_party_or_statement_target(): void
    {
        $this->actingAs($this->user('accountant', $this->alpha));
        session([CompanyContext::SESSION_KEY => $this->hidden->id]);

        Livewire::test(Dues::class)->set('party', (string) $this->secret->id)->assertSet('party', '')
            ->set('party', (string) $this->betaBuyer->id)->assertSet('party', '')
            ->assertDontSee('Secret Buyer')->assertDontSee('Beta Buyer')->assertSee('Rahman Traders');
        Livewire::withQueryParams(['company' => $this->hidden->id, 'party' => $this->secret->id, 'kind' => 'bogus'])->test(Dues::class)
            ->assertSet('party', '')->assertSet('kind', '')->assertDontSee('Secret Buyer')->assertDontSee('৳77,000.00');
        $this->assertThrows(fn () => Livewire::test(Dues::class)->call('openStatement', $this->secret->id), ModelNotFoundException::class);

        $this->actingAs($this->user('accountant', $this->alpha, $this->beta));
        session([CompanyContext::SESSION_KEY => $this->hidden->id]);
        Livewire::test(Dues::class)->assertViewHas('consolidated', true)->assertSee('Beta Buyer')->assertDontSee('Secret Buyer');
        session([CompanyContext::SESSION_KEY => $this->alpha->id]);
        $this->assertThrows(fn () => Livewire::test(Dues::class)->call('openStatement', $this->betaBuyer->id), ModelNotFoundException::class);
        $this->assertSame($this->alpha->id, app(CompanyContext::class)->selectedId());
    }

    public function test_dues_actions_follow_permissions(): void
    {
        $auditor = RolePermission::factory()->create(['role' => 'auditor', 'permissions' => ['admin.access', 'reports.view']]);
        $this->actingAs($this->user($auditor->role, $this->alpha));

        Livewire::test(Dues::class)->assertSee('Rahman Traders')
            ->assertDontSee(route('admin.entries.settle', $this->bills['overdue']->id))
            ->assertDontSee(route('admin.reports.party-statement', ['party' => $this->rahman->id]))->assertDontSee('openStatement');
        $this->get(route('admin.reports.party-statement'))->assertForbidden();
        Livewire::test(PartyStatement::class)->assertForbidden();
    }

    public function test_party_statement_shows_opening_due_running_balance_and_closing_due(): void
    {
        $this->actingAs($this->user('accountant', $this->alpha));

        Livewire::test(PartyStatement::class)->set('period', 'this_month')->set('party', (string) $this->rahman->id)
            ->assertViewHas('report', fn (array $report): bool => $report['opening'] === 100_000_00
                && array_map(fn (array $row): array => [$row['debit'], $row['credit'], $row['balance']], $report['rows']) === [
                    [0, 30_000_00, 70_000_00], [50_000_00, 20_000_00, 100_000_00], [10_000_00, 10_000_00, 100_000_00],
                    [8_000_00, 0, 108_000_00], [0, 8_000_00, 100_000_00],
                ]
                && [$report['debit'], $report['credit'], $report['closing']] === [68_000_00, 68_000_00, 100_000_00])
            ->assertSee('Closing due (owed to us)')
            ->set('party', (string) $this->noor->id)
            ->assertViewHas('report', fn (array $report): bool => $report['opening'] === 0
                && array_column($report['rows'], 'balance') === [-40_000_00, -46_000_00, -44_000_00] && $report['closing'] === -44_000_00)
            ->assertSee('Closing due (owed by us)');
    }

    public function test_party_statement_ignores_a_crafted_context_and_party_ids(): void
    {
        $this->actingAs($this->user('accountant', $this->alpha));
        session([CompanyContext::SESSION_KEY => $this->hidden->id]);

        Livewire::test(PartyStatement::class)->assertViewHas('scopeLabel', 'Alpha Ltd')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['rows']->pluck('party.id')->all() === [$this->noor->id, $this->rahman->id])
            ->set('party', (string) $this->secret->id)->assertSet('party', '')->assertViewHas('report', null)->assertDontSee('Secret Buyer');
        Livewire::withQueryParams(['company' => $this->hidden->id, 'party' => $this->secret->id, 'period' => 'this_fiscal_year'])->test(PartyStatement::class)
            ->assertSet('party', '')->assertViewHas('report', null)->assertDontSee('৳77,000.00');
        Livewire::withQueryParams(['party' => $this->betaBuyer->id])->test(PartyStatement::class)->assertSet('party', '')->assertDontSee('Beta Buyer');
    }

    public function test_party_statement_lists_every_party_by_default_and_filters_to_one(): void
    {
        $this->bill($this->hidden, EntryType::Income, '4000', 77_000_00, '2026-09-12', ['paid' => 0, 'party' => $this->secret, 'due' => '2026-10-12']);
        $this->actingAs($this->user('accountant', $this->alpha, $this->beta));

        Livewire::test(PartyStatement::class)->assertSet('period', 'all_time')->assertSet('party', '')->assertViewHas('report', null)
            ->assertViewHas('partyOptions', fn (array $options): bool => $options[''] === 'All parties'
                && array_keys($options) === ['', $this->betaBuyer->id, $this->noor->id, $this->rahman->id])
            ->assertViewHas('summary', fn (array $summary): bool => $summary['rows']->map(fn (array $row): array => [$row['party']->id, $row['closing']])->all() === [
                [$this->betaBuyer->id, 20_000_00], [$this->noor->id, -44_000_00], [$this->rahman->id, 100_000_00],
            ] && $summary['closing'] === 76_000_00)
            ->assertSee('All time')->assertDontSee('Opening due')->assertDontSee('Secret Buyer')
            ->set('party', (string) $this->betaBuyer->id)
            ->assertViewHas('summary', null)->assertViewHas('report', fn (array $report): bool => $report['closing'] === 20_000_00)
            ->set('party', '')->set('from', '2026-09-05')->assertSet('period', 'custom')->assertHasNoErrors()->assertSee('From 05 Sep 2026')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['rows']->firstWhere('party.id', $this->rahman->id)['opening'] === 70_000_00);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return list<array{0: string, 1: int, 2: list<array{0: int, 1: int, 2: int, 3: int}>}>
     */
    private function summary($groups): array
    {
        return $groups->map(fn (array $group): array => [$group['party']->name, $group['total'],
            array_map(fn (array $row): array => [$row['entry']->id, $row['paid'], $row['outstanding'], $row['days_overdue']], $group['bills'])])->all();
    }
}
