<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\EntryType;
use App\Livewire\Admin\Sales\Dashboard;
use App\Livewire\Admin\Sales\Documents\Form;
use App\Livewire\Admin\Sales\Documents\Index;
use App\Livewire\Admin\Sales\Documents\Show;
use App\Mail\DocumentMail;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentPayment;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\DocumentMailer;
use App\Services\DocumentService;
use App\Services\RecurringInvoices;
use App\Support\CompanyContext;
use App\Support\Permissions;
use App\Support\WhatsApp;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Concerns\MakesDocuments;
use Tests\TestCase;

class SalesDocumentsTest extends TestCase
{
    use MakesDocuments, RefreshDatabase;

    private User $owner;

    private Company $acme;

    private Company $beta;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-29 10:00:00');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->acme = Company::factory()->create(['code' => 'ACME', 'name' => 'Acme Ltd']);
        $this->beta = Company::factory()->create(['code' => 'BETA', 'name' => 'Beta Ltd']);
        $this->customer = Party::factory()->for($this->acme)->create(['name' => 'Acme Customer', 'phone' => '01711-000000', 'email' => 'buyer@example.com']);
    }

    private function accountant(Company ...$companies): User
    {
        $user = User::factory()->create(['role' => 'accountant']);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    private function context(?Company $company): void
    {
        session([CompanyContext::SESSION_KEY => $company?->id]);
    }

    private function accountId(Company $company, string $code): string
    {
        return (string) $company->accounts()->where('code', $code)->value('id');
    }

    private function invoice(array $extra = []): Document
    {
        return $this->issued($this->acme, DocumentType::Invoice, $this->customer, [['Design', 1, 100_000]], $extra);
    }

    private function assertLocked(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('A locked property was changed.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_the_index_lists_only_that_type_in_the_header_scope_and_404s_an_unknown_type(): void
    {
        $betaCustomer = Party::factory()->for($this->beta)->create(['name' => 'Beta Customer']);
        $this->invoice();
        $this->draft($this->acme, DocumentType::Invoice, Party::factory()->for($this->acme)->create(['name' => 'Draft Customer']), [['Draft work', 1, 500]]);
        $this->issued($this->beta, DocumentType::Invoice, $betaCustomer, [['Beta work', 1, 100]]);
        $this->issued($this->acme, DocumentType::Quotation, Party::factory()->for($this->acme)->create(['name' => 'Quote Customer']), [['Offer', 1, 100]]);

        $listed = fn (string ...$parties): Closure => fn (LengthAwarePaginator $documents): bool => $documents->getCollection()
            ->map(fn (Document $document): string => $document->party->name)->sort()->values()->all() === collect($parties)->sort()->values()->all();
        $this->actingAs($this->owner);
        $this->context($this->acme);
        Livewire::test(Index::class, ['type' => 'invoice'])
            ->assertViewHas('documents', $listed('Acme Customer', 'Draft Customer'))
            ->assertSee(__('New :type', ['type' => 'invoice']))
            ->set('search', 'Draft Cust')->assertViewHas('documents', $listed('Draft Customer'))
            ->set('search', '')->set('status', 'due:due')->assertViewHas('documents', $listed('Acme Customer'))
            ->set('status', 'draft')->assertViewHas('documents', $listed('Draft Customer'));

        $this->context(null);
        Livewire::test(Index::class, ['type' => 'invoice'])->assertViewHas('documents', $listed('Acme Customer', 'Draft Customer', 'Beta Customer'))
            ->assertDontSee(__('New :type', ['type' => 'invoice']));

        $this->actingAs($this->accountant($this->acme));
        Livewire::test(Index::class, ['type' => 'invoice'])->assertViewHas('documents', $listed('Acme Customer', 'Draft Customer'))->assertDontSee('Beta Customer');
        Livewire::test(Index::class, ['type' => 'receipts'])->assertNotFound();
        $this->get(route('admin.sales.invoices.index'))->assertOk()->assertSee('Acme Customer');
    }

    public function test_a_user_of_another_company_cannot_open_edit_or_pay_its_documents(): void
    {
        $theirs = $this->issued($this->beta, DocumentType::Invoice, Party::factory()->for($this->beta)->create(), [['Beta work', 1, 10_000]]);
        $theirPayment = DocumentPayment::query()->forceCreate(['document_id' => $theirs->id, 'paid_on' => '2026-09-02', 'amount' => 100,
            'account_id' => (int) $this->accountId($this->beta, '1000'), 'created_by' => $this->owner->id]);
        $mine = $this->invoice();
        $this->actingAs($this->accountant($this->acme));
        $this->context($this->acme);

        $this->get(route('admin.sales.documents.show', $theirs))->assertNotFound();
        $this->get(route('admin.sales.documents.edit', $theirs))->assertNotFound();
        $this->get(route('admin.sales.documents.show', $mine))->assertOk()->assertSee('Acme Customer');
        $this->get(route('admin.sales.documents.edit', $mine))->assertOk()->assertSee('Design');
        Livewire::test(Show::class, ['document' => $theirs])->assertNotFound();
        Livewire::test(Form::class, ['document' => $theirs])->assertNotFound();

        $show = Livewire::test(Show::class, ['document' => $mine]);
        $this->assertLocked(fn () => $show->set('documentId', $theirs->id));
        $this->expectException(ModelNotFoundException::class);
        try {
            $show->call('deletePayment', $theirPayment->id);
        } finally {
            $this->assertDatabaseHas('document_payments', ['id' => $theirPayment->id]);
            $form = Livewire::test(Form::class, ['document' => $mine]);
            $this->assertLocked(fn () => $form->set('companyId', $this->beta->id));
            $this->assertLocked(fn () => $form->set('documentId', $theirs->id));
        }
    }

    public function test_a_credit_note_can_only_be_started_against_an_issued_invoice_of_the_header_company(): void
    {
        $theirs = $this->issued($this->beta, DocumentType::Invoice, Party::factory()->for($this->beta)->create(), [['Beta work', 1, 10_000]]);
        $draft = $this->draft($this->acme, DocumentType::Invoice, $this->customer, [['Draft', 1, 100]]);
        $mine = $this->invoice();
        $this->actingAs($this->owner);
        $this->context($this->acme);

        $this->get(route('admin.sales.credit-notes.create', ['source' => $theirs->id]))->assertNotFound();
        $this->get(route('admin.sales.credit-notes.create', ['source' => $draft->id]))->assertNotFound();
        $this->get(route('admin.sales.credit-notes.create', ['source' => $mine->id]))->assertOk();

        Livewire::test(Form::class, ['type' => 'credit_note'])
            ->set('sourceId', (string) $theirs->id)->assertSet('sourceId', '')->assertSet('partyId', '')
            ->set('sourceId', (string) $mine->id)->assertSet('partyId', (string) $this->customer->id)->assertSet('reference', $mine->number)
            ->assertSet('lines.0.description', 'Design')->set('lines.0.price', '100')->call('save');

        $note = Document::query()->where('type', DocumentType::CreditNote)->sole();
        $this->assertSame([$mine->id, 10_000], [$note->source_id, $note->total]);
    }

    public function test_the_form_saves_a_draft_priced_on_the_server_from_string_inputs(): void
    {
        $this->actingAs($this->owner);
        $this->context($this->acme);

        Livewire::test(Form::class, ['type' => 'invoice'])
            ->assertSet('companyId', $this->acme->id)->assertSet('lines.0.vat', '15')->assertSet('postToAccounts', false)
            ->set('partyId', (string) $this->customer->id)
            ->set('lines.0.description', 'Consulting')->set('lines.0.quantity', '1.5')->set('lines.0.price', '1,250.50')->set('lines.0.vat', '15')
            ->assertSee('৳2,157.11')
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $this->get(route('admin.sales.contracts.create'))->assertOk()->assertSee('{party.name}');
        $document = Document::query()->with('lines')->sole();
        $line = $document->lines->sole();
        $this->assertSame([DocumentStatus::Draft, null, $this->acme->id], [$document->status, $document->number, $document->company_id]);
        $this->assertSame([1500, 125_050, 1500, 187_575, 28_136], [$line->quantity, $line->unit_price, $line->tax_rate, $line->amount, $line->tax]);
        $this->assertSame([187_575, 28_136, 215_711], [$document->subtotal, $document->tax_total, $document->total]);
        $this->assertSame((int) $this->accountId($this->acme, '4000'), $line->account_id, 'Lines default to the first income category.');
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_malformed_input_and_service_errors_land_on_the_form_rows(): void
    {
        $this->actingAs($this->owner);
        $this->context($this->acme);

        Livewire::test(Form::class, ['type' => 'invoice'])
            ->set('partyId', (string) $this->customer->id)
            ->set('lines.0.description', 'Work')->set('lines.0.quantity', '1,5')->set('lines.0.price', '12.345')->set('lines.0.vat', '150')
            ->call('save')->assertHasErrors(['lines.0.quantity', 'lines.0.price', 'lines.0.vat']);

        // Row 0 is blank and skipped, so the service's line 0 is form row 1.
        $form = Livewire::test(Form::class, ['type' => 'invoice'])
            ->set('partyId', (string) Party::factory()->for($this->beta)->create()->id)
            ->call('addLine')->set('lines.1.price', '100')
            ->call('save')->assertHasErrors(['lines.1.description'])->assertHasNoErrors(['lines.0.description']);
        $form->set('lines.1.description', 'Work')->set('lines.1.account', $this->accountId($this->beta, '4000'))
            ->call('save')->assertHasErrors(['partyId', 'lines.1.account'])->assertHasNoErrors(['lines.1.description']);

        $this->assertSame(0, Document::count());
    }

    public function test_save_and_issue_numbers_the_document(): void
    {
        $this->actingAs($this->owner);
        $this->context($this->acme);

        Livewire::test(Form::class, ['type' => 'invoice'])
            ->set('partyId', (string) $this->customer->id)->set('lines.0.description', 'Work')->set('lines.0.price', '500')
            ->call('save', true)->assertHasNoErrors()->assertRedirect();

        $document = Document::query()->sole();
        $this->assertSame([DocumentStatus::Issued, 'INV-00001'], [$document->status, $document->number]);
    }

    public function test_post_to_accounts_is_offered_only_with_the_ledger_permission(): void
    {
        RolePermission::factory()->create(['role' => 'sales-clerk', 'permissions' => ['admin.access', 'sales.view', 'sales.create', 'sales.update']]);
        app(Permissions::class)->flush();
        $clerk = User::factory()->create(['role' => 'sales-clerk']);
        $clerk->companies()->attach($this->acme);
        $this->context($this->acme);

        $this->actingAs($clerk);
        $label = __('Post to accounts');
        Livewire::test(Form::class, ['type' => 'invoice'])->assertDontSee($label)
            ->set('postToAccounts', true)->set('partyId', (string) $this->customer->id)->set('lines.0.description', 'Work')->set('lines.0.price', '500')
            ->call('save', true)->assertHasNoErrors();
        $document = Document::query()->sole();
        $this->assertFalse($document->post_to_accounts);
        $this->assertNull($document->journal_entry_id);
        Livewire::test(Show::class, ['document' => $document])->assertDontSee($label)->call('postToAccounts')->assertForbidden();

        $this->actingAs($this->owner);
        Livewire::test(Form::class, ['type' => 'invoice'])->assertSee($label);
        Livewire::test(Form::class, ['type' => 'quotation'])->assertDontSee($label);
        Livewire::test(Show::class, ['document' => $document])->assertSee($label)->call('postToAccounts')->assertHasNoErrors();
        $this->assertNotNull($document->fresh()->journal_entry_id);
    }

    public function test_payments_are_recorded_on_the_document_or_in_the_books(): void
    {
        $unposted = $this->invoice();
        $posted = $this->invoice(['post_to_accounts' => true]);
        $cash = $this->accountId($this->acme, '1000');
        $this->actingAs($this->owner);
        $this->context($this->acme);

        Livewire::test(Show::class, ['document' => $unposted])
            ->call('openPayment')->assertSet('paymentAmount', '1000.00')->assertSet('paymentAccountId', $cash)
            ->set('paymentAmount', '1,000.01')->call('recordPayment')->assertHasErrors('paymentAmount')
            ->set('paymentAmount', '400')->call('recordPayment')->assertHasNoErrors()->assertSet('recordingPayment', false)
            ->assertSee(route('admin.sales.documents.receipt', ['document' => $unposted, 'kind' => 'payment', 'id' => DocumentPayment::query()->value('id')]));
        $this->assertSame(40_000, (int) DocumentPayment::query()->where('document_id', $unposted->id)->sum('amount'));
        $this->assertSame(0, JournalEntry::query()->where('type', EntryType::Receipt)->count());

        Livewire::test(Show::class, ['document' => $posted])
            ->call('openPayment')->set('paymentAmount', '250')->call('recordPayment')->assertHasNoErrors();
        $receipt = JournalEntry::query()->where('type', EntryType::Receipt)->sole();
        $this->assertSame([$posted->journal_entry_id, 25_000], [$receipt->bill_id, $receipt->amount]);
        $this->assertSame(0, DocumentPayment::query()->where('document_id', $posted->id)->count());

        $payment = DocumentPayment::query()->sole();
        Livewire::test(Show::class, ['document' => $unposted])->call('deletePayment', $payment->id);
        $this->assertModelMissing($payment);
    }

    public function test_a_document_is_voided_only_with_a_reason(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->owner);

        Livewire::test(Show::class, ['document' => $invoice])
            ->call('openVoid')->call('void')->assertHasErrors('voidReason')
            ->set('voidReason', 'Raised twice')->call('void')->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame([DocumentStatus::Void, 'Raised twice'], [$invoice->status, $invoice->void_reason]);
    }

    public function test_a_quotation_converts_to_an_invoice_draft_and_an_invoice_starts_a_credit_note(): void
    {
        $quotation = $this->issued($this->acme, DocumentType::Quotation, $this->customer, [['Offer', 2, 5_000]]);
        $this->actingAs($this->owner);

        Livewire::test(Show::class, ['document' => $quotation])->assertSee(__('Convert to :type', ['type' => 'invoice']))
            ->call('convert', 'bill')->assertNotFound();
        $component = Livewire::test(Show::class, ['document' => $quotation])->call('convert', 'invoice');
        $invoice = Document::query()->where('type', DocumentType::Invoice)->sole();
        $component->assertRedirect(route('admin.sales.documents.edit', $invoice));
        $this->assertSame([DocumentStatus::Draft, $quotation->id, 10_000], [$invoice->status, $invoice->source_id, $invoice->total]);
        $this->assertSame(DocumentStatus::Converted, $quotation->fresh()->status);

        $issued = app(DocumentService::class)->issue($invoice, $this->owner);
        $component = Livewire::test(Show::class, ['document' => $issued])->call('createNote');
        $note = Document::query()->where('type', DocumentType::CreditNote)->sole();
        $component->assertRedirect(route('admin.sales.documents.edit', $note));
        $this->assertSame([$issued->id, $this->customer->id], [$note->source_id, $note->party_id]);
    }

    public function test_offers_are_accepted_declined_and_reopened(): void
    {
        $quotation = $this->issued($this->acme, DocumentType::Quotation, $this->customer, [['Offer', 1, 5_000]]);
        $this->actingAs($this->owner);

        $component = Livewire::test(Show::class, ['document' => $quotation])->call('respond', 'accepted');
        $this->assertSame(DocumentStatus::Accepted, $quotation->fresh()->status);
        $component->call('respond', 'issued')->call('respond', 'declined')->call('respond', 'void')->assertNotFound();
        $this->assertSame(DocumentStatus::Declined, $quotation->fresh()->status);
    }

    public function test_a_share_link_is_created_with_a_whatsapp_button_and_revoked(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->owner);

        $component = Livewire::test(Show::class, ['document' => $invoice])->set('shareDays', '7')->call('share')->assertHasNoErrors();
        $invoice->refresh();
        $this->assertNotNull($invoice->share_token);
        $this->assertTrue($invoice->share_expires_at->isSameDay(now()->addDays(7)));
        $url = route('documents.shared', $invoice->share_token);
        $component->assertSee($url)->assertSee('https://wa.me/8801711000000?text=', false);

        $component->set('shareDays', '365')->call('share')->assertHasErrors('shareDays');
        $component->call('revokeShare');
        $this->assertNull($invoice->fresh()->share_token);
        $component->assertDontSee($url);
    }

    public function test_email_sends_through_the_mailer_and_a_failure_is_shown(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->owner);
        $mailer = $this->mock(DocumentMailer::class);
        $mailer->shouldReceive('send')->once()->withArgs(fn (Document $document, string $to, ?string $cc): bool => $document->is($invoice)
            && $to === 'buyer@example.com' && $cc === 'boss@example.com, ops@example.com');
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));

        $component = Livewire::test(Show::class, ['document' => $invoice])->call('openEmail')
            ->assertSet('emailTo', 'buyer@example.com')->assertSet('emailSubject', 'Invoice '.$invoice->number.' from Acme Ltd')
            ->set('emailCc', 'not-an-address')->call('sendEmail')->assertHasErrors('emailCc')
            ->set('emailCc', 'boss@example.com; ops@example.com')->call('sendEmail')->assertHasNoErrors()->assertSet('emailing', false);

        $component->call('openEmail')->call('sendEmail')->assertHasErrors('emailTo')->assertSet('emailing', true)
            ->assertSee(__('The email was not sent. Check the mail settings or try again later.'));
    }

    public function test_documents_are_emailed_from_the_company_sender(): void
    {
        $invoice = $this->invoice();
        $mail = fn (): DocumentMail => new DocumentMail($invoice->fresh('company'), 'Invoice', 'Hi', null, '%PDF', 'invoice.pdf');
        $this->assertNull($mail()->envelope()->from);

        $this->acme->update(['mail_from_address' => 'billing@acme.test']);
        $this->assertTrue($mail()->hasFrom('billing@acme.test', 'Acme Ltd'));
    }

    public function test_drafts_are_issued_or_deleted_from_the_show_page(): void
    {
        $draft = $this->draft($this->acme, DocumentType::Invoice, $this->customer, [['Work', 1, 100]]);
        $other = $this->draft($this->acme, DocumentType::Invoice, $this->customer, [['Work', 1, 100]]);
        $this->actingAs($this->owner);

        Livewire::test(Show::class, ['document' => $draft])->call('issue')->assertHasNoErrors();
        $this->assertSame('INV-00001', $draft->fresh()->number);
        Livewire::test(Show::class, ['document' => $other])->call('deleteDraft')->assertRedirect(route('admin.sales.invoices.index'));
        $this->assertModelMissing($other);
    }

    public function test_the_dashboard_shows_the_months_figures_for_the_header_scope(): void
    {
        $paid = $this->invoice(['issue_date' => '2026-09-05', 'due_date' => '2026-10-05']);
        app(DocumentService::class)->recordPayment($paid, ['paid_on' => '2026-09-10', 'amount' => 30_000,
            'account_id' => (int) $this->accountId($this->acme, '1000')], $this->owner);
        $posted = $this->invoice(['issue_date' => '2026-09-06', 'due_date' => '2026-09-10', 'post_to_accounts' => true]);
        app(DocumentService::class)->recordPayment($posted, ['paid_on' => '2026-09-11', 'amount' => 20_000,
            'account_id' => (int) $this->accountId($this->acme, '1000')], $this->owner);
        $this->issued($this->acme, DocumentType::CreditNote, $this->customer, [['Discount', 1, 10_000]], ['source_id' => $paid->id]);
        $this->issued($this->acme, DocumentType::Bill, Party::factory()->for($this->acme)->create(), [['Rent', 1, 50_000]]);
        $this->issued($this->acme, DocumentType::Quotation, Party::factory()->for($this->acme)->create(['name' => 'Waiting Customer']), [['Offer', 1, 100]]);
        $this->issued($this->beta, DocumentType::Invoice, Party::factory()->for($this->beta)->create(), [['Beta work', 1, 999_900]]);
        $this->actingAs($this->owner);
        $this->context($this->acme);

        Livewire::test(Dashboard::class)
            ->assertViewHas('invoiced', 190_000)
            ->assertViewHas('received', 50_000)
            ->assertViewHas('receivable', 60_000 + 80_000)
            ->assertViewHas('payable', 50_000)
            ->assertViewHas('overdueCount', 1)
            ->assertSee('৳1,900.00')->assertSee('Waiting Customer')->assertSee($posted->number)
            ->assertSee(__('New invoice'));

        $this->context(null);
        Livewire::test(Dashboard::class)->assertViewHas('invoiced', 190_000 + 999_900)->assertDontSee(__('New invoice'));
        $this->get(route('admin.sales.dashboard'))->assertOk();
    }

    public function test_every_document_type_has_a_list_create_and_show_page(): void
    {
        $this->actingAs($this->owner);
        $this->context($this->acme);
        $sources = [DocumentType::CreditNote->value => $this->invoice(),
            DocumentType::DebitNote->value => $this->issued($this->acme, DocumentType::Bill, $this->customer, [['Rent', 1, 10_000]])];
        foreach (DocumentType::cases() as $type) {
            $this->get(route('admin.sales.'.$type->slug().'.index'))->assertOk()->assertSee($type->pluralLabel());
            $this->get(route('admin.sales.'.$type->slug().'.create'))->assertOk()->assertSee(__('New :type', ['type' => mb_strtolower($type->label())]));
            $document = $type->isNote()
                ? $this->draft($this->acme, $type, $this->customer, [['Work', 1, 100]], ['source_id' => $sources[$type->value]->id])
                : $this->draft($this->acme, $type, $type === DocumentType::Contract ? null : $this->customer, $type->hasLines() ? [['Work', 1, 100]] : [], ['title' => 'Terms of work']);
            $this->get(route('admin.sales.documents.show', $document))->assertOk();
            $this->get(route('admin.sales.documents.edit', $document))->assertOk();
        }
    }

    public function test_the_invoice_list_filters_the_drafts_of_one_recurring_schedule(): void
    {
        $source = $this->invoice();
        $schedule = app(RecurringInvoices::class)->save(null, $this->acme, ['source_id' => $source->id, 'name' => 'Monthly retainer',
            'frequency' => 'monthly', 'day' => 1, 'starts_on' => '2026-09-01', 'ends_on' => null, 'is_active' => true], $this->owner);
        app(RecurringInvoices::class)->run();
        $generated = Document::query()->where('recurring_invoice_id', $schedule->id)->pluck('id');
        $this->assertNotEmpty($generated);
        $this->actingAs($this->owner);
        $this->context($this->acme);

        Livewire::withQueryParams(['recurring' => (string) $schedule->id])->test(Index::class, ['type' => 'invoice'])
            ->assertSee('Monthly retainer')
            ->assertViewHas('documents', fn ($documents): bool => collect($documents->items())->pluck('id')->sort()->values()->all() === $generated->sort()->values()->all());
        $this->get(route('admin.sales.documents.show', $source))->assertSee(route('admin.sales.recurring.index', ['sheet' => 'create:'.$source->id]), false);
    }

    public function test_whatsapp_links_normalise_bangladeshi_numbers(): void
    {
        $this->assertSame('https://wa.me/8801711000000?text=Hi%20there%20%26%20more', WhatsApp::link('01711-000000', 'Hi there & more'));
        $this->assertSame('8801911222333', WhatsApp::normalize('+880 1911 222333'));
        $this->assertSame('8801911222333', WhatsApp::normalize('008801911222333'));
        $this->assertSame('441234567890', WhatsApp::normalize('+44 1234 567890'));
        $this->assertNull(WhatsApp::normalize('call me'));
        $this->assertSame('https://wa.me/?text=Hi', WhatsApp::link(null, 'Hi'));
    }
}
