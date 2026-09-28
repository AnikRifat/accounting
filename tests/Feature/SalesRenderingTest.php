<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\EntryType;
use App\Livewire\Admin\Sales\Templates\Form;
use App\Livewire\Admin\Sales\Templates\Index;
use App\Mail\DocumentMail;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentTemplate;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\DocumentMailer;
use App\Services\DocumentRenderer;
use App\Services\DocumentService;
use App\Support\CompanyContext;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Concerns\MakesDocuments;
use Tests\TestCase;

class SalesRenderingTest extends TestCase
{
    use MakesDocuments, RefreshDatabase;

    private User $owner;

    private Company $company;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->company = Company::factory()->create(['code' => 'ACME', 'name' => 'Acme Ltd']);
        $this->customer = Party::factory()->for($this->company)->create(['name' => 'রহিম ট্রেডার্স', 'address' => 'Dhanmondi, Dhaka', 'email' => 'rahim@example.com']);
    }

    private function userFor(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    private function invoice(array $extra = []): Document
    {
        return $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Design work', 2, 50_000, 1500], ['Hosting', 1, 20_000]], $extra);
    }

    private function fakePdf(): void
    {
        $this->partialMock(DocumentRenderer::class, fn ($mock) => $mock->shouldReceive('pdf', 'receiptPdf')->andReturn('%PDF-1.4 fake'));
    }

    private function template(Company $company, array $attributes = []): DocumentTemplate
    {
        return DocumentTemplate::query()->create(['company_id' => $company->id, 'name' => 'Letterhead', 'sections' => DocumentTemplate::defaultSections(), ...$attributes]);
    }

    public function test_the_print_page_shows_the_number_party_lines_and_totals(): void
    {
        $invoice = $this->invoice(['notes' => 'Thanks for the order.']);
        $this->actingAs($this->owner);

        $this->get(route('admin.sales.documents.print', $invoice))->assertOk()
            ->assertSeeTextInOrder([$invoice->number, 'রহিম ট্রেডার্স', 'Design work', 'Hosting', 'Subtotal', Money::format(135_000), 'Balance due', Money::format(135_000)])
            ->assertSee('Thanks for the order.')->assertSee('Download PDF')->assertSee('৳')->assertSee('<meta name="robots" content="noindex">', false);
        $this->get(route('admin.sales.documents.print', [$invoice, 'embed' => 1]))->assertOk()->assertSee($invoice->number)->assertDontSee('Download PDF');
    }

    public function test_a_delivery_note_prints_quantities_only(): void
    {
        $note = $this->issued($this->company, DocumentType::DeliveryNote, $this->customer, [['Cement bags', 40, 55_000]]);
        $this->actingAs($this->owner);

        $this->get(route('admin.sales.documents.print', $note))->assertOk()->assertSee('Cement bags')->assertSee('Deliver to')
            ->assertDontSee('Unit price')->assertDontSeeText(Money::format(55_000))->assertDontSee('Subtotal');
    }

    public function test_drafts_and_void_documents_carry_a_watermark_and_contracts_print_their_body(): void
    {
        $draft = $this->draft($this->company, DocumentType::Invoice, $this->customer, [['Design work', 1, 10_000]]);
        $contract = $this->issued($this->company, DocumentType::Contract, $this->customer, [], [
            'body' => "Between {company.name} and {party.name} <b>bold</b>.\n\nSigned for {document.number}.",
        ]);
        $this->actingAs($this->owner);

        $this->get(route('admin.sales.documents.print', $draft))->assertOk()->assertSee('DRAFT')->assertSee('Draft #'.$draft->id);
        $this->get(route('admin.sales.documents.print', $contract))->assertOk()
            ->assertSee('<p>Between Acme Ltd and রহিম ট্রেডার্স &lt;b&gt;bold&lt;/b&gt;.</p>', false)
            ->assertSee('<p>Signed for '.$contract->number.'.</p>', false)->assertDontSee('Subtotal');
    }

    public function test_the_pdf_is_a_real_pdf_named_after_the_number(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->owner);

        $response = $this->get(route('admin.sales.documents.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('inline; filename='.$invoice->number.'.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('draft-7.pdf', app(DocumentRenderer::class)->filename((new Document)->forceFill(['id' => 7])));
    }

    public function test_documents_are_limited_to_accessible_companies_and_sales_viewers(): void
    {
        $other = Company::factory()->create();
        $invoice = $this->invoice();
        $this->fakePdf();

        $this->actingAs($this->userFor('accountant', $other));
        $this->get(route('admin.sales.documents.print', $invoice))->assertNotFound();
        $this->get(route('admin.sales.documents.pdf', $invoice))->assertNotFound();

        $this->actingAs($this->userFor('sales', $this->company));
        $this->get(route('admin.sales.documents.print', $invoice))->assertForbidden();

        // Any accessible company prints, whatever the header shows.
        $this->actingAs($this->userFor('accountant', $this->company, $other))->withSession([CompanyContext::SESSION_KEY => $other->id]);
        $this->get(route('admin.sales.documents.print', $invoice))->assertOk();
        $this->get(route('admin.sales.documents.pdf', $invoice))->assertOk();
    }

    public function test_the_share_link_shows_the_document_logs_views_and_stops_when_expired_or_revoked(): void
    {
        $invoice = $this->invoice();
        $url = app(DocumentService::class)->share($invoice, 7, $this->owner);
        $this->fakePdf();

        $this->get($url)->assertOk()->assertSee($invoice->number)->assertSee('Download PDF')->assertDontSee('/admin/')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('<meta name="robots" content="noindex">', false);
        $this->assertStringContainsString('noindex', $this->get($url)->headers->get('X-Robots-Tag'));
        $this->assertSame(1, DocumentActivity::query()->where('document_id', $invoice->id)->where('event', 'viewed')->whereNull('user_id')->count());
        $this->get($url.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->travel(2)->hours();
        $this->get($url)->assertOk();
        $this->assertSame(2, DocumentActivity::query()->where('document_id', $invoice->id)->where('event', 'viewed')->count());

        $this->travel(8)->days();
        $this->get($url)->assertNotFound();
        $this->get($url.'/pdf')->assertNotFound();
        $this->travelBack();

        app(DocumentService::class)->revokeShare($invoice, $this->owner);
        $this->get($url)->assertNotFound();
        $this->get(route('documents.shared', str_repeat('a', 40)))->assertNotFound();
    }

    public function test_drafts_and_void_documents_are_never_shared_publicly(): void
    {
        $draft = $this->draft($this->company, DocumentType::Invoice, $this->customer, [['Design work', 1, 10_000]]);
        $draft->forceFill(['share_token' => str_repeat('d', 40)])->save();
        $void = $this->invoice();
        $url = app(DocumentService::class)->share($void, null, $this->owner);
        app(DocumentService::class)->void($void, 'Wrong customer', $this->owner);

        $this->get(route('documents.shared', str_repeat('d', 40)))->assertNotFound();
        $this->get($url)->assertNotFound();
    }

    public function test_receipts_for_a_document_payment_and_a_ledger_receipt(): void
    {
        $this->actingAs($this->owner);
        $cash = (int) $this->company->accounts()->where('code', '1000')->value('id');
        $service = app(DocumentService::class);

        $unposted = $this->invoice();
        $service->recordPayment($unposted, ['paid_on' => '2026-09-05', 'amount' => 35_000, 'account_id' => $cash, 'reference' => 'BK-77'], $this->owner);
        $payment = $unposted->payments()->sole();
        $this->get(route('admin.sales.documents.receipt', [$unposted, 'payment', $payment->id]))->assertOk()
            ->assertSee('Payment receipt')->assertSee('রহিম ট্রেডার্স')->assertSeeText(Money::format(35_000))->assertSee('BK-77')
            ->assertSee($unposted->number.'/1')->assertSeeTextInOrder(['Balance after this payment', Money::format(100_000)]);

        $posted = $this->invoice(['post_to_accounts' => true]);
        $service->recordPayment($posted, ['paid_on' => '2026-09-06', 'amount' => 135_000, 'account_id' => $cash], $this->owner);
        $receipt = JournalEntry::query()->where('bill_id', $posted->journal_entry_id)->where('type', EntryType::Receipt)->sole();
        $this->get(route('admin.sales.documents.receipt', [$posted, 'entry', $receipt->id]))->assertOk()
            ->assertSee($receipt->number)->assertSeeText(Money::format(135_000))->assertSeeTextInOrder(['Balance after this payment', Money::format(0)]);

        // An entry of another bill, the bill's own entry, or a payment of another document are not receipts of this one.
        $this->get(route('admin.sales.documents.receipt', [$unposted, 'entry', $receipt->id]))->assertNotFound();
        $this->get(route('admin.sales.documents.receipt', [$posted, 'entry', $posted->journal_entry_id]))->assertNotFound();
        $this->get(route('admin.sales.documents.receipt', [$posted, 'payment', $payment->id]))->assertNotFound();
        $this->get('/admin/sales/documents/'.$posted->id.'/receipts/other/'.$receipt->id)->assertNotFound();

        $this->fakePdf();
        $this->get(route('admin.sales.documents.receipt', [$posted, 'entry', $receipt->id, 'pdf' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_mailer_sends_the_pdf_and_logs_the_recipient(): void
    {
        Mail::fake();
        $this->fakePdf();
        $invoice = $this->invoice();
        $mailer = app(DocumentMailer::class);

        $mailer->send($invoice, 'buyer@example.com', 'a@example.com; b@example.com', 'Invoice '.$invoice->number, "Dear Rahim,\nplease find it attached.", $this->owner);

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('buyer@example.com') && $mail->hasCc('b@example.com')
            && $mail->filename === $invoice->number.'.pdf' && count($mail->attachments()) === 1 && $mail->shareUrl === null
            && str_contains($mail->render(), 'please find it attached.'));
        $this->assertSame('buyer@example.com (cc: a@example.com, b@example.com)', $invoice->activities()->where('event', 'emailed')->sole()->details);

        $url = app(DocumentService::class)->share($invoice, null, $this->owner);
        $mailer->send($invoice, 'buyer@example.com', null, 'Again', '', $this->owner);
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->shareUrl === $url);
    }

    public function test_the_mailer_refuses_drafts_bad_addresses_and_users_without_send(): void
    {
        Mail::fake();
        $this->fakePdf();
        $mailer = app(DocumentMailer::class);
        $draft = $this->draft($this->company, DocumentType::Invoice, $this->customer, [['Design work', 1, 10_000]]);
        $invoice = $this->invoice();

        foreach ([[$draft, 'x@example.com', null, 'document'], [$invoice, 'not-an-email', null, 'to'], [$invoice, 'x@example.com', 'ok@example.com, nope', 'cc']] as [$document, $to, $cc, $key]) {
            try {
                $mailer->send($document, $to, $cc, 'Subject', 'Body', $this->owner);
                $this->fail("Expected a validation error on {$key}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
            }
        }
        try {
            $mailer->send($invoice, 'x@example.com', null, 'Subject', 'Body', $this->userFor('data-entry', $this->company));
            $this->fail('A user without sales.send must not email documents.');
        } catch (AuthorizationException) {
        }
        try {
            $mailer->send($invoice, 'x@example.com', null, 'Subject', 'Body', $this->userFor('accountant', Company::factory()->create()));
            $this->fail('A user of another company must not email its documents.');
        } catch (AuthorizationException) {
        }
        Mail::assertNothingSent();
        $this->assertFalse($invoice->activities()->where('event', 'emailed')->exists());
    }

    public function test_the_builder_saves_order_visibility_and_text_blocks_and_the_document_follows_them(): void
    {
        Storage::fake(config('media.disk'));
        $this->actingAs($this->owner);
        session([CompanyContext::SESSION_KEY => $this->company->id]);

        $form = Livewire::test(Form::class)->assertSet('isDefault', true);
        $sections = collect($form->get('sections'));
        $totals = $sections->firstWhere('key', 'totals')['id'];
        $notes = $sections->search(fn (array $section): bool => $section['key'] === 'notes');
        $form->set('name', 'Letterhead')->set('layout', 'modern')->set('accentColor', '#1D4ED8')->set('font', 'serif')->set('vatNumber', '000123456-0101')
            ->set('bankDetails', 'City Bank, A/C 123')->set('logo', UploadedFile::fake()->image('logo.png', 300, 100))
            ->call('sortSection', $totals, 0)->set("sections.{$notes}.visible", false)->call('addTextBlock');
        $text = collect($form->get('sections'))->search(fn (array $section): bool => $section['key'] === 'text');
        $form->set("sections.{$text}.text", 'Goods once sold are not returned.')->call('save')->assertHasNoErrors();

        $template = DocumentTemplate::query()->where('company_id', $this->company->id)->sole();
        $form->assertRedirect(route('admin.sales.templates.edit', $template));
        $this->assertSame(['modern', '#1d4ed8', 'serif', true], [$template->layout, $template->accent_color, $template->font, $template->is_default]);
        $this->assertSame('totals', $template->sections[0]['key']);
        $this->assertFalse(collect($template->sections)->firstWhere('key', 'notes')['visible']);
        $this->assertSame('Goods once sold are not returned.', collect($template->sections)->firstWhere('key', 'text')['text']);
        $this->assertCount(1, $template->getMedia(DocumentTemplate::LOGO));

        $invoice = $this->invoice(['template_id' => $template->id, 'notes' => 'Hidden note text']);
        $this->get(route('admin.sales.documents.print', $invoice))->assertOk()
            ->assertSeeInOrder(['Subtotal', 'Design work', 'Goods once sold are not returned.'])->assertDontSee('Hidden note text')
            ->assertSee('BIN/VAT: 000123456-0101')->assertSee('City Bank, A/C 123')->assertSee('#1d4ed8')->assertSee('/media/', false);

        $this->withSession([CompanyContext::SESSION_KEY => $this->company->id])->get(route('admin.sales.templates.create'))->assertOk()->assertSee('Save to see a preview');
        $this->get(route('admin.sales.templates.edit', $template))->assertOk()->assertSee('Letterhead')->assertSee(route('admin.sales.templates.preview', $template), false);
        $this->get(route('admin.sales.templates.index'))->assertOk()->assertSee('Letterhead')->assertSee('Company default');
        $this->get(route('admin.sales.templates.preview', [$template, 'type' => 'quotation']))->assertOk()->assertSee('Rahim Traders')->assertSee('Quotation');
        $this->assertSame(1, Document::query()->count());
    }

    public function test_one_default_template_per_company_and_validation(): void
    {
        $this->actingAs($this->owner);
        session([CompanyContext::SESSION_KEY => $this->company->id]);
        $first = $this->template($this->company);
        $first->forceFill(['is_default' => true])->save();

        Livewire::test(Form::class)->set('name', 'Letterhead')->set('accentColor', 'red')->call('save')->assertHasErrors(['name', 'accentColor']);
        Livewire::test(Form::class)->assertSet('isDefault', false)->set('name', 'Minimal')->set('isDefault', true)->call('save')->assertHasNoErrors();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue(DocumentTemplate::query()->where('name', 'Minimal')->sole()->is_default);
        Livewire::test(Form::class, ['template' => $first])->set('sections.0.key', 'bogus')->call('save')->assertHasErrors('sections.0.key');
    }

    public function test_templates_of_another_company_are_out_of_reach(): void
    {
        $other = Company::factory()->create();
        $foreign = $this->template($other, ['name' => 'Foreign']);
        $own = $this->template($this->company, ['name' => 'Own']);
        RolePermission::factory()->create(['role' => 'designer', 'permissions' => ['admin.access', 'companies.view', 'sales.view', 'sales.setup']]);
        $this->actingAs($this->userFor('designer', $this->company));

        $this->get(route('admin.sales.templates.edit', $foreign))->assertNotFound();
        $this->get(route('admin.sales.templates.preview', $foreign))->assertNotFound();
        $this->get(route('admin.sales.templates.index'))->assertOk()->assertSee('Own')->assertDontSee('Foreign');
        Livewire::test(Form::class, ['template' => $foreign])->assertStatus(404);
        try {
            Livewire::test(Index::class)->call('delete', $foreign->id);
            $this->fail('Another company\'s template must not be deleted.');
        } catch (ModelNotFoundException) {
        }
        Livewire::test(Index::class)->call('delete', $own->id);
        $this->assertModelMissing($own);
        $this->assertModelExists($foreign);

        $this->actingAs($this->userFor('accountant', $this->company));
        $this->get(route('admin.sales.templates.index'))->assertForbidden();
    }
}
