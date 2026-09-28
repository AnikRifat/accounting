<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Livewire\Admin\Sales\Items\Form as ItemForm;
use App\Livewire\Admin\Sales\Items\Index as ItemIndex;
use App\Livewire\Admin\Sales\Settings;
use App\Models\Company;
use App\Models\DocumentField;
use App\Models\DocumentSequence;
use App\Models\DocumentTemplate;
use App\Models\Item;
use App\Models\Party;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Concerns\MakesDocuments;
use Tests\TestCase;

class SalesSetupTest extends TestCase
{
    use MakesDocuments, RefreshDatabase;

    private User $owner;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->company = Company::factory()->create(['code' => 'ACME']);
        $this->other = Company::factory()->create(['code' => 'BETA']);
        RolePermission::factory()->create(['role' => 'sales_admin', 'permissions' => [...config('permissions.roles.accountant'), 'sales.setup']]);
    }

    private function manager(Company ...$companies): User
    {
        $user = User::factory()->create(['role' => 'sales_admin']);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    private function accountId(Company $company, string $code): int
    {
        return (int) $company->accounts()->where('code', $code)->value('id');
    }

    public function test_items_store_taka_prices_as_paisa_and_vat_as_basis_points(): void
    {
        $this->actingAs($this->manager($this->company));

        Livewire::test(ItemForm::class)->set('name', ' Web design ')->set('price', '1,25,000.50')->set('taxRate', '7.5')->set('unit', 'hour')
            ->set('accountId', (string) $this->accountId($this->company, '4000'))->call('save')
            ->assertHasNoErrors()->assertRedirect(route('admin.sales.items.index'));
        $item = Item::query()->sole();
        $this->assertSame([$this->company->id, 'Web design', 12_500_050, 750, 'hour', $this->accountId($this->company, '4000'), true],
            [$item->company_id, $item->name, $item->price, $item->tax_rate, $item->unit, $item->account_id, $item->is_active]);

        Livewire::test(ItemForm::class)->set('name', 'Bad')->set('price', '12.345')->set('taxRate', '101')
            ->set('accountId', (string) $this->accountId($this->company, '5000'))->call('save')->assertHasErrors(['price', 'taxRate', 'accountId']);
        Livewire::test(ItemForm::class)->set('name', 'Bad')->set('price', '10')->set('taxRate', '15')
            ->set('accountId', (string) $this->accountId($this->other, '4000'))->call('save')->assertHasErrors('accountId');

        Livewire::test(ItemForm::class, ['item' => $item])->assertSet('price', '125000.50')->assertSet('taxRate', '7.5')
            ->set('price', '99')->set('taxRate', '15')->set('isActive', false)->call('save')->assertHasNoErrors();
        $this->assertSame([9_900, 1500, false], [$item->fresh()->price, $item->fresh()->tax_rate, $item->fresh()->is_active]);

        Livewire::test(ItemIndex::class)->assertSee('Web design')->set('status', 'active')->assertDontSee('Web design')
            ->set('status', 'inactive')->assertSee('Web design')->call('delete', $item->id);
        $this->assertModelMissing($item);
    }

    public function test_item_names_are_unique_within_a_company_only(): void
    {
        Item::factory()->for($this->company)->create(['name' => 'Hosting']);
        $this->actingAs($this->manager($this->company, $this->other));
        session([CompanyContext::SESSION_KEY => $this->company->id]);

        Livewire::test(ItemForm::class)->set('name', 'Hosting')->set('price', '100')->call('save')->assertHasErrors(['name' => 'unique']);

        session([CompanyContext::SESSION_KEY => $this->other->id]);
        Livewire::test(ItemForm::class)->set('name', 'Hosting')->set('price', '100')->call('save')->assertHasNoErrors();
        $this->assertSame(1, Item::query()->where('company_id', $this->other->id)->count());
    }

    public function test_items_of_other_companies_are_out_of_reach(): void
    {
        $foreign = Item::factory()->for($this->other)->create(['name' => 'Foreign widget']);
        Item::factory()->for($this->company)->create(['name' => 'Own widget']);
        $this->actingAs($this->manager($this->company));

        $this->get(route('admin.sales.items.index'))->assertOk()->assertSee('Own widget')->assertDontSee('Foreign widget');
        $this->get(route('admin.sales.items.edit', $foreign))->assertNotFound();
        try {
            Livewire::test(ItemIndex::class)->call('delete', $foreign->id);
            $this->fail('Another company\'s item must not be deleted.');
        } catch (ModelNotFoundException) {
        }
        $this->assertModelExists($foreign);

        $dataEntry = User::factory()->create(['role' => 'data-entry']);
        $dataEntry->companies()->attach($this->company->id);
        $this->actingAs($dataEntry);
        $this->get(route('admin.sales.items.index'))->assertOk()->assertSee('Own widget')->assertDontSee(route('admin.sales.items.create'));
        $this->get(route('admin.sales.items.create'))->assertForbidden();
    }

    public function test_the_header_company_is_rechecked_when_an_item_or_setting_is_saved(): void
    {
        $this->actingAs($this->manager($this->company, $this->other));
        session([CompanyContext::SESSION_KEY => $this->company->id]);
        $item = Livewire::test(ItemForm::class)->set('name', 'Switch')->set('price', '1');
        $settings = Livewire::test(Settings::class);

        session([CompanyContext::SESSION_KEY => $this->other->id]);
        $item->call('save')->assertHasErrors('company');
        $settings->call('saveNumbering')->assertHasErrors('company');
        $this->assertSame(0, Item::query()->count());
        $this->assertSame(0, DocumentSequence::query()->count());

        session([CompanyContext::SESSION_KEY => null]);
        Livewire::test(Settings::class)->assertSee('Choose a company first')->assertSet('companyId', null);
        session([CompanyContext::SESSION_KEY => 999_999]);
        Livewire::test(Settings::class)->assertSet('companyId', null);
    }

    public function test_a_prefix_change_numbers_the_next_document_without_touching_issued_ones(): void
    {
        $customer = Party::factory()->for($this->company)->create();
        $first = $this->issued($this->company, DocumentType::Invoice, $customer, [['Work', 1, 1_000]]);
        $this->assertSame('INV-00001', $first->number);
        $this->actingAs($this->manager($this->company));

        Livewire::test(Settings::class)->assertSee('INV-00002')->set('rows.invoice.prefix', 'S/26-')->set('rows.invoice.padding', '3')
            ->assertSee('S/26-001')->call('saveNumbering')->assertHasNoErrors();

        $second = $this->issued($this->company, DocumentType::Invoice, $customer, [['Work', 1, 1_000]]);
        $this->assertSame(['INV-00001', 'S/26-001'], [$first->fresh()->number, $second->number]);

        Livewire::test(Settings::class)->set('rows.invoice.prefix', 'INV-')->set('rows.invoice.padding', '5')->assertSee('INV-00002')->call('saveNumbering');
        $this->assertSame('INV-00002', $this->issued($this->company, DocumentType::Invoice, $customer, [['Work', 1, 1_000]])->number);

        Livewire::test(Settings::class)->set('rows.invoice.prefix', 'IN V')->set('rows.quotation.padding', '9')->call('saveNumbering')
            ->assertHasErrors(['rows.invoice.prefix', 'rows.quotation.padding']);
        $this->assertSame('INV-', DocumentSequence::for($this->company->id, DocumentType::Invoice)->prefix);
    }

    public function test_default_templates_and_texts_are_per_company(): void
    {
        $own = DocumentTemplate::create(['company_id' => $this->company->id, 'name' => 'Blue', 'sections' => DocumentTemplate::defaultSections()]);
        $foreign = DocumentTemplate::create(['company_id' => $this->other->id, 'name' => 'Red', 'sections' => DocumentTemplate::defaultSections()]);
        $this->actingAs($this->manager($this->company));

        Livewire::test(Settings::class)->set('rows.quotation.templateId', (string) $foreign->id)->call('saveNumbering')->assertHasErrors('rows.quotation.templateId');
        Livewire::test(Settings::class)->set('rows.quotation.templateId', (string) $own->id)->set('rows.quotation.terms', 'Valid 30 days')
            ->call('saveNumbering')->assertHasNoErrors();

        $sequence = DocumentSequence::for($this->company->id, DocumentType::Quotation);
        $this->assertSame([$own->id, 'Valid 30 days', 'QUO-'], [$sequence->template_id, $sequence->default_terms, $sequence->prefix]);
    }

    public function test_custom_fields_are_managed_in_order_and_required_ones_are_enforced_on_documents(): void
    {
        $customer = Party::factory()->for($this->company)->create();
        $this->actingAs($this->manager($this->company));

        $settings = Livewire::test(Settings::class)
            ->set('field.label', 'PO number')->set('field.documentType', 'invoice')->set('field.isRequired', true)->call('saveField')->assertHasNoErrors()
            ->set('field.label', 'Delivery date')->set('field.kind', 'date')->call('saveField')->assertHasNoErrors()
            ->set('field.label', str_repeat('x', 61))->set('field.kind', 'colour')->call('saveField')->assertHasErrors(['field.label', 'field.kind']);
        [$po, $delivery] = DocumentField::query()->orderBy('sort')->get()->all();
        $this->assertSame(['PO number', DocumentType::Invoice, true, 'Delivery date', null, 'date'], [$po->label, $po->document_type, $po->is_required, $delivery->label, $delivery->document_type, $delivery->kind]);

        $settings->call('moveField', $delivery->id, -1);
        $this->assertSame(['Delivery date', 'PO number'], DocumentField::query()->orderBy('sort')->pluck('label')->all());

        try {
            $this->draft($this->company, DocumentType::Invoice, $customer, [['Work', 1, 1_000]]);
            $this->fail('A required custom field must be filled in.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('custom_values.'.$po->id, $exception->errors());
        }
        $invoice = $this->draft($this->company, DocumentType::Invoice, $customer, [['Work', 1, 1_000]], ['custom_values' => [$po->id => 'PO-77']]);
        $this->assertSame([(string) $po->id => 'PO-77'], $invoice->custom_values);
        $this->draft($this->company, DocumentType::Quotation, $customer, [['Offer', 1, 1_000]]);

        $settings->call('editField', $po->id)->set('field.isRequired', false)->call('saveField')->assertHasNoErrors();
        $this->assertFalse($po->fresh()->is_required);
        $settings->call('deleteField', $delivery->id);
        $this->assertModelMissing($delivery);

        $foreignField = DocumentField::create(['company_id' => $this->other->id, 'label' => 'Theirs', 'kind' => 'text']);
        $this->expectException(ModelNotFoundException::class);
        $settings->call('deleteField', $foreignField->id);
    }

    public function test_settings_need_sales_setup(): void
    {
        $user = User::factory()->create(['role' => 'accountant']);
        $user->companies()->attach($this->company->id);
        $this->actingAs($user);

        $this->get(route('admin.sales.settings'))->assertForbidden();
        $this->get(route('admin.sales.items.index'))->assertOk();
    }
}
