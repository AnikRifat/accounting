<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use App\Services\DocumentService;
use App\Support\Modules;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesDocuments;
use Tests\TestCase;

class ModuleSwitchTest extends TestCase
{
    use MakesDocuments, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    public function test_every_module_is_on_by_default(): void
    {
        $this->assertSame([Modules::ACCOUNTING, Modules::SALES, Modules::CRM, Modules::ORGANISATION], array_keys(Modules::available($this->owner)));
    }

    public function test_a_disabled_module_leaves_the_header_and_its_pages_are_not_found(): void
    {
        config(['modules.crm' => false]);
        $this->actingAs($this->owner);

        $this->get('/admin')->assertOk()->assertDontSee(route('admin.crm.dashboard'))->assertSee(route('admin.sales.dashboard'));
        $this->get('/admin/crm')->assertNotFound();
        $this->get('/admin/crm/leads')->assertNotFound();
        $this->get('/admin/crm/reports/pipeline')->assertNotFound();
        $this->get('/admin/sales')->assertOk();
        // A shared page last visited from the disabled module falls back to one the user can open.
        $this->withSession([Modules::SESSION_KEY => Modules::CRM])->get('/admin/profile')->assertOk()->assertSee(route('admin.entries.index'));
    }

    public function test_sales_runs_only_with_accounting_and_the_home_moves_to_the_first_open_module(): void
    {
        config(['modules.accounting' => false]);
        $this->actingAs($this->owner);

        $this->assertFalse(Modules::enabled(Modules::SALES));
        $this->get('/admin')->assertRedirect(route('admin.crm.dashboard'));
        $this->get('/admin/entries')->assertNotFound();
        $this->get('/admin/parties')->assertNotFound();
        $this->get('/admin/sales')->assertNotFound();
        $this->get('/admin/crm')->assertOk()->assertDontSee('aria-label="Accounting"', false)->assertDontSee(route('admin.sales.dashboard'));
        $this->get('/admin/companies')->assertOk();
    }

    public function test_organisation_stays_when_every_other_module_is_off(): void
    {
        config(['modules.accounting' => false, 'modules.sales' => false, 'modules.crm' => false]);

        $this->actingAs($this->owner)->get('/admin')->assertRedirect(route('admin.companies.index'));
        $this->get('/admin/companies')->assertOk()->assertSee(route('admin.users.index'));
    }

    public function test_a_disabled_accounting_home_is_not_found_for_users_with_no_other_module(): void
    {
        config(['modules.accounting' => false]);
        $user = User::factory()->create(['role' => 'data-entry', 'denied_permissions' => ['companies.view']]);

        $this->assertSame([], Modules::available($user));
        $this->actingAs($user)->get('/admin')->assertNotFound();
        $this->get('/admin/profile')->assertOk()->assertDontSee(route('admin.entries.index'));
    }

    public function test_disabling_sales_closes_public_links_and_stops_recurring_invoices(): void
    {
        $company = Company::factory()->create();
        $customer = Party::factory()->for($company)->create();
        $invoice = $this->issued($company, DocumentType::Invoice, $customer, [['Design work', 1, 50_000]]);
        $url = app(DocumentService::class)->share($invoice, null, $this->owner);
        $this->get($url)->assertOk();
        $this->assertTrue($this->recurringInvoiceJob()->filtersPass($this->app));

        config(['modules.sales' => false]);

        $this->get($url)->assertNotFound();
        $this->get($url.'/pdf')->assertNotFound();
        $this->assertFalse($this->recurringInvoiceJob()->filtersPass($this->app));
        $this->actingAs($this->owner)->get('/admin/entries')->assertOk()->assertDontSee(route('admin.sales.documents.show', $invoice));
    }

    private function recurringInvoiceJob(): Event
    {
        return collect(app(Schedule::class)->events())->sole(fn (Event $event): bool => str_contains((string) $event->command, 'sales:generate-recurring'));
    }
}
