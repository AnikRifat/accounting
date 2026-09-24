<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Entries\Index;
use App\Livewire\Admin\Entries\Trash;
use App\Livewire\Admin\Reports\AccountLedger;
use App\Livewire\Admin\Reports\Dues;
use App\Livewire\Admin\Reports\IncomeStatement;
use App\Livewire\Admin\Reports\PartyStatement;
use App\Livewire\Admin\Reports\TrialBalance;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Media;
use App\Models\Party;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\MediaService;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class EntryTrashTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    private function user(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    private function accountId(Company $company, string $code): int
    {
        return (int) $company->accounts()->where('code', $code)->value('id');
    }

    /** An income (4000) or expense (5100) bill dated today; paid through cash (1000) unless $paid < $total. */
    private function bill(Company $company, EntryType $type, int $total, ?int $paid = null, ?Party $party = null, string $description = ''): JournalEntry
    {
        $paid ??= $total;

        return $this->ledger->record($company, $type, ['entry_date' => today()->toDateString(), 'amount' => $total,
            'payments' => $paid > 0 ? [['account_id' => $this->accountId($company, '1000'), 'amount' => $paid]] : [],
            'category_account_id' => $this->accountId($company, $type === EntryType::Income ? '4000' : '5100'), 'party_id' => $party?->id,
            'due_date' => $paid < $total ? today()->addMonth()->toDateString() : null, 'description' => $description], $this->owner);
    }

    private function settle(JournalEntry $bill, int $amount): JournalEntry
    {
        return $this->ledger->settle($bill, ['entry_date' => today()->toDateString(), 'amount' => $amount,
            'payment_account_id' => $this->accountId($bill->company, '1010')], $this->owner);
    }

    private function outstanding(JournalEntry $bill): int
    {
        return JournalEntry::withTrashed()->withOutstanding()->findOrFail($bill->id)->outstanding;
    }

    private function assertRefused(string $field, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected a refusal on [{$field}].");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_every_figure_ignores_a_trashed_entry(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->for($company)->create(['name' => 'Karim Traders']);
        $kept = $this->bill($company, EntryType::Income, 1_000_00, null, $party, 'Kept sale');
        $trashedIncome = $this->bill($company, EntryType::Income, 700_00, 0, $party, 'Trashed sale');
        $trashedExpense = $this->bill($company, EntryType::Expense, 300_00, null, null, 'Trashed rent');
        $this->ledger->delete($trashedIncome, $this->owner);
        $this->ledger->delete($trashedExpense, $this->owner);
        $this->actingAs($this->owner);

        $this->assertSame(1_000_00, $this->ledger->balance($company->accounts()->where('code', '1000')->sole()));
        $this->assertSame(0, $this->ledger->balance($company->accounts()->where('code', '1200')->sole()));
        $this->assertSame(0, $this->ledger->balance($company->accounts()->where('code', '5100')->sole()));
        $this->assertCount(0, $this->ledger->dues([$company->id]));
        $this->assertSame(['4000'], $this->ledger->periodActivity([$company->id], '2000-01-01', '2099-12-31', [AccountType::Income, AccountType::Expense])->pluck('code')->all());

        Livewire::test(IncomeStatement::class)->assertViewHas('totalIncome', [$company->id => 1_000_00])->assertViewHas('totalExpense', [$company->id => 0]);
        Livewire::test(TrialBalance::class)->assertViewHas('debitTotal', 1_000_00)->assertViewHas('creditTotal', 1_000_00)->assertViewHas('balanced', true);
        Livewire::test(Dues::class)->assertDontSee($trashedIncome->number);
        Livewire::test(Dashboard::class)
            ->assertViewHas('dues', fn (array $dues): bool => $dues['receivable'] === 0 && $dues['payable'] === 0 && $dues['next']->isEmpty())
            ->assertViewHas('recent', fn ($recent): bool => $recent->pluck('id')->all() === [$kept->id])
            ->assertViewHas('months', fn (array $months): bool => array_sum(array_column($months, 'income')) === 1_000_00 && array_sum(array_column($months, 'expense')) === 0);
        Livewire::test(AccountLedger::class)->assertViewHas('lines', fn (array $lines): bool => $lines['credit'] === 1_000_00 && $lines['debit'] === 0 && count($lines['rows']) === 1)
            ->set('account', (string) $this->accountId($company, '4000'))
            ->assertViewHas('report', fn (array $report): bool => $report['credit'] === 1_000_00 && count($report['rows']) === 1);
        Livewire::test(PartyStatement::class)->set('party', (string) $party->id)
            ->assertViewHas('report', fn (array $report): bool => collect($report['rows'])->pluck('entry.id')->all() === [$kept->id]);
        Livewire::test(Index::class)->assertSee('Kept sale')->assertDontSee('Trashed sale')->assertViewHas('income', 1_000_00);
        $csv = $this->get(route('admin.entries.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Kept sale', $csv);
        $this->assertStringNotContainsString('Trashed', $csv);
    }

    public function test_bills_with_settlements_cannot_be_trashed_and_trashing_a_settlement_reopens_it(): void
    {
        $company = Company::factory()->create();
        $bill = $this->bill($company, EntryType::Income, 1_000_00, 0, Party::factory()->for($company)->create());
        $receipt = $this->settle($bill, 400_00);
        $voidedReceipt = $this->ledger->void($this->settle($bill, 100_00), 'Bounced', $this->owner);

        $this->assertRefused('entry', fn () => $this->ledger->delete($bill, $this->owner));
        $trashed = $this->ledger->delete($receipt, $this->owner);
        $this->assertSame($this->owner->id, $trashed->deleted_by);
        $this->assertSame(1_000_00, $this->outstanding($bill));
        $this->assertRefused('entry', fn () => $this->ledger->delete($bill, $this->owner));
        $this->ledger->delete($voidedReceipt, $this->owner);
        $this->assertRefused('entry', fn () => $this->ledger->delete($receipt, $this->owner));
        $this->ledger->delete($bill, $this->owner);

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(3, JournalEntry::onlyTrashed()->count());
        $this->expectException(ModelNotFoundException::class);
        $this->settle($bill, 100);
    }

    public function test_restore_rules_for_bills_settlements_and_inactive_companies(): void
    {
        $company = Company::factory()->create();
        $bill = $this->bill($company, EntryType::Expense, 1_000_00, 0, Party::factory()->for($company)->create());
        $first = $this->settle($bill, 600_00);
        $this->ledger->delete($first, $this->owner);
        $this->settle($bill, 600_00);

        $this->assertRefused('entry', fn () => $this->ledger->restore($first, $this->owner));
        $this->assertSame(400_00, $this->outstanding($bill));
        $this->assertRefused('entry', fn () => $this->ledger->restore($bill, $this->owner));

        $lone = $this->bill($company, EntryType::Income, 500_00, 0, Party::factory()->for($company)->create());
        $receipt = $this->settle($lone, 200_00);
        $this->ledger->delete($receipt, $this->owner);
        $this->ledger->delete($lone, $this->owner);
        $this->assertRefused('entry', fn () => $this->ledger->restore($receipt, $this->owner));
        $this->ledger->restore($lone, $this->owner);
        $this->ledger->restore($receipt, $this->owner);
        $this->assertSame(300_00, $this->outstanding($lone));
        $this->assertNull($receipt->fresh()->deleted_by);

        $this->ledger->delete($receipt, $this->owner);
        $company->forceFill(['is_active' => false])->save();
        $this->assertRefused('company_id', fn () => $this->ledger->restore($receipt, $this->owner));
    }

    public function test_purge_removes_a_bill_with_all_its_settlements_lines_and_files(): void
    {
        Storage::fake(config('media.disk'));
        $company = Company::factory()->create();
        $bill = $this->bill($company, EntryType::Income, 1_000_00, 0, Party::factory()->for($company)->create());
        $live = $this->settle($bill, 300_00);
        $voided = $this->ledger->void($this->settle($bill, 100_00), 'Bounced', $this->owner);
        $trashed = $this->ledger->delete($this->settle($bill, 200_00), $this->owner);
        $other = $this->bill($company, EntryType::Income, 50_00);
        $media = app(MediaService::class)->attach(UploadedFile::fake()->image('voucher.png'), $this->owner, JournalEntry::REFERENCE_FILE, $live);

        $this->ledger->purge($bill, $this->owner);

        $this->assertSame([$other->id], JournalEntry::withTrashed()->pluck('id')->all());
        $this->assertSame(0, JournalLine::query()->whereIn('journal_entry_id', [$bill->id, $live->id, $voided->id, $trashed->id])->count());
        $this->assertNull(Media::find($media->id));
        Storage::disk(config('media.disk'))->assertMissing($media->path);
        $this->assertSame(0, $this->ledger->balance($company->accounts()->where('code', '1200')->sole()));
        $this->assertSame(50_00, $this->ledger->balance($company->accounts()->where('code', '4000')->sole()));

        $receipt = $this->settle($keep = $this->bill($company, EntryType::Income, 400_00, 0, Party::factory()->for($company)->create()), 400_00);
        $this->ledger->purge($receipt, $this->owner);
        $this->assertSame(400_00, $this->outstanding($keep));
    }

    public function test_transactions_list_delete_action_is_scoped_and_reports_refusals(): void
    {
        [$mine, $second, $hidden] = Company::factory()->count(3)->create();
        $accountant = $this->user('accountant', $mine, $second);
        $entry = $this->bill($mine, EntryType::Income, 100_00);
        $outOfScope = $this->bill($second, EntryType::Income, 100_00);
        $foreign = $this->bill($hidden, EntryType::Income, 100_00);
        $billWithReceipt = $this->bill($mine, EntryType::Income, 500_00, 0, Party::factory()->for($mine)->create());
        $this->settle($billWithReceipt, 100_00);
        $this->actingAs($accountant);
        session([CompanyContext::SESSION_KEY => $mine->id]);

        Livewire::test(Index::class)->call('delete', $entry->id)->assertHasNoErrors()->assertSee('moved to trash')
            ->call('delete', $billWithReceipt->id)->assertHasErrors('delete');
        $this->assertSame($accountant->id, JournalEntry::onlyTrashed()->sole()->deleted_by);
        foreach ([$outOfScope, $foreign] as $target) {
            try {
                Livewire::test(Index::class)->call('delete', $target->id);
                $this->fail('An entry outside the scope was trashed.');
            } catch (ModelNotFoundException) {
                $this->assertFalse($target->fresh()->trashed());
            }
        }
    }

    public function test_trash_page_is_scoped_restores_and_purges(): void
    {
        [$alpha, $beta, $hidden] = Company::factory()->count(3)->sequence(['name' => 'Alpha Ltd'], ['name' => 'Beta Ltd'], ['name' => 'Hidden Ltd'])->create();
        $admin = $this->user('administrator');
        $admin->forceFill(['denied_permissions' => ['companies.all']])->save();
        $admin->companies()->attach([$alpha->id, $beta->id]);
        $a = $this->ledger->delete($this->bill($alpha, EntryType::Income, 100_00, null, null, 'Alpha sale'), $this->owner);
        $b = $this->ledger->delete($this->bill($beta, EntryType::Income, 200_00, null, null, 'Beta sale'), $this->owner);
        $h = $this->ledger->delete($this->bill($hidden, EntryType::Income, 300_00), $this->owner);
        $this->actingAs($admin->fresh());
        session([CompanyContext::SESSION_KEY => $hidden->id]);

        $this->get(route('admin.entries.trash'))->assertOk()->assertSee($a->number)->assertSee($b->number)->assertDontSee($h->number)->assertSee('Alpha Ltd');
        session([CompanyContext::SESSION_KEY => $beta->id]);
        $page = Livewire::test(Trash::class)->assertSee($b->number)->assertDontSee($a->number)->assertViewHas('showCompany', false);
        try {
            Livewire::test(Trash::class)->call('purge', $a->id);
            $this->fail('An entry outside the scope was purged.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(JournalEntry::withTrashed()->whereKey($a->id)->exists());
        }
        try {
            Livewire::test(Trash::class)->call('restore', $h->id);
            $this->fail('An entry of an invisible company was restored.');
        } catch (ModelNotFoundException) {
            $this->assertTrue($h->fresh()->trashed());
        }
        $page->call('restore', $b->id)->assertHasNoErrors()->assertSee('restored');
        $this->assertFalse($b->fresh()->trashed());
        $this->ledger->delete($b, $this->owner);
        session([CompanyContext::SESSION_KEY => null]);
        Livewire::test(Trash::class)->call('emptyTrash')->assertHasNoErrors();
        $this->assertSame([$h->id], JournalEntry::withTrashed()->pluck('id')->all());
    }

    public function test_roles_for_trash_restore_and_purge(): void
    {
        $company = Company::factory()->create();
        $entry = $this->bill($company, EntryType::Income, 100_00);
        $dataEntry = $this->user('data-entry', $company);
        $accountant = $this->user('accountant', $company);
        $administrator = $this->user('administrator');

        $this->actingAs($dataEntry);
        $this->get(route('admin.entries.trash'))->assertForbidden();
        Livewire::test(Index::class)->call('delete', $entry->id)->assertForbidden();
        $this->assertFalse($entry->fresh()->trashed());

        $this->actingAs($accountant);
        Livewire::test(Index::class)->call('delete', $entry->id)->assertHasNoErrors();
        $this->get(route('admin.entries.trash'))->assertOk()->assertSee($entry->number)->assertDontSee('Delete permanently')->assertDontSee('Empty trash');
        Livewire::test(Trash::class)->call('purge', $entry->id)->assertForbidden();
        Livewire::test(Trash::class)->call('emptyTrash')->assertForbidden();
        try {
            $this->ledger->purge($entry, $accountant);
            $this->fail('An accountant purged an entry.');
        } catch (AuthorizationException) {
            $this->assertTrue(JournalEntry::withTrashed()->whereKey($entry->id)->exists());
        }
        Livewire::test(Trash::class)->call('restore', $entry->id)->assertHasNoErrors();
        $this->ledger->delete($entry, $accountant);

        $this->actingAs($administrator);
        $this->get(route('admin.entries.trash'))->assertOk()->assertSee('Delete permanently');
        Livewire::test(Trash::class)->call('purge', $entry->id)->assertHasNoErrors();
        $this->assertFalse(JournalEntry::withTrashed()->whereKey($entry->id)->exists());

        $superAdminTarget = $this->bill($company, EntryType::Expense, 50_00);
        $this->ledger->purge($superAdminTarget, $this->owner);
        $this->assertSame(0, JournalEntry::withTrashed()->count());
    }

    public function test_transactions_page_offers_delete_and_trash_by_permission_and_shows_refusals(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->for($company)->create();
        $bill = $this->bill($company, EntryType::Expense, 1_000_00, 400_00, $party);
        $this->settle($bill, 100_00);

        $this->actingAs($this->user('accountant', $company));
        $this->get(route('admin.entries.index'))->assertOk()->assertSee(route('admin.entries.trash'))->assertSee('Delete '.$bill->number);
        Livewire::test(Index::class)->call('delete', $bill->id)->assertHasErrors('delete')->assertSee('Delete the receipts or payments first.');
        $this->assertNull($bill->fresh()->deleted_at);

        $this->actingAs($this->user('data-entry', $company));
        $this->get(route('admin.entries.index'))->assertOk()->assertDontSee(route('admin.entries.trash'))->assertDontSee('Delete '.$bill->number);
    }
}
