<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\DueStatus;
use App\Enums\EntryType;
use App\Livewire\Admin\Entries\Form;
use App\Livewire\Admin\Entries\Index;
use App\Livewire\Admin\Entries\Settle;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class EntriesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    private function context(?Company $company): void
    {
        session([CompanyContext::SESSION_KEY => $company?->id]);
    }

    /** Asserts that setting a locked Livewire property is refused. */
    private function assertLocked(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('A locked property was changed.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->addToAssertionCount(1);
        }
    }

    private function accountId(Company $company, string $code): string
    {
        return (string) $company->accounts()->where('code', $code)->value('id');
    }

    /** Records an income (4000) or expense (5100) bill through cash (1000); pass paid < total with party and due date for a due. */
    private function bill(Company $company, EntryType $type, int $total, User $actor, array $extra = []): JournalEntry
    {
        return app(LedgerService::class)->record($company, $type, $extra + ['entry_date' => '2026-09-01', 'amount' => $total, 'paid_amount' => $total,
            'category_account_id' => (int) $this->accountId($company, $type === EntryType::Income ? '4000' : '5100'),
            'payment_account_id' => (int) $this->accountId($company, '1000')], $actor);
    }

    private function dueBill(Company $company, EntryType $type, int $total, User $actor, array $extra = []): JournalEntry
    {
        return $this->bill($company, $type, $total, $actor, $extra + ['paid_amount' => 0, 'due_date' => '2026-09-30',
            'party_id' => Party::factory()->for($company)->create()->id]);
    }

    public function test_income_is_recorded_in_the_header_company_with_defaults(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->actingAs($this->user('data-entry', $first, $second));
        $this->context($second);

        Livewire::test(Form::class, ['type' => 'income'])->assertSet('companyId', $second->id)
            ->assertSet('paymentAccountId', $this->accountId($second, '1000'))->assertSee($second->name)
            ->set('categoryAccountId', $this->accountId($second, '4000'))->set('amount', '1,25,000.50')->assertSet('paidAmount', '1,25,000.50')
            ->set('reference', 'INV-7')->call('save')->assertHasNoErrors()->assertRedirect(route('admin.entries.index'));

        $entry = JournalEntry::sole();
        $this->assertSame([$second->id, EntryType::Income, 1_25_000_50, 'INV-7', null], [$entry->company_id, $entry->type, $entry->amount, $entry->reference, $entry->party_id]);
        $this->assertSame(1_25_000_50, app(LedgerService::class)->balance($second->accounts()->where('code', '1000')->sole()));
        $this->context($first);
        Livewire::test(Form::class, ['type' => 'expense'])->assertSet('companyId', $first->id);
    }

    public function test_create_needs_one_active_company_in_the_header(): void
    {
        [$active, $other] = Company::factory()->count(2)->create();
        $inactive = Company::factory()->create(['is_active' => false]);
        $hidden = Company::factory()->create();
        $this->actingAs($this->user('accountant', $active, $other, $inactive));
        $chooser = route('admin.choose-company', ['next' => '/admin/entries/create/expense']);

        $this->get(route('admin.entries.create', 'expense'))->assertRedirect($chooser);
        $this->context($inactive);
        $this->get(route('admin.entries.create', 'expense'))->assertRedirect($chooser);
        $this->context($hidden);
        $this->assertTrue(app(CompanyContext::class)->isAll());
        $this->get(route('admin.entries.create', 'expense'))->assertRedirect($chooser);
        $this->context($active);
        $this->get(route('admin.entries.create', 'expense'))->assertOk();
    }

    public function test_a_header_change_in_another_tab_or_a_deactivated_company_fails_the_save_cleanly(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->actingAs($this->user('accountant', $first, $second));
        $this->context($first);
        $form = fn () => Livewire::test(Form::class, ['type' => 'income'])->set('categoryAccountId', $this->accountId($first, '4000'))->set('amount', '100');

        $switched = $form();
        $this->context($second);
        $switched->call('save')->assertHasErrors('entry')->set('addingParty', true)->set('newPartyName', 'Late')->call('addParty')->assertHasErrors('newPartyName');
        $this->context($first);
        $this->assertLocked(fn () => $form()->set('companyId', $second->id));
        $closed = $form();
        $first->forceFill(['is_active' => false])->save();
        $closed->call('save')->assertHasErrors('entry');

        $this->assertSame(0, JournalEntry::count());
        $this->assertFalse(Party::where('name', 'Late')->exists());
    }

    public function test_a_partly_paid_expense_needs_a_party_and_due_date_and_posts_a_payable(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->for($company)->create(['name' => 'Karim Traders']);
        $this->actingAs($this->user('data-entry', $company));

        $form = Livewire::test(Form::class, ['type' => 'expense'])->set('categoryAccountId', $this->accountId($company, '5400'))
            ->set('amount', '10000')->set('paidAmount', '4000')->assertViewHas('showDue', true)
            ->set('paymentAccountId', $this->accountId($company, '1020'))
            ->call('save')->assertHasErrors(['partyId', 'dueDate']);
        $form->assertSee('Karim Traders')->set('partyId', (string) $party->id)
            ->set('dueDate', '2026-01-01')->set('entryDate', '2026-09-01')->call('save')->assertHasErrors('dueDate')
            ->set('dueDate', '2026-10-01')->call('save')->assertHasNoErrors();

        $entry = JournalEntry::query()->withOutstanding()->sole();
        $this->assertSame([10_000_00, 6_000_00, $party->id, '2026-10-01'], [$entry->amount, $entry->outstanding, $entry->party_id, $entry->due_date->toDateString()]);
        $this->assertSame(4_000_00, -app(LedgerService::class)->balance($company->accounts()->where('code', '1020')->sole()));
    }

    public function test_quick_add_party_and_save_and_add_another(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($user = $this->user('accountant', $company));

        Livewire::test(Form::class, ['type' => 'income'])->set('addingParty', true)->set('newPartyName', ' Walk-in Customer ')
            ->set('newPartyPhone', '01711000000')->call('addParty')->assertHasNoErrors()
            ->set('categoryAccountId', $this->accountId($company, '4000'))->set('amount', '500')
            ->call('save', true)->assertHasNoErrors()->assertNoRedirect()
            ->assertSet('amount', '')->assertSet('partyId', '')->assertSet('categoryAccountId', $this->accountId($company, '4000'))
            ->set('amount', '700')->call('save', true)->assertHasNoErrors();

        $party = Party::sole();
        $this->assertSame([$company->id, 'Walk-in Customer', null], [$party->company_id, $party->name, $party->employee_id]);
        $this->assertSame(2, JournalEntry::count());
        $this->assertSame($party->id, JournalEntry::orderBy('id')->first()->party_id);
        $this->assertTrue(JournalEntry::orderByDesc('id')->first()->creator->is($user));

        $withoutParties = $this->user('data-entry', $company);
        $withoutParties->forceFill(['denied_permissions' => ['parties.create']])->save();
        $this->actingAs($withoutParties->fresh());
        Livewire::test(Form::class, ['type' => 'income'])->assertDontSee('+ Add a new party')->call('addParty')->assertForbidden();
    }

    public function test_quick_add_category_creates_one_of_the_entry_side_and_selects_it(): void
    {
        [$company, $other] = Company::factory()->count(2)->create();
        $this->actingAs($this->user('accountant', $company));

        $form = Livewire::test(Form::class, ['type' => 'expense'])->assertSee('+ Add a new category')
            ->set('addingCategory', true)->set('newCategoryName', ' Courier ')->call('addCategory')->assertHasNoErrors()
            ->assertSet('addingCategory', false)->assertSet('newCategoryName', '');
        $category = Account::query()->where('name', 'Courier')->sole();
        $this->assertSame([$company->id, AccountType::Expense, false, true], [$category->company_id, $category->type, $category->is_cash, $category->is_active]);
        $this->assertGreaterThanOrEqual(5000, (int) $category->code);
        $this->assertLessThanOrEqual(5999, (int) $category->code);
        $form->assertSet('categoryAccountId', (string) $category->id)->assertSee('Courier')
            ->set('amount', '250')->call('save')->assertHasNoErrors();
        $this->assertSame($category->id, JournalEntry::sole()->categoryAccount()->id);

        Livewire::test(Form::class, ['type' => 'income'])->set('newCategoryName', 'Courier')->call('addCategory')->assertHasErrors('newCategoryName');
        Livewire::test(Form::class, ['type' => 'income'])->set('newCategoryName', 'Tuition')->call('addCategory')->assertHasNoErrors();
        $this->assertSame(AccountType::Income, Account::query()->where('name', 'Tuition')->sole()->type);
        $this->assertLocked(fn () => Livewire::test(Form::class, ['type' => 'income'])->set('companyId', $other->id));
        Livewire::test(Form::class, ['type' => 'transfer'])->set('newCategoryName', 'Injected')->call('addCategory')->assertNotFound();

        $open = Livewire::test(Form::class, ['type' => 'expense']);
        $company->forceFill(['is_active' => false])->save();
        $open->set('newCategoryName', 'Injected')->call('addCategory')->assertHasErrors('newCategoryName');
        $this->assertFalse(Account::where('name', 'Injected')->exists());

        $company->forceFill(['is_active' => true])->save();
        $this->actingAs($this->user('data-entry', $company));
        Livewire::test(Form::class, ['type' => 'expense'])->assertDontSee('+ Add a new category')->call('addCategory')->assertForbidden();
    }

    public function test_crafted_company_category_method_and_party_ids_are_rejected(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $foreignParty = Party::factory()->for($other)->create();
        $this->actingAs($this->user('accountant', $mine));
        $income = fn () => Livewire::test(Form::class, ['type' => 'income'])->set('categoryAccountId', $this->accountId($mine, '4000'))->set('amount', '100');

        $this->assertLocked(fn () => Livewire::test(Form::class, ['type' => 'income'])->set('companyId', $other->id));
        $income()->set('categoryAccountId', $this->accountId($other, '4000'))->call('save')->assertHasErrors('categoryAccountId');
        $income()->set('categoryAccountId', $this->accountId($mine, '5100'))->call('save')->assertHasErrors('categoryAccountId');
        $income()->set('paymentAccountId', $this->accountId($other, '1000'))->call('save')->assertHasErrors('paymentAccountId');
        $income()->set('paymentAccountId', $this->accountId($mine, '4900'))->call('save')->assertHasErrors('paymentAccountId');
        $income()->set('partyId', (string) $foreignParty->id)->call('save')->assertHasErrors('partyId');
        $income()->set('paidAmount', '150')->call('save')->assertHasErrors('paidAmount');
        $income()->set('amount', '0.00')->call('save')->assertHasErrors('amount');
        Livewire::test(Form::class, ['type' => 'income'])->set('addingParty', true)->set('newPartyName', 'Mine only')->call('addParty')->assertHasNoErrors();
        $this->assertSame($mine->id, Party::where('name', 'Mine only')->sole()->company_id);
        Livewire::test(Form::class, ['type' => 'transfer'])->set('creditAccountId', $this->accountId($mine, '1000'))
            ->set('debitAccountId', $this->accountId($mine, '1000'))->set('amount', '100')->call('save')->assertHasErrors('creditAccountId');

        $this->assertSame(0, JournalEntry::count());
        $this->assertFalse(Party::where('name', 'Injected')->exists());
    }

    public function test_transfer_between_payment_methods(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->user('data-entry', $company));

        Livewire::test(Form::class, ['type' => 'transfer'])->set('creditAccountId', $this->accountId($company, '1010'))
            ->set('debitAccountId', $this->accountId($company, '1020'))->set('amount', '2,500')->call('save')->assertHasNoErrors();

        $this->assertSame(2_500_00, app(LedgerService::class)->balance($company->accounts()->where('code', '1020')->sole()));
    }

    public function test_settling_a_bill_from_the_list_and_editing_the_settlement(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->create(['role' => 'owner']);
        $bill = $this->dueBill($company, EntryType::Income, 1_000_00, $owner);
        $dataEntry = $this->user('data-entry', $company);
        $this->actingAs($dataEntry);

        $this->get(route('admin.entries.index'))->assertSee('Receive payment')->assertSee(route('admin.entries.settle', $bill));
        $this->get(route('admin.entries.settle', $bill))->assertOk()->assertSee('1000.00');
        Livewire::test(Settle::class, ['entry' => $bill])->assertSet('amount', '1000.00')->assertSet('paymentAccountId', $this->accountId($company, '1000'))
            ->set('amount', '1000.01')->call('save')->assertHasErrors('amount')
            ->set('amount', '400')->set('entryDate', '2026-09-05')->call('save')->assertHasNoErrors()->assertRedirect(route('admin.entries.index'));

        $receipt = JournalEntry::where('type', EntryType::Receipt)->sole();
        $this->assertSame([$bill->id, $bill->party_id, 400_00, $dataEntry->id], [$receipt->bill_id, $receipt->party_id, $receipt->amount, $receipt->created_by]);
        $this->get(route('admin.entries.settlement.edit', $receipt))->assertForbidden();
        $this->get(route('admin.entries.edit', $bill))->assertForbidden();

        $this->actingAs($owner);
        $this->get(route('admin.entries.edit', $receipt))->assertRedirect(route('admin.entries.settlement.edit', $receipt));
        Livewire::test(Settle::class, ['entry' => $receipt])->assertSet('amount', '400.00')->set('amount', '1,000')->call('save')->assertHasNoErrors();
        $this->assertSame(0, app(LedgerService::class)->outstanding($bill));
        $this->get(route('admin.entries.settle', $bill))->assertForbidden();
    }

    public function test_editing_a_bill_reposts_it_within_the_settled_limits(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $owner = User::factory()->create(['role' => 'owner']);
        $bill = $this->dueBill($mine, EntryType::Expense, 1_000_00, $owner, ['paid_amount' => 200_00]);
        app(LedgerService::class)->settle($bill, ['entry_date' => '2026-09-05', 'amount' => 500_00, 'payment_account_id' => (int) $this->accountId($mine, '1010')], $owner);
        $accountant = $this->user('accountant', $mine);
        $this->actingAs($accountant);

        $this->get(route('admin.entries.edit', $bill))->assertOk()->assertSee('৳500.00 has already been settled');
        $form = Livewire::test(Form::class, ['entry' => $bill])->assertSet('paidAmount', '200.00')->assertSet('amount', '1000.00')->assertSet('companyId', $mine->id);
        $this->assertLocked(fn () => Livewire::test(Form::class, ['entry' => $bill])->set('companyId', $other->id));
        $form->set('amount', '600')->call('save')->assertHasErrors('amount');
        $form->set('amount', '1,500')->set('categoryAccountId', $this->accountId($mine, '5200'))->call('save')
            ->assertHasNoErrors()->assertRedirect(route('admin.entries.index'));

        $bill = JournalEntry::query()->withOutstanding()->findOrFail($bill->id);
        $this->assertSame([1_500_00, 800_00, $accountant->id], [$bill->amount, $bill->outstanding, $bill->updated_by]);
        $this->assertSame($this->accountId($mine, '5200'), (string) $bill->categoryAccount()->id);
    }

    public function test_opening_entries_are_editable_and_voided_entries_are_not(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->create(['role' => 'owner']);
        $cash = $company->accounts()->where('code', '1010')->sole();
        $opening = app(LedgerService::class)->recordOpening($cash, 5_000_00, '2026-07-01', $owner);
        $voided = app(LedgerService::class)->void($this->bill($company, EntryType::Income, 100, $owner), 'Typo', $owner);
        $this->actingAs($owner);

        $this->get(route('admin.entries.edit', $opening))->assertOk()->assertSee('Payment method');
        Livewire::test(Form::class, ['entry' => $opening])->set('amount', '6000')->call('save')->assertHasNoErrors();
        $this->assertSame(6_000_00, app(LedgerService::class)->balance($cash));
        $this->get(route('admin.entries.edit', $voided))->assertForbidden();
    }

    public function test_entries_of_invisible_companies_cannot_be_opened_edited_settled_or_voided(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $owner = User::factory()->create(['role' => 'owner']);
        $foreign = $this->dueBill($other, EntryType::Income, 100, $owner);
        $mineBill = $this->dueBill($mine, EntryType::Income, 100, $owner);
        $this->actingAs($this->user('accountant', $mine));

        $this->get(route('admin.entries.edit', $foreign))->assertNotFound();
        $this->get(route('admin.entries.settle', $foreign))->assertNotFound();
        foreach ([
            fn () => Livewire::test(Index::class)->call('confirmVoid', $foreign->id),
            fn () => Livewire::test(Index::class)->set('voidingId', $foreign->id)->set('voidReason', 'Crafted')->call('void'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An entry of an invisible company was resolved.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            Livewire::test(Settle::class, ['entry' => $mineBill])->set('billId', $foreign->id);
            $this->fail('The locked bill id was changed.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->addToAssertionCount(1);
        }
        Livewire::test(Settle::class, ['entry' => $mineBill])->set('paymentAccountId', $this->accountId($other, '1000'))
            ->call('save')->assertHasErrors('paymentAccountId');
        $this->assertFalse($foreign->fresh()->isVoided());
        $this->assertSame(1, JournalEntry::where('company_id', $other->id)->count());
        $this->assertSame(100, app(LedgerService::class)->outstanding($mineBill));
    }

    public function test_data_entry_can_create_and_settle_but_not_edit_or_void(): void
    {
        $company = Company::factory()->create();
        $user = $this->user('data-entry', $company);
        $entry = $this->bill($company, EntryType::Income, 100, $user);
        $this->actingAs($user);

        $this->get(route('admin.entries.create', 'transfer'))->assertOk();
        $this->get(route('admin.entries.edit', $entry))->assertForbidden();
        $this->get(route('admin.entries.index'))->assertOk()->assertSee($entry->number)->assertDontSee(route('admin.entries.edit', $entry));
        Livewire::test(Index::class)->call('confirmVoid', $entry->id)->assertForbidden();
        $this->get('/admin/entries/create/opening')->assertNotFound();
        $this->get('/admin/entries/create/receipt')->assertNotFound();
    }

    public function test_list_filters_statuses_and_totals_exclude_voided_entries(): void
    {
        [$mine, $second, $other] = Company::factory()->count(3)->create();
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = Party::factory()->for($mine)->create(['name' => 'Rahim Stores']);
        $this->travelTo('2026-09-24 10:00');
        $this->bill($mine, EntryType::Income, 10_000_00, $owner, ['description' => 'Consulting fee']);
        $voided = $this->bill($mine, EntryType::Income, 99_000_00, $owner, ['description' => 'Wrong receipt']);
        $overdue = $this->bill($mine, EntryType::Expense, 3_000_00, $owner, ['entry_date' => '2026-09-10', 'paid_amount' => 1_000_00, 'party_id' => $customer->id, 'due_date' => '2026-09-20']);
        $partly = $this->dueBill($mine, EntryType::Income, 2_000_00, $owner, ['party_id' => $customer->id]);
        app(LedgerService::class)->settle($partly, ['entry_date' => '2026-09-05', 'amount' => 500_00, 'payment_account_id' => (int) $this->accountId($mine, '1000')], $owner);
        $this->bill($second, EntryType::Expense, 500_00, $owner);
        $this->bill($other, EntryType::Income, 77_000_00, $owner, ['description' => 'Secret income']);
        $this->actingAs($this->user('accountant', $mine, $second));

        $component = Livewire::test(Index::class)->call('confirmVoid', $voided->id)
            ->call('void')->assertHasErrors('voidReason')
            ->set('voidReason', 'Duplicate')->call('void')->assertHasNoErrors()
            ->assertViewHas('income', 12_000_00)->assertViewHas('expense', 3_500_00)
            ->assertSee('Wrong receipt')->assertSee('Voided')->assertSee('Overdue')->assertSee('Partly paid')->assertSee('Rahim Stores')
            ->assertDontSee('Secret income');
        $this->assertSame('Duplicate', $voided->fresh()->void_reason);
        $component->call('confirmVoid', $partly->id)->set('voidReason', 'Mistake')->call('void')->assertHasErrors('voidReason');
        $this->assertFalse($partly->fresh()->isVoided());

        $component->assertViewHas('showCompany', true)->assertSee($second->name);
        $this->context($mine);
        $component = Livewire::test(Index::class)->assertViewHas('showCompany', false)->assertDontSee($second->name);
        $ids = fn () => $component->viewData('entries')->pluck('id')->all();
        $component->assertViewHas('expense', 3_000_00)
            ->set('status', DueStatus::Overdue->value);
        $this->assertSame([$overdue->id], $ids());
        $component->set('status', DueStatus::PartlyPaid->value);
        $this->assertSame([$partly->id], $ids());
        $component->set('status', DueStatus::Paid->value);
        $this->assertNotContains($partly->id, $ids());
        $component->set('status', '')->set('party', (string) $customer->id);
        $this->assertEqualsCanonicalizing([$overdue->id, $partly->id, $partly->id + 1], $ids());
        $component->set('party', '')->set('from', '2026-09-05')->assertViewHas('income', 0)->assertViewHas('expense', 3_000_00)
            ->set('from', '')->set('search', 'consult')->assertSee('Consulting fee')->assertDontSee('Wrong receipt');
        $this->context($other);
        Livewire::test(Index::class)->assertViewHas('showCompany', true)->assertViewHas('income', 12_000_00)->assertDontSee('Secret income');
    }

    public function test_csv_export_contains_the_filtered_scoped_entries_with_dues(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $owner = User::factory()->create(['role' => 'owner', 'name' => '@SUM(A1)']);
        $party = Party::factory()->for($mine)->create(['name' => '=HYPERLINK("e")']);
        $mine->accounts()->where('code', '5100')->update(['code' => '=5100']);
        $bill = $this->bill($mine, EntryType::Expense, 25_000_00, $owner, ['category_account_id' => (int) $this->accountId($mine, '=5100'), 'paid_amount' => 5_000_00, 'party_id' => $party->id, 'due_date' => '2026-09-30',
            'description' => '=HYPERLINK("x")', 'reference' => '+1+1']);
        $payment = app(LedgerService::class)->settle($bill, ['entry_date' => '2026-09-02', 'amount' => 8_000_00, 'payment_account_id' => (int) $this->accountId($mine, '1010')], $owner);
        $voided = $this->bill($mine, EntryType::Income, 100, $owner, ['entry_date' => '2026-09-03']);
        app(LedgerService::class)->void($voided, '-2+3', $owner);
        $this->bill($other, EntryType::Income, 5_00, $owner, ['description' => 'Secret income']);
        $this->travelTo('2026-09-24 10:00');
        $this->actingAs($this->user('data-entry', $mine));

        $csv = $this->get(route('admin.entries.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $rows = array_map(str_getcsv(...), explode("\n", trim(substr($csv, 3))));

        $this->assertSame(['Number', 'Date', 'Company', 'Type', 'Party', 'Category', 'Payment method', 'Total (BDT)', 'Paid (BDT)', 'Due (BDT)', 'Due date', 'Due status',
            'Bill number', 'Description', 'Reference', 'Status', 'Void reason', 'Created by'], $rows[0]);
        $this->assertCount(4, $rows);
        $this->assertSame(['Expense', "'=HYPERLINK(\"e\")", "'=5100 · Office Rent", '1000 · Cash in Hand', '25000.00', '13000.00', '12000.00', '2026-09-30', 'Partly paid', '', "'=HYPERLINK(\"x\")", "'+1+1", 'Posted', "'@SUM(A1)"],
            array_values(array_intersect_key($rows[1], array_flip([3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 17]))));
        $this->assertSame(['Payment', '1010 · Bank Account', '8000.00', '8000.00', $bill->number], [$rows[2][3], $rows[2][6], $rows[2][7], $rows[2][8], $rows[2][12]]);
        $this->assertSame([$payment->number, 'Voided', "'-2+3", ''], [$rows[2][0], $rows[3][15], $rows[3][16], $rows[3][11]]);
        $this->assertStringNotContainsString('Secret income', $csv);

        $filtered = $this->get(route('admin.entries.export', ['status' => 'partly_paid', 'company' => [$other->id]]))->assertOk()->streamedContent();
        $this->assertCount(2, explode("\n", trim($filtered)));
        $this->assertStringNotContainsString('Secret income', $this->get(route('admin.entries.export', ['company' => $other->id]))->streamedContent());
        $this->actingAs(User::factory()->create(['role' => 'member']))->get(route('admin.entries.export'))->assertForbidden();
    }

    public function test_csv_export_follows_the_header_company(): void
    {
        [$alpha, $beta, $hidden] = Company::factory()->count(3)->create();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->bill($alpha, EntryType::Income, 100, $owner, ['description' => 'Alpha sale']);
        $this->bill($beta, EntryType::Income, 100, $owner, ['description' => 'Beta sale']);
        $this->bill($hidden, EntryType::Income, 100, $owner, ['description' => 'Hidden sale']);
        $this->actingAs($this->user('accountant', $alpha, $beta));
        $export = fn (array $query = []): string => $this->get(route('admin.entries.export', $query))->assertOk()->streamedContent();

        $all = $export();
        $this->assertStringContainsString('Alpha sale', $all);
        $this->assertStringContainsString('Beta sale', $all);
        $this->context($beta);
        $this->assertStringNotContainsString('Alpha sale', $export(['company' => $alpha->id]));
        $this->assertStringContainsString('Beta sale', $export());
        $this->context($hidden);
        $crafted = $export();
        $this->assertStringContainsString('Alpha sale', $crafted);
        $this->assertStringNotContainsString('Hidden sale', $crafted);
    }
}
