<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Livewire\Admin\Sales\Reports\Ageing;
use App\Livewire\Admin\Sales\Settings;
use App\Models\Account;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentSequence;
use App\Models\DocumentTemplate;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\LedgerService;
use App\Services\RecordDeletion;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Concerns\MakesDocuments;
use Tests\TestCase;

/** Guards against documents and the ledger drifting apart, found in the increment 13 review. */
class SalesSafeguardsTest extends TestCase
{
    use MakesDocuments, RefreshDatabase;

    private User $owner;

    private Company $company;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-20 10:00:00');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->company = Company::factory()->create(['code' => 'ACME']);
        $this->customer = Party::factory()->for($this->company)->create();
    }

    private function service(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function account(string $code): Account
    {
        return $this->company->accounts()->where('code', $code)->sole();
    }

    private function pay(Document $document, int $amount, string $date, string $method = '1000', ?User $by = null): void
    {
        $this->service()->recordPayment($document, ['paid_on' => $date, 'amount' => $amount, 'account_id' => $this->account($method)->id], $by ?? $this->owner);
    }

    private function assertRefused(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail("Expected a validation error on {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_accounts_on_documents_cannot_be_hard_deleted_and_a_transfer_moves_documents_too(): void
    {
        $other = Account::factory()->income()->for($this->company)->create();
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['A', 1, 1_000, 1500], ['B', 1, 2_000, 1500, $other->code]], ['post_to_accounts' => true]);
        $this->pay($invoice, 500, '2026-09-05');
        Item::factory()->for($this->company)->create(['account_id' => $this->account('4000')->id]);
        $deletion = app(RecordDeletion::class);

        $this->assertRefused(fn () => $deletion->hardDelete($this->account('4000'), $this->owner), 'record');
        $this->assertRefused(fn () => app(LedgerService::class)->purge($invoice->entry, $this->owner), 'entry');
        $this->assertSame(4, $invoice->entry->lines()->count());

        $deletion->transfer($this->account('4000'), $this->account('4900'), $this->owner);

        $this->assertSame([$this->account('4900')->id, $other->id], $invoice->lines()->pluck('account_id')->all());
        $this->assertSame($this->account('4900')->id, Item::query()->value('account_id'));
        $this->service()->save($invoice->fresh(), $this->company, DocumentType::Invoice, $this->dataOf($invoice->fresh()), $this->owner);
        $this->assertSame(1_000, app(LedgerService::class)->balance($this->account('4900')));
    }

    public function test_a_payment_method_used_only_by_a_document_payment_is_not_unused(): void
    {
        $bkash = $this->account('1020');
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['A', 1, 1_000]]);
        $this->pay($invoice, 400, '2026-09-05', '1020');

        $this->assertRefused(fn () => app(RecordDeletion::class)->deleteUnused($bkash, $this->owner), 'record');
        $bkash->update(['is_active' => false]);
        $this->assertRefused(fn () => $this->service()->postToAccounts($invoice, $this->owner), 'post_to_accounts');
        $this->assertFalse($invoice->fresh()->isPosted());
    }

    public function test_a_note_cannot_be_issued_or_changed_once_its_invoice_is_void(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 1_000]]);
        $note = $this->service()->convert($invoice, DocumentType::CreditNote, $this->owner);
        $this->service()->void($invoice, 'Wrong', $this->owner);

        $this->assertRefused(fn () => $this->service()->issue($note, $this->owner), 'document');
        $this->assertRefused(fn () => $this->service()->save($note, $this->company, DocumentType::CreditNote, $this->dataOf($note), $this->owner), 'document');
        $this->assertTrue($note->fresh()->isDraft());
    }

    public function test_lines_without_a_category_get_the_items_or_the_first_one_and_can_post(): void
    {
        $item = Item::factory()->for($this->company)->create(['account_id' => $this->account('4900')->id]);
        $invoice = $this->service()->save(null, $this->company, DocumentType::Invoice, ['party_id' => $this->customer->id, 'issue_date' => '2026-09-01',
            'post_to_accounts' => true, 'lines' => [
                ['item_id' => $item->id, 'account_id' => null, 'description' => 'Item', 'quantity' => 1000, 'unit_price' => 700],
                ['account_id' => null, 'description' => 'Free text', 'quantity' => 1000, 'unit_price' => 300],
            ]], $this->owner);

        $this->assertSame([$this->account('4900')->id, $this->account('4000')->id], $invoice->lines()->pluck('account_id')->all());
        $this->service()->issue($invoice, $this->owner);
        $this->assertSame([700, 300], [app(LedgerService::class)->balance($this->account('4900')), app(LedgerService::class)->balance($this->account('4000'))]);
    }

    public function test_ageing_uses_the_balance_owed_on_the_chosen_date(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 1_000]], ['due_date' => '2026-09-05']);
        $posted = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 2_000]], ['due_date' => '2026-09-05', 'post_to_accounts' => true]);
        $this->pay($invoice, 1_000, '2026-09-15');
        $this->pay($posted, 500, '2026-09-15');
        $this->actingAs($this->owner);
        session([CompanyContext::SESSION_KEY => $this->company->id]);

        Livewire::test(Ageing::class)->set('asOf', '2026-09-10')
            ->assertViewHas('totals', fn (array $totals): bool => $totals['days_1_30'] === 3_000)
            ->set('asOf', '2026-09-20')
            ->assertViewHas('parties', fn (Collection $parties): bool => $parties->sole()['count'] === 1 && $parties->sole()['total'] === 1_500);
    }

    public function test_sharing_again_after_a_link_expired_issues_a_new_link(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 1_000]]);
        $first = $this->service()->share($invoice, 7, $this->owner);
        $this->assertSame($first, $this->service()->share($invoice->fresh(), 30, $this->owner), 'A live link keeps its URL.');

        $this->travel(31)->days();
        $second = $this->service()->share($invoice->fresh(), null, $this->owner);

        $this->assertNotSame($first, $second);
        $this->get($first)->assertNotFound();
        $this->get($second)->assertOk();
    }

    public function test_edits_that_would_block_posting_later_are_refused(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 1_000]]);
        $this->pay($invoice, 100, '2026-09-03');
        $note = $this->service()->convert($invoice, DocumentType::CreditNote, $this->owner);
        $this->service()->save($note, $this->company, DocumentType::CreditNote, $this->dataOf($note, ['lines' => [
            ['description' => 'Discount', 'quantity' => 1000, 'unit_price' => 100, 'account_id' => $this->account('4000')->id]]]), $this->owner);
        $this->service()->issue($note, $this->owner);
        $stranger = Party::factory()->for($this->company)->create();

        $this->assertRefused(fn () => $this->service()->save($invoice, $this->company, DocumentType::Invoice, $this->dataOf($invoice, ['issue_date' => '2026-09-04']), $this->owner), 'issue_date');
        $this->assertRefused(fn () => $this->service()->save($invoice, $this->company, DocumentType::Invoice, $this->dataOf($invoice, ['party_id' => $stranger->id]), $this->owner), 'party_id');
    }

    public function test_replayed_payments_keep_who_recorded_them(): void
    {
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->companies()->attach($this->company);
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 1_000]]);
        $this->pay($invoice, 400, '2026-09-03', '1000', $accountant);

        $this->service()->postToAccounts($invoice, $this->owner);

        $this->assertSame($accountant->id, JournalEntry::query()->where('bill_id', $invoice->fresh()->journal_entry_id)->sole()->paid_by);
    }

    public function test_an_employee_whose_party_is_on_a_document_cannot_be_deleted(): void
    {
        $employee = User::factory()->employeeOf($this->company)->create();
        $this->draft($this->company, DocumentType::Invoice, $employee->parties()->sole(), [['Advance', 1, 100]]);

        $this->assertNotNull(app(RecordDeletion::class)->blockedReason($employee));
        $this->assertRefused(fn () => app(RecordDeletion::class)->deleteUnused($employee, $this->owner), 'record');
        $this->assertModelExists($employee);
    }

    public function test_documents_render_with_their_own_then_the_type_then_the_company_default_template(): void
    {
        $first = DocumentTemplate::create(['company_id' => $this->company->id, 'name' => 'First', 'sections' => DocumentTemplate::defaultSections()]);
        $default = DocumentTemplate::create(['company_id' => $this->company->id, 'name' => 'Default', 'sections' => DocumentTemplate::defaultSections()]);
        $default->forceFill(['is_default' => true])->save();
        $quotes = DocumentTemplate::create(['company_id' => $this->company->id, 'name' => 'Quotes', 'sections' => DocumentTemplate::defaultSections()]);
        DocumentSequence::for($this->company->id, DocumentType::Quotation)->update(['template_id' => $quotes->id]);

        $this->assertTrue(DocumentTemplate::forDocument($this->company->id, null, DocumentType::Invoice)->is($default));
        $this->assertTrue(DocumentTemplate::forDocument($this->company->id, null, DocumentType::Quotation)->is($quotes));
        $this->assertTrue(DocumentTemplate::forDocument($this->company->id, $first->id, DocumentType::Quotation)->is($first));
    }

    public function test_converting_an_offer_needs_sales_update_but_starting_a_note_needs_only_sales_create(): void
    {
        $clerk = User::factory()->create(['role' => 'data-entry']);
        $clerk->companies()->attach($this->company);
        $quote = $this->issued($this->company, DocumentType::Quotation, $this->customer, [['Work', 1, 1_000]]);
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 1_000]]);

        $this->expectException(AuthorizationException::class);
        try {
            $this->assertTrue($this->service()->convert($invoice, DocumentType::CreditNote, $clerk)->isDraft());
        } finally {
            $this->service()->convert($quote, DocumentType::Invoice, $clerk);
        }
    }

    public function test_numbering_settings_match_the_prefix_exactly(): void
    {
        $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 100]]);
        DocumentSequence::for($this->company->id, DocumentType::Invoice)->update(['prefix' => 'INVX', 'last_number' => 0]);
        $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 100]]);

        $this->assertSame(0, Settings::highestUsed($this->company->id, DocumentType::Invoice, 'INV_'));
        $this->assertSame(0, Settings::highestUsed($this->company->id, DocumentType::Invoice, 'inv-'));
        $this->assertSame(1, Settings::highestUsed($this->company->id, DocumentType::Invoice, 'INV-'));
    }
}
