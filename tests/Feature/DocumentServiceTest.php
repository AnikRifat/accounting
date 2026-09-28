<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DueStatus;
use App\Enums\EntryType;
use App\Enums\SystemAccount;
use App\Models\Company;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\LedgerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\MakesDocuments;
use Tests\TestCase;

class DocumentServiceTest extends TestCase
{
    use MakesDocuments, RefreshDatabase;

    private User $owner;

    private Company $company;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->company = Company::factory()->create(['code' => 'ACME']);
        $this->customer = Party::factory()->for($this->company)->create();
    }

    private function service(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function withBalance(Document $document): Document
    {
        return Document::query()->withBalance()->findOrFail($document->id);
    }

    private function balanceOf(string $code): int
    {
        return app(LedgerService::class)->balance($this->company->accounts()->where('code', $code)->sole());
    }

    private function pay(Document $document, int $amount, string $date = '2026-09-05'): void
    {
        $this->service()->recordPayment($document, ['paid_on' => $date, 'amount' => $amount,
            'account_id' => (int) $this->company->accounts()->where('code', '1000')->value('id')], $this->owner);
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

    public function test_drafts_are_priced_on_the_server_and_numbered_only_when_issued(): void
    {
        $draft = $this->draft($this->company, DocumentType::Invoice, $this->customer, [['Design', 2, 50_000, 1500], ['Hosting', 1, 20_000]]);

        $this->assertNull($draft->number);
        $this->assertSame(DocumentStatus::Draft, $draft->status);
        $this->assertSame([120_000, 15_000, 135_000], [$draft->subtotal, $draft->tax_total, $draft->total]);
        $this->assertSame(0, JournalEntry::count());

        $first = $this->service()->issue($draft, $this->owner);
        $second = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['More', 1, 100]]);
        $quote = $this->issued($this->company, DocumentType::Quotation, $this->customer, [['Offer', 1, 100]]);

        $this->assertSame(['INV-00001', 'INV-00002', 'QUO-00001'], [$first->number, $second->number, $quote->number]);
        $this->assertSame(DocumentStatus::Issued, $first->status);
        $this->assertSame(0, JournalEntry::count(), 'Post to accounts is off by default.');
    }

    public function test_an_unposted_invoice_tracks_its_own_payments_and_status(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 10_000]]);

        $this->pay($invoice, 4_000);
        $this->assertSame(6_000, $this->withBalance($invoice)->balance());
        $this->assertSame(DueStatus::PartlyPaid, $this->withBalance($invoice)->dueStatus());
        $this->assertRefused(fn () => $this->pay($invoice, 6_001), 'amount');
        $this->assertRefused(fn () => $this->pay($invoice, 100, '2026-08-31'), 'paid_on');

        $this->pay($invoice, 6_000);
        $this->assertSame(DueStatus::Paid, $this->withBalance($invoice)->dueStatus());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame([$invoice->id], Document::query()->dueStatus(DueStatus::Paid)->pluck('id')->all());
        $this->assertRefused(fn () => $this->service()->save($invoice, $this->company, DocumentType::Invoice,
            $this->dataOf($invoice, ['lines' => [['description' => 'Work', 'quantity' => 1000, 'unit_price' => 9_000]]]), $this->owner), 'lines');
    }

    public function test_a_posted_invoice_books_income_net_of_vat_and_is_paid_through_the_ledger(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Design', 1, 100_000, 1500]], ['post_to_accounts' => true]);
        $entry = $invoice->entry;

        $this->assertSame(EntryType::Income, $entry->type);
        $this->assertSame(115_000, $entry->amount);
        $this->assertSame($this->customer->id, $entry->party_id);
        $this->assertSame([115_000, 100_000, 15_000], [$this->balanceOf('1200'), $this->balanceOf('4000'), $this->balanceOf('2100')]);

        $this->pay($invoice, 15_000);
        $receipt = JournalEntry::query()->where('bill_id', $entry->id)->sole();
        $this->assertSame(EntryType::Receipt, $receipt->type);
        $this->assertSame(100_000, $this->withBalance($invoice)->balance());
        $this->assertSame(100_000, (int) app(LedgerService::class)->dues([$this->company->id])->sole()->outstanding);
        $this->assertSame(0, $invoice->payments()->count());

        $this->assertRefused(fn () => app(LedgerService::class)->update($entry, [], $this->owner), 'entry');
        $this->assertRefused(fn () => app(LedgerService::class)->void($entry, 'x', $this->owner), 'entry');
        $this->assertRefused(fn () => app(LedgerService::class)->delete($entry, $this->owner), 'entry');
    }

    public function test_editing_a_posted_invoice_reposts_it_and_keeps_what_was_paid(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Design', 1, 100_000]], ['post_to_accounts' => true]);
        $this->pay($invoice, 60_000);

        $this->service()->save($invoice, $this->company, DocumentType::Invoice,
            $this->dataOf($invoice, ['lines' => [['description' => 'Design', 'quantity' => 1000, 'unit_price' => 80_000, 'tax_rate' => 1500,
                'account_id' => (int) $this->company->accounts()->where('code', '4900')->value('id')]]]), $this->owner);

        $this->assertSame(92_000, $invoice->entry->fresh()->amount);
        $this->assertSame([0, 80_000, 12_000, 32_000], [$this->balanceOf('4000'), $this->balanceOf('4900'), $this->balanceOf('2100'), $this->balanceOf('1200')]);
        $this->assertRefused(fn () => $this->service()->save($invoice->fresh(), $this->company, DocumentType::Invoice,
            $this->dataOf($invoice->fresh(), ['lines' => [['description' => 'Design', 'quantity' => 1000, 'unit_price' => 50_000,
                'account_id' => (int) $this->company->accounts()->where('code', '4000')->value('id')]]]), $this->owner), 'lines');
    }

    public function test_switching_posting_on_later_replays_payments_and_notes_into_the_ledger(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 50_000, 1000]]);
        $this->pay($invoice, 20_000, '2026-09-03');
        $note = $this->service()->convert($invoice, DocumentType::CreditNote, $this->owner);
        $this->service()->save($note, $this->company, DocumentType::CreditNote,
            $this->dataOf($note, ['lines' => [['description' => 'Refund', 'quantity' => 1000, 'unit_price' => 10_000, 'tax_rate' => 1000,
                'account_id' => (int) $this->company->accounts()->where('code', '4000')->value('id')]]]), $this->owner);
        $this->service()->issue($note, $this->owner);
        $this->assertSame(55_000 - 20_000 - 11_000, $this->withBalance($invoice)->balance());
        $this->assertFalse($note->fresh()->post_to_accounts, 'A note follows its unposted invoice.');

        $this->service()->postToAccounts($invoice, $this->owner);

        $this->assertSame(24_000, $this->withBalance($invoice)->balance());
        $this->assertSame(0, $invoice->payments()->count());
        $this->assertTrue($note->fresh()->isPosted());
        $this->assertSame(EntryType::CreditNote, $note->fresh()->entry->type);
        $this->assertSame('2026-09-03', JournalEntry::query()->where('type', EntryType::Receipt)->sole()->entry_date->toDateString());
        $this->assertSame([24_000, 40_000, 4_000], [$this->balanceOf('1200'), $this->balanceOf('4000'), $this->balanceOf('2100')]);
        $this->assertRefused(fn () => $this->service()->postToAccounts($invoice->fresh(), $this->owner), 'post_to_accounts');
    }

    public function test_credit_notes_cannot_exceed_what_is_owed_and_block_voiding_the_invoice(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 10_000]], ['post_to_accounts' => true]);
        $this->pay($invoice, 7_000);
        $note = $this->service()->convert($invoice, DocumentType::CreditNote, $this->owner);

        $this->assertTrue($note->post_to_accounts);
        $this->assertRefused(fn () => $this->service()->issue($note, $this->owner), 'lines');
        $this->service()->save($note, $this->company, DocumentType::CreditNote,
            $this->dataOf($note, ['lines' => [['description' => 'Discount', 'quantity' => 1000, 'unit_price' => 3_000,
                'account_id' => (int) $this->company->accounts()->where('code', '4000')->value('id')]]]), $this->owner);
        $this->service()->issue($note, $this->owner);

        $this->assertSame(DueStatus::Paid, $this->withBalance($invoice)->dueStatus());
        $this->assertSame(7_000, $this->balanceOf('4000'));
        $this->assertRefused(fn () => $this->service()->void($invoice->fresh(), 'Wrong', $this->owner), 'reason');
    }

    public function test_a_note_drafted_before_its_invoice_was_posted_is_posted_when_issued(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 10_000]]);
        $note = $this->service()->convert($invoice, DocumentType::CreditNote, $this->owner);
        $this->service()->save($note, $this->company, DocumentType::CreditNote,
            $this->dataOf($note, ['lines' => [['description' => 'Discount', 'quantity' => 1000, 'unit_price' => 1_000,
                'account_id' => (int) $this->company->accounts()->where('code', '4000')->value('id')]]]), $this->owner);
        $this->service()->postToAccounts($invoice, $this->owner);

        $this->service()->issue($note->fresh(), $this->owner);

        $this->assertTrue($note->fresh()->isPosted());
        $this->assertSame(9_000, $this->withBalance($invoice)->balance());
        $this->assertSame(9_000, $this->balanceOf('1200'));
    }

    public function test_a_posted_bill_books_the_full_cost_to_payable_and_a_debit_note_reduces_it(): void
    {
        $supplier = Party::factory()->for($this->company)->create();
        $bill = $this->issued($this->company, DocumentType::Bill, $supplier, [['Paper', 10, 500, 1500]], ['post_to_accounts' => true]);

        $this->assertSame(EntryType::Expense, $bill->entry->type);
        $this->assertSame([5_750, 5_750, 0], [$this->balanceOf('2000'), $this->balanceOf('5400'), $this->balanceOf('2100')]);

        $note = $this->service()->convert($bill, DocumentType::DebitNote, $this->owner);
        $this->service()->save($note, $this->company, DocumentType::DebitNote,
            $this->dataOf($note, ['lines' => [['description' => 'Returned', 'quantity' => 2000, 'unit_price' => 500, 'tax_rate' => 1500,
                'account_id' => (int) $this->company->accounts()->where('code', '5400')->value('id')]]]), $this->owner);
        $this->service()->issue($note, $this->owner);

        $this->assertSame([4_600, 4_600], [$this->balanceOf('2000'), $this->balanceOf('5400')]);
        $this->assertSame(4_600, $this->withBalance($bill)->balance());
    }

    public function test_offers_convert_once_and_a_voided_conversion_reopens_the_offer(): void
    {
        $quote = $this->issued($this->company, DocumentType::Quotation, $this->customer, [['Work', 3, 1_000, 1500]]);
        $invoice = $this->service()->convert($quote, DocumentType::Invoice, $this->owner);

        $this->assertSame(DocumentStatus::Converted, $quote->fresh()->status);
        $this->assertSame([$quote->id, 3_450, 1], [$invoice->source_id, $invoice->total, $invoice->lines()->count()]);
        $this->assertRefused(fn () => $this->service()->convert($quote->fresh(), DocumentType::Invoice, $this->owner), 'document');
        $this->assertRefused(fn () => $this->service()->convert($quote->fresh(), DocumentType::Bill, $this->owner), 'document');

        $this->service()->deleteDraft($invoice, $this->owner);
        $this->assertSame(DocumentStatus::Issued, $quote->fresh()->status);
    }

    public function test_voiding_needs_a_reason_and_voids_the_posted_entry(): void
    {
        $posted = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 1_000]], ['post_to_accounts' => true]);
        $unposted = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 1_000]]);
        $this->pay($unposted, 500);

        $this->assertRefused(fn () => $this->service()->void($posted, ' ', $this->owner), 'reason');
        $this->assertRefused(fn () => $this->service()->void($unposted, 'Wrong', $this->owner), 'reason');
        $this->service()->void($posted, 'Wrong customer', $this->owner);

        $this->assertSame(DocumentStatus::Void, $posted->fresh()->status);
        $this->assertTrue($posted->entry->fresh()->isVoided());
        $this->assertSame(0, $this->balanceOf('1200'));
        $this->assertNull($this->withBalance($posted)->balance());
        $this->assertRefused(fn () => $this->service()->save($posted->fresh(), $this->company, DocumentType::Invoice, $this->dataOf($posted), $this->owner), 'document');
    }

    public function test_documents_are_confined_to_the_companies_a_user_can_access(): void
    {
        $other = Company::factory()->create();
        $stranger = Party::factory()->for($other)->create();
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->companies()->attach($this->company);
        $foreign = $this->draft($other, DocumentType::Invoice, $stranger, [['Work', 1, 100]]);

        $this->assertRefused(fn () => app(DocumentService::class)->save(null, $this->company, DocumentType::Invoice,
            $this->dataOf($foreign, ['party_id' => $stranger->id]), $accountant), 'party_id');
        foreach ([fn () => app(DocumentService::class)->issue($foreign, $accountant),
            fn () => app(DocumentService::class)->save(null, $other, DocumentType::Invoice, $this->dataOf($foreign), $accountant)] as $action) {
            try {
                $action();
                $this->fail('A company the user cannot access must be refused.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(1, Document::count());
        $this->assertTrue($foreign->fresh()->isDraft());
    }

    public function test_inactive_companies_accept_no_documents_and_numbers_survive_drafts_being_deleted(): void
    {
        $draft = $this->draft($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 100]]);
        $this->service()->deleteDraft($draft, $this->owner);
        $this->assertSame('INV-00001', $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 100]])->number);

        $this->company->update(['is_active' => false]);
        $this->assertRefused(fn () => $this->draft($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 100]]), 'company');
        $this->assertNotNull(app(LedgerService::class)->systemAccount($this->company->id, SystemAccount::VatPayable));
    }
}
