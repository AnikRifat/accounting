<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\RecurringFrequency;
use App\Livewire\Admin\Sales\Recurring\Form;
use App\Livewire\Admin\Sales\Recurring\Index;
use App\Models\Company;
use App\Models\Document;
use App\Models\Party;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\RecurringInvoices;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Concerns\MakesDocuments;
use Tests\TestCase;

class RecurringInvoicesTest extends TestCase
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
        $this->customer = Party::factory()->for($this->company)->create(['name' => 'Rahman Traders']);
    }

    private function service(): RecurringInvoices
    {
        return app(RecurringInvoices::class);
    }

    /** A draft invoice dated 1 Sep, due 30 Sep, with two lines and a 10% discount. */
    private function sourceInvoice(?Company $company = null, ?Party $party = null): Document
    {
        return $this->draft($company ?? $this->company, DocumentType::Invoice, $party ?? $this->customer,
            [['Retainer', 1, 100_000, 1500], ['Hosting', 2, 5_000]], ['discount_type' => 'percent', 'discount_value' => 1000, 'notes' => 'Thanks', 'terms' => 'Net 29']);
    }

    /** @param array<string, mixed> $overrides */
    private function schedule(Document $source, array $overrides = [], ?User $actor = null): RecurringInvoice
    {
        return $this->service()->save(null, $source->company, $overrides + ['source_id' => $source->id, 'name' => 'Retainer', 'frequency' => 'monthly',
            'day' => 1, 'starts_on' => '2026-07-01', 'ends_on' => null, 'is_active' => true], $actor ?? $this->owner);
    }

    private function userFor(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    public function test_the_first_run_is_the_schedule_day_on_or_after_the_start_date(): void
    {
        $first = fn (RecurringFrequency $frequency, int $day, string $start): string => RecurringInvoices::firstRun($frequency, $day, CarbonImmutable::parse($start))->toDateString();

        $this->assertSame('2026-01-31', $first(RecurringFrequency::Monthly, 31, '2026-01-15'));
        $this->assertSame('2026-02-05', $first(RecurringFrequency::Monthly, 5, '2026-01-15'));
        $this->assertSame('2026-02-28', $first(RecurringFrequency::Quarterly, 31, '2026-02-10'));
        $this->assertSame('2026-01-15', $first(RecurringFrequency::Monthly, 15, '2026-01-15'));
        $this->assertSame('2026-01-15', $first(RecurringFrequency::Weekly, 1, '2026-01-15'));
        $this->assertSame('2026-01-29', RecurringInvoices::firstRun(RecurringFrequency::Weekly, 1, CarbonImmutable::parse('2026-01-15'), CarbonImmutable::parse('2026-01-23'))->toDateString());

        $schedule = $this->schedule($this->sourceInvoice(), ['day' => 10, 'starts_on' => '2026-07-15']);
        $this->assertSame('2026-08-10', $schedule->next_run_on->toDateString());
        $this->assertSame($this->owner->id, $schedule->created_by);
    }

    public function test_a_run_creates_one_draft_per_due_period_copying_the_invoice(): void
    {
        $source = $this->sourceInvoice();
        $schedule = $this->schedule($source);

        $summary = $this->service()->run(CarbonImmutable::parse('2026-09-15'));

        $this->assertSame(3, $summary['created']);
        $this->assertSame([], $summary['skipped']);
        $drafts = Document::query()->where('recurring_invoice_id', $schedule->id)->orderBy('recurring_period')->get();
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01'], $drafts->map(fn (Document $draft): string => $draft->recurring_period->toDateString())->all());
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01'], $drafts->map(fn (Document $draft): string => $draft->issue_date->toDateString())->all());
        $this->assertSame(['2026-07-30', '2026-08-30', '2026-09-30'], $drafts->map(fn (Document $draft): string => $draft->due_date->toDateString())->all());
        foreach ($drafts as $draft) {
            $this->assertSame(DocumentStatus::Draft, $draft->status);
            $this->assertNull($draft->number);
            $this->assertSame([$source->party_id, $source->subtotal, $source->discount_total, $source->tax_total, $source->total, 'Thanks', 'Net 29', null],
                [$draft->party_id, $draft->subtotal, $draft->discount_total, $draft->tax_total, $draft->total, $draft->notes, $draft->terms, $draft->source_id]);
            $this->assertSame($source->lines->map->only(['description', 'quantity', 'unit_price', 'tax_rate', 'account_id', 'net', 'tax'])->all(),
                $draft->lines->map->only(['description', 'quantity', 'unit_price', 'tax_rate', 'account_id', 'net', 'tax'])->all());
        }
        $schedule->refresh();
        $this->assertSame(['2026-10-01', '2026-09-01'], [$schedule->next_run_on->toDateString(), $schedule->last_run_on->toDateString()]);
        $this->assertTrue($schedule->is_active);

        $again = $this->service()->run(CarbonImmutable::parse('2026-09-15'));
        $this->assertSame(0, $again['created']);
        $this->assertSame(3, Document::query()->where('recurring_invoice_id', $schedule->id)->count());
    }

    public function test_a_run_skips_companies_that_switched_sales_off(): void
    {
        $schedule = $this->schedule($this->sourceInvoice());
        $this->company->update(['sales_enabled' => false]);

        $this->assertSame(0, $this->service()->run(CarbonImmutable::parse('2026-09-15'))['created']);
        $this->assertSame('2026-07-01', $schedule->fresh()->next_run_on->toDateString());
    }

    public function test_a_period_generated_before_is_never_generated_again(): void
    {
        $schedule = $this->schedule($this->sourceInvoice());
        $this->service()->run(CarbonImmutable::parse('2026-07-01'));
        // Rewind the schedule as if a second process read it before the first advanced it.
        $schedule->forceFill(['next_run_on' => '2026-07-01'])->save();

        $summary = $this->service()->run(CarbonImmutable::parse('2026-07-01'));

        $this->assertSame(0, $summary['created']);
        $this->assertSame(1, Document::query()->where('recurring_invoice_id', $schedule->id)->count());
        $this->assertSame('2026-08-01', $schedule->fresh()->next_run_on->toDateString());
    }

    public function test_the_end_date_stops_the_schedule(): void
    {
        $schedule = $this->schedule($this->sourceInvoice(), ['ends_on' => '2026-08-15']);

        $summary = $this->service()->run(CarbonImmutable::parse('2026-12-01'));

        $this->assertSame(2, $summary['created']);
        $this->assertFalse($schedule->fresh()->is_active);
        $this->assertSame(0, $this->service()->run(CarbonImmutable::parse('2027-01-01'))['created']);
    }

    public function test_month_end_days_are_clamped_to_short_months(): void
    {
        $schedule = $this->schedule($this->sourceInvoice(), ['day' => 31, 'starts_on' => '2026-01-31']);

        $this->service()->run(CarbonImmutable::parse('2026-03-31'));

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], Document::query()->where('recurring_invoice_id', $schedule->id)
            ->orderBy('recurring_period')->get()->map(fn (Document $draft): string => $draft->issue_date->toDateString())->all());
        $this->assertSame('2026-04-30', $schedule->fresh()->next_run_on->toDateString());
    }

    public function test_a_run_catches_up_at_most_twelve_periods_per_schedule(): void
    {
        $schedule = $this->schedule($this->sourceInvoice(), ['frequency' => 'weekly', 'starts_on' => '2026-01-01']);

        $this->assertSame(12, $this->service()->run(CarbonImmutable::parse('2026-09-01'))['created']);
        $this->assertSame('2026-03-26', $schedule->fresh()->next_run_on->toDateString());
    }

    public function test_schedules_of_an_inactive_creator_or_company_are_skipped_and_stay_due(): void
    {
        $accountant = $this->userFor('accountant', $this->company);
        $schedule = $this->schedule($this->sourceInvoice(), [], $accountant);
        $accountant->forceFill(['is_active' => false])->save();

        $summary = $this->service()->run(CarbonImmutable::parse('2026-07-05'));

        $this->assertSame(0, $summary['created']);
        $this->assertSame([['schedule' => 'Retainer', 'reason' => 'Its creator is inactive.']], $summary['skipped']);
        $this->assertSame('2026-07-01', $schedule->fresh()->next_run_on->toDateString());

        $accountant->forceFill(['is_active' => true])->save();
        $accountant->companies()->detach();
        $this->assertSame('Its creator can no longer create invoices in this company.', $this->service()->run(CarbonImmutable::parse('2026-07-05'))['skipped'][0]['reason']);

        $accountant->companies()->attach($this->company->id);
        $this->company->forceFill(['is_active' => false])->save();
        $this->assertSame('The company is inactive.', $this->service()->run(CarbonImmutable::parse('2026-07-05'))['skipped'][0]['reason']);
        $this->assertSame(0, Document::query()->whereNotNull('recurring_invoice_id')->count());
    }

    public function test_the_command_generates_due_drafts(): void
    {
        $this->schedule($this->sourceInvoice(), ['starts_on' => '2026-09-01']);
        $this->travelTo(CarbonImmutable::parse('2026-09-01 06:00'));

        $this->artisan('sales:generate-recurring')->expectsOutput('Created 1 draft invoice.')->assertSuccessful();
        $this->artisan('sales:generate-recurring')->expectsOutput('No draft invoices were due.')->assertSuccessful();
        $this->assertSame(1, Document::query()->whereNotNull('recurring_invoice_id')->count());
    }

    public function test_the_source_must_be_a_live_invoice_of_the_same_company_and_editors_need_sales_update(): void
    {
        $other = Company::factory()->create();
        $foreign = $this->sourceInvoice($other, Party::factory()->for($other)->create());
        $quotation = $this->draft($this->company, DocumentType::Quotation, $this->customer, [['Offer', 1, 1_000]]);
        $void = app(DocumentService::class)->issue($this->sourceInvoice(), $this->owner);
        app(DocumentService::class)->void($void, 'Wrong', $this->owner);

        foreach ([$foreign, $quotation, $void] as $source) {
            try {
                $this->service()->save(null, $this->company, ['source_id' => $source->id, 'name' => 'X', 'frequency' => 'monthly', 'day' => 1,
                    'starts_on' => '2026-07-01', 'is_active' => true], $this->owner);
                $this->fail('The source must be a draft or issued invoice of the company.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('source_id', $exception->errors());
            }
        }

        $this->expectException(AuthorizationException::class);
        $this->schedule($this->sourceInvoice(), [], $this->userFor('data-entry', $this->company));
    }

    public function test_changing_the_timing_recomputes_the_next_run_from_today(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00'));
        $schedule = $this->schedule($this->sourceInvoice());
        $this->assertSame('2026-07-01', $schedule->next_run_on->toDateString());

        $renamed = $this->service()->save($schedule, $this->company, ['source_id' => $schedule->source_id, 'name' => 'Renamed', 'frequency' => 'monthly',
            'day' => 1, 'starts_on' => '2026-07-01', 'is_active' => true], $this->owner);
        $this->assertSame('2026-07-01', $renamed->next_run_on->toDateString());

        $moved = $this->service()->save($schedule->fresh(), $this->company, ['source_id' => $schedule->source_id, 'name' => 'Renamed', 'frequency' => 'monthly',
            'day' => 25, 'starts_on' => '2026-07-01', 'is_active' => true], $this->owner);
        $this->assertSame('2026-09-25', $moved->next_run_on->toDateString());
    }

    public function test_the_list_form_and_generate_button_stay_in_the_header_companies(): void
    {
        $other = Company::factory()->create(['code' => 'BETA']);
        $accountant = $this->userFor('accountant', $this->company, $other);
        $mine = $this->schedule($this->sourceInvoice(), [], $accountant);
        $theirs = $this->schedule($this->sourceInvoice($other, Party::factory()->for($other)->create()), ['name' => 'Beta retainer'], $accountant);
        $this->actingAs($accountant);
        session([CompanyContext::SESSION_KEY => $this->company->id]);
        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00'));

        Livewire::test(Index::class)->assertSee('Retainer')->assertDontSee('Beta retainer')->call('generate')->assertHasNoErrors();

        $this->assertSame(1, $mine->documents()->count());
        $this->assertSame(0, $theirs->documents()->count());

        Livewire::test(Form::class)->set('sourceId', (string) $theirs->source_id)->set('name', 'Sneaky')->call('save')->assertHasErrors('sourceId');
        Livewire::test(Form::class)->set('sourceId', (string) $mine->source_id)->set('name', 'Second')->set('day', '15')->call('save')
            ->assertHasNoErrors()->assertRedirect(route('admin.sales.recurring.index'));
        $this->assertSame('2026-07-15', RecurringInvoice::query()->where('name', 'Second')->sole()->next_run_on->toDateString());

        $this->actingAs($this->userFor('data-entry', $this->company));
        Livewire::test(Index::class)->call('generate')->assertForbidden();
    }
}
