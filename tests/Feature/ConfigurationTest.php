<?php

namespace Tests\Feature;

use App\Livewire\Admin\Crm\Calls\Form as CallForm;
use App\Livewire\Admin\Crm\Leads\Form as LeadForm;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Entries\Form as EntryForm;
use App\Livewire\Admin\Reports\IncomeStatement;
use App\Livewire\Admin\Sales\Documents\Form as DocumentForm;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Users\Index as UserIndex;
use App\Models\ApplicationSetting;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Party;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\Configuration;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

class ConfigurationTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00'));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->company = Company::factory()->create();
        $this->actingAs($this->owner);
        session([CompanyContext::SESSION_KEY => $this->company->id]);
    }

    private function set(array $values): void
    {
        app(Configuration::class)->save($values);
    }

    public function test_settings_have_defaults_and_only_changed_values_are_stored(): void
    {
        $this->assertSame(25, Configuration::get('general.rows_per_page'));
        $this->assertSame(7, Configuration::get('accounting.fiscal_year_start'));
        $this->assertNull(Configuration::get('sales.default_due_days'));

        Livewire::test(Settings::class)->set('tab', 'sales')->assertSee('Documents are due in (days)')
            ->set('values.sales.default_due_days', '15')->set('values.sales.default_vat', '7.5')->set('values.general.rows_per_page', '50')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.settings', ['tab' => 'sales']));

        $this->assertSame(15, Configuration::get('sales.default_due_days'));
        $this->assertSame('7.5', Configuration::get('sales.default_vat'));
        $this->assertSame(50, Configuration::get('general.rows_per_page'));
        $this->assertEqualsCanonicalizing(['sales.default_due_days', 'sales.default_vat', 'general.rows_per_page'], ApplicationSetting::query()->pluck('key')->all());

        // Back at the default, the row goes, so a later default change reaches this install.
        Livewire::test(Settings::class)->set('tab', 'sales')->call('restoreDefaults')->assertSet('values.sales.default_due_days', '')
            ->assertSet('values.general.rows_per_page', '50')->call('save')->assertHasNoErrors();
        $this->assertSame(['general.rows_per_page'], ApplicationSetting::query()->pluck('key')->all());
    }

    public function test_invalid_values_are_rejected_and_only_admins_can_save(): void
    {
        Livewire::test(Settings::class)->set('values.general.rows_per_page', '7')->set('values.app_name', '')
            ->set('values.sales.default_vat', '120')->set('values.accounting.default_due_days', '-3')->call('save')
            ->assertHasErrors(['values.general.rows_per_page', 'values.app_name', 'values.sales.default_vat', 'values.accounting.default_due_days']);
        $this->assertSame(0, ApplicationSetting::query()->count());

        $this->actingAs(User::factory()->create(['role' => 'accountant']))->get('/admin/settings')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'administrator', 'denied_permissions' => ['settings.update']]));
        Livewire::test(Settings::class)->assertDontSee('Save settings')->call('save')->assertForbidden();
    }

    public function test_modules_are_switched_in_settings_within_what_the_install_allows(): void
    {
        foreach (['general' => 'Rows per page', 'accounting' => 'Financial year starts in', 'sales' => 'Shared links last', 'crm' => 'Next call after (days)'] as $tab => $label) {
            $this->get(route('admin.settings', ['tab' => $tab]))->assertOk()->assertSee($label);
        }
        $this->get('/admin/settings')->assertOk()->assertSee('values.modules.crm', false);

        Livewire::test(Settings::class)->set('values.modules.crm', false)->call('save')->assertHasNoErrors();
        $this->get('/admin/crm')->assertNotFound();
        $this->get('/admin/settings')->assertOk()->assertDontSee(route('admin.crm.dashboard'))->assertDontSee('wire:click="$set(\'tab\', \'crm\')"', false);

        // A module the install doesn't include has no switch at all.
        config(['modules.sales' => false]);
        $this->get('/admin/settings')->assertOk()->assertDontSee('values.modules.sales', false);
    }

    public function test_accounting_settings_drive_reports_the_dashboard_and_entries(): void
    {
        $this->set(['accounting.fiscal_year_start' => 1, 'accounting.report_period' => 'this_month', 'accounting.dashboard_months' => 12, 'accounting.default_due_days' => 10]);

        $this->assertSame(['2026-01-01', '2026-12-31'], IncomeStatement::presetRange('this_fiscal_year', CarbonImmutable::parse('2026-03-10')));
        Livewire::test(IncomeStatement::class)->assertSet('period', 'this_month')->assertSet('from', '2026-09-01');
        Livewire::withQueryParams(['period' => 'all_time'])->test(IncomeStatement::class)->assertSet('period', 'all_time');
        Livewire::test(Dashboard::class)->assertSet('trendMonths', 12);
        Livewire::test(EntryForm::class, ['type' => 'income'])->assertSet('dueDate', '2026-10-09');
    }

    public function test_future_dated_transactions_can_be_blocked(): void
    {
        $transfer = fn (string $date) => $this->transfer($this->company, '1010', '1000', 1_000, $date);

        $this->assertNotNull($transfer('2026-10-05'));
        $this->set(['accounting.allow_future_dates' => false]);
        $this->assertNotNull($transfer('2026-09-29'));
        // A posted invoice takes its issue date, so the error lands on that field.
        $customer = Party::factory()->for($this->company)->create();
        Livewire::test(DocumentForm::class, ['type' => 'invoice'])->set('issueDate', '2026-10-05')->set('postToAccounts', true)
            ->set('partyId', (string) $customer->id)->set('lines.0.description', 'Work')->set('lines.0.price', '500')
            ->call('save', true)->assertHasErrors('issueDate');
        $this->expectException(ValidationException::class);
        $transfer('2026-10-05');
    }

    public function test_sales_settings_fill_new_documents(): void
    {
        $this->set(['sales.default_due_days' => 15, 'sales.default_vat' => '7.5', 'sales.tax_inclusive' => true, 'sales.post_to_accounts' => true]);

        $invoice = Livewire::test(DocumentForm::class, ['type' => 'invoice'])->assertSet('dueDate', '2026-10-14')
            ->assertSet('taxInclusive', true)->assertSet('postToAccounts', true);
        $this->assertSame('7.5', $invoice->get('lines')[0]['vat']);
        $bill = Livewire::test(DocumentForm::class, ['type' => 'bill']);
        $this->assertSame('0', $bill->get('lines')[0]['vat']);
        Livewire::test(DocumentForm::class, ['type' => 'delivery_note'])->assertSet('dueDate', '')->assertSet('postToAccounts', false);
    }

    public function test_recurring_invoices_run_at_the_hour_chosen_and_can_be_switched_off(): void
    {
        $job = fn (): Event => collect(app(Schedule::class)->events())->sole(fn (Event $event): bool => str_contains((string) $event->command, 'sales:generate-recurring'));

        $this->travelTo(CarbonImmutable::parse('2026-09-29 06:00'));
        $this->assertTrue($job()->filtersPass($this->app));
        $this->travelTo(CarbonImmutable::parse('2026-09-29 09:00'));
        $this->assertFalse($job()->filtersPass($this->app));

        $this->set(['sales.recurring_hour' => 9]);
        $this->assertTrue($job()->filtersPass($this->app));
        $this->set(['sales.recurring_enabled' => false]);
        $this->assertFalse($job()->filtersPass($this->app));
    }

    public function test_crm_settings_fill_new_leads_and_calls(): void
    {
        $manager = User::factory()->create(['role' => 'sales-manager']);
        $manager->companies()->attach($this->company->id);
        $this->actingAs($manager);
        Livewire::test(LeadForm::class)->assertSet('assignedTo', (string) $manager->id);
        $lead = Lead::factory()->for($this->company)->create(['assigned_to' => $manager->id]);
        Livewire::test(CallForm::class, ['lead' => $lead])->assertSet('nextCallOn', '');

        $this->set(['crm.assign_to_creator' => false, 'crm.follow_up_days' => 3]);
        Livewire::test(LeadForm::class)->assertSet('assignedTo', '');
        Livewire::test(CallForm::class, ['lead' => $lead])->assertSet('nextCallOn', '2026-10-02');
    }

    public function test_lists_and_export_follow_the_general_settings(): void
    {
        $this->set(['general.rows_per_page' => 10, 'general.export_format' => 'print', 'general.print_orientation' => 'landscape']);
        $this->assertSame(10, Livewire::test(UserIndex::class)->viewData('users')->perPage());
        $this->get('/admin/users')->assertOk()->assertSee('\\u0022format\\u0022:\\u0022print\\u0022', false)->assertSee('\\u0022orientation\\u0022:\\u0022landscape\\u0022', false);
    }
}
