<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Livewire\Admin\Dashboard;
use App\Models\Company;
use App\Models\Document;
use App\Models\Item;
use App\Models\Party;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\RecordDeletion;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Concerns\MakesDocuments;
use Tests\TestCase;

/** How posted sales documents show up in, and are protected within, the Accounting module. */
class SalesLedgerIntegrationTest extends TestCase
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
        $this->actingAs($this->owner);
        session([CompanyContext::SESSION_KEY => $this->company->id]);
    }

    public function test_dashboard_income_leaves_out_vat_and_nets_credit_notes(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 100_000, 1500]], ['post_to_accounts' => true]);
        $note = app(DocumentService::class)->convert($invoice, DocumentType::CreditNote, $this->owner);
        app(DocumentService::class)->save($note, $this->company, DocumentType::CreditNote, $this->dataOf($note, ['lines' => [
            ['description' => 'Discount', 'quantity' => 1000, 'unit_price' => 20_000, 'tax_rate' => 1500,
                'account_id' => (int) $this->company->accounts()->where('code', '4000')->value('id')]]]), $this->owner);
        app(DocumentService::class)->issue($note, $this->owner);

        Livewire::test(Dashboard::class)->assertViewHas('months', fn (array $months): bool => end($months)['income'] === 80_000);
    }

    public function test_transactions_link_a_document_entry_to_its_document_instead_of_editing_it(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 1_000]], ['post_to_accounts' => true]);

        $this->get(route('admin.entries.index'))->assertOk()
            ->assertSee(route('admin.sales.documents.show', $invoice))
            ->assertDontSee(route('admin.entries.edit', $invoice->entry));
    }

    public function test_parties_on_documents_cannot_be_deleted_and_company_deletion_removes_sales_data(): void
    {
        $invoice = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, 1_000]], ['post_to_accounts' => true]);
        $this->pay($invoice);
        $deliveryNote = app(DocumentService::class)->convert($invoice, DocumentType::DeliveryNote, $this->owner);
        Item::factory()->for($this->company)->create();

        $this->assertNotNull(app(RecordDeletion::class)->blockedReason($this->customer));
        try {
            app(RecordDeletion::class)->hardDelete($this->customer, $this->owner);
            $this->fail('A party on documents must not be deleted.');
        } catch (ValidationException) {
        }

        app(RecordDeletion::class)->hardDelete($this->company, $this->owner, 'ACME');

        $this->assertModelMissing($this->company);
        $this->assertSame(0, Document::query()->count());
        $this->assertModelMissing($deliveryNote);
        $this->assertSame(0, Item::query()->count());
    }

    private function pay(Document $document): void
    {
        app(DocumentService::class)->recordPayment($document, ['paid_on' => '2026-09-10', 'amount' => 500,
            'account_id' => (int) $this->company->accounts()->where('code', '1000')->value('id')], $this->owner);
    }
}
