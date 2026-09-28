<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Livewire\Admin\Sales\Reports\Ageing;
use App\Livewire\Admin\Sales\Reports\Breakdown;
use App\Livewire\Admin\Sales\Reports\Register;
use App\Livewire\Admin\Sales\Reports\Vat;
use App\Models\Company;
use App\Models\Document;
use App\Models\Item;
use App\Models\Party;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\LedgerService;
use App\Services\RecurringInvoices;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\Feature\Concerns\MakesDocuments;
use Tests\TestCase;

class SalesReportsTest extends TestCase
{
    use MakesDocuments, RefreshDatabase;

    private const SEPTEMBER = ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'];

    private User $owner;

    private Company $company;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00'));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->company = Company::factory()->create(['code' => 'ACME', 'name' => 'Acme']);
        $this->customer = Party::factory()->for($this->company)->create(['name' => 'Rahman Traders']);
        $this->actingAs($this->owner);
        session([CompanyContext::SESSION_KEY => $this->company->id]);
    }

    private function service(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function pay(Document $document, int $amount): void
    {
        $this->service()->recordPayment($document, ['paid_on' => $document->issue_date->toDateString(), 'amount' => $amount,
            'account_id' => (int) $this->company->accounts()->where('code', '1000')->value('id')], $this->owner);
    }

    /** Issues a credit note against an invoice with the given lines (save() data shape). */
    private function creditNote(Document $invoice, array $lines): Document
    {
        $note = $this->service()->convert($invoice, DocumentType::CreditNote, $this->owner);
        $this->service()->save($note, $this->company, DocumentType::CreditNote, $this->dataOf($note, ['lines' => $lines]), $this->owner);

        return $this->service()->issue($note, $this->owner)->fresh();
    }

    private function line(string $description, int $units, int $price, int $rate = 0, ?Item $item = null): array
    {
        return ['item_id' => $item?->id, 'description' => $description, 'quantity' => $units * 1000, 'unit_price' => $price, 'tax_rate' => $rate,
            'discount_type' => null, 'discount_value' => 0, 'account_id' => (int) $this->company->accounts()->where('code', '4000')->value('id')];
    }

    public function test_the_register_lists_issued_documents_and_totals_all_but_void_ones(): void
    {
        $paid = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Design', 1, 100_000, 1500]]);
        $this->pay($paid, 40_000);
        $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Hosting', 1, 20_000]], ['issue_date' => '2026-09-10', 'post_to_accounts' => true]);
        $void = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Wrong', 1, 99_000]]);
        $this->service()->void($void, 'Typo', $this->owner);
        $this->draft($this->company, DocumentType::Invoice, $this->customer, [['Draft', 1, 55_000]]);
        $this->issued($this->company, DocumentType::Invoice, $this->customer, [['August', 1, 7_000]], ['issue_date' => '2026-08-31', 'due_date' => null]);
        $this->issued($this->company, DocumentType::Quotation, $this->customer, [['Offer', 1, 8_000]]);

        Livewire::withQueryParams(self::SEPTEMBER)->test(Register::class)
            ->assertViewHas('documents', fn (Collection $documents): bool => $documents->pluck('number')->all() === ['INV-00001', 'INV-00003', 'INV-00002'])
            ->assertViewHas('totals', ['subtotal' => 120_000, 'discount' => 0, 'tax' => 15_000, 'total' => 135_000, 'paid' => 40_000, 'balance' => 95_000])
            ->assertSee('INV-00003')->assertDontSee('INV-00004')
            ->set('type', 'quotation')
            ->assertViewHas('totals', fn (array $totals): bool => $totals['total'] === 8_000 && $totals['balance'] === 0);
    }

    public function test_ageing_buckets_open_balances_by_days_overdue(): void
    {
        $invoice = fn (string $issued, ?string $due, int $amount) => $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Work', 1, $amount]],
            ['issue_date' => $issued, 'due_date' => $due]);
        $invoice('2026-12-01', '2026-12-31', 1_000);
        $invoice('2026-12-01', '2026-12-15', 2_000);
        $invoice('2026-11-01', '2026-11-15', 3_000);
        $invoice('2026-10-01', '2026-10-15', 4_000);
        $invoice('2026-09-01', null, 5_000);
        $this->pay($invoice('2026-09-01', '2026-09-01', 6_000), 6_000);
        $partly = $invoice('2026-06-01', '2026-06-30', 10_000);
        $this->pay($partly, 3_000);
        $other = Party::factory()->for($this->company)->create(['name' => 'Karim Stores']);
        $this->issued($this->company, DocumentType::Invoice, $other, [['Work', 1, 700]], ['issue_date' => '2026-12-20', 'due_date' => '2026-12-30']);
        $this->issued($this->company, DocumentType::Invoice, $other, [['Future', 1, 900]], ['issue_date' => '2027-01-05', 'due_date' => '2027-01-10']);

        Livewire::withQueryParams(['asOf' => '2026-12-31'])->test(Ageing::class)
            ->assertViewHas('parties', fn (Collection $parties): bool => $parties->map(fn (array $row): array => [$row['party']->name, $row['count'], $row['buckets']])->all() === [
                ['Karim Stores', 1, ['not_due' => 0, 'days_1_30' => 700, 'days_31_60' => 0, 'days_61_90' => 0, 'days_90_plus' => 0]],
                ['Rahman Traders', 6, ['not_due' => 1_000, 'days_1_30' => 2_000, 'days_31_60' => 3_000, 'days_61_90' => 4_000, 'days_90_plus' => 12_000]],
            ])
            ->assertViewHas('totals', ['not_due' => 1_000, 'days_1_30' => 2_700, 'days_31_60' => 3_000, 'days_61_90' => 4_000, 'days_90_plus' => 12_000])
            ->set('kind', 'bill')->assertViewHas('parties', fn (Collection $parties): bool => $parties->isEmpty());
    }

    public function test_the_vat_report_nets_credit_notes_and_matches_the_vat_payable_ledger_for_posted_documents(): void
    {
        $posted = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Design', 1, 100_000, 1500]], ['post_to_accounts' => true]);
        $this->creditNote($posted, [$this->line('Refund', 1, 10_000, 1500)]);
        $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Books', 1, 20_000, 750]], ['issue_date' => '2026-08-15']);
        $void = $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Wrong', 1, 50_000, 1500]]);
        $this->service()->void($void, 'Typo', $this->owner);
        $this->issued($this->company, DocumentType::Invoice, $this->customer, [['October', 1, 30_000, 1500]], ['issue_date' => '2026-10-02', 'due_date' => null]);
        $ledgerVat = app(LedgerService::class)->balance($this->company->accounts()->where('code', '2100')->sole());

        Livewire::withQueryParams(['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-09-30'])->test(Vat::class)
            ->assertViewHas('byRate', fn (Collection $rates): bool => $rates->all() === [750 => ['net' => 20_000, 'tax' => 1_500], 1500 => ['net' => 90_000, 'tax' => 13_500]])
            ->assertViewHas('byMonth', fn (Collection $months): bool => $months->all() === ['2026-08' => ['net' => 20_000, 'tax' => 1_500], '2026-09' => ['net' => 90_000, 'tax' => 13_500]])
            ->assertViewHas('total', ['net' => 110_000, 'tax' => 15_000])
            ->assertViewHas('postedTax', 13_500)
            ->assertViewHas('ledgerTax', 13_500)
            ->assertSee('Sep 2026');
        $this->assertSame(13_500, $ledgerVat);
    }

    public function test_the_breakdown_shows_sales_by_customer_and_item_and_purchases_by_supplier(): void
    {
        $design = Item::factory()->for($this->company)->create(['name' => 'Design', 'tax_rate' => 1500]);
        $other = Party::factory()->for($this->company)->create(['name' => 'Karim Stores']);
        $first = $this->issued($this->company, DocumentType::Invoice, $this->customer, [], ['lines' => [
            $this->line('Logo', 2, 50_000, 1500, $design), $this->line('Printing', 1, 10_000)]]);
        $this->issued($this->company, DocumentType::Invoice, $other, [], ['lines' => [$this->line('Banner', 1, 50_000, 1500, $design)]]);
        $this->creditNote($first, [$this->line('Logo returned', 1, 50_000, 1500, $design)]);
        $supplier = Party::factory()->for($this->company)->create(['name' => 'Paper House']);
        $this->issued($this->company, DocumentType::Bill, $supplier, [['Paper', 10, 500, 1500]]);

        Livewire::withQueryParams(self::SEPTEMBER)->test(Breakdown::class)
            ->assertViewHas('parties', fn (Collection $rows): bool => $rows->map(fn (array $row): array => [$row['name'], $row['count'], $row['net'], $row['tax'], $row['total']])->all() === [
                ['Rahman Traders', 1, 60_000, 7_500, 67_500],
                ['Karim Stores', 1, 50_000, 7_500, 57_500],
            ])
            ->assertViewHas('items', fn (Collection $rows): bool => $rows->map(fn (array $row): array => [$row['name'], $row['count'], $row['net'], $row['tax']])->all() === [
                ['Design', 2_000, 100_000, 15_000],
                ['Other items', 1_000, 10_000, 0],
            ])
            ->set('side', 'purchases')
            ->assertViewHas('parties', fn (Collection $rows): bool => $rows->map(fn (array $row): array => [$row['name'], $row['net'], $row['tax']])->all() === [['Paper House', 5_000, 750]])
            ->assertViewHas('items', fn (Collection $rows): bool => $rows->isEmpty());
    }

    public function test_all_companies_mode_consolidates_only_accessible_companies(): void
    {
        $second = Company::factory()->create(['code' => 'BETA', 'name' => 'Beta']);
        $hidden = Company::factory()->create(['code' => 'HIDE', 'name' => 'Hidden']);
        $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Acme work', 1, 10_000, 1500]]);
        $this->issued($second, DocumentType::Invoice, Party::factory()->for($second)->create(['name' => 'Beta Buyer']), [['Beta work', 1, 20_000]]);
        $this->issued($hidden, DocumentType::Invoice, Party::factory()->for($hidden)->create(['name' => 'Hidden Buyer']), [['Hidden work', 1, 40_000]]);
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->companies()->attach([$this->company->id, $second->id]);
        $this->actingAs($accountant);
        session([CompanyContext::SESSION_KEY => null]);

        Livewire::withQueryParams(self::SEPTEMBER)->test(Register::class)->assertViewHas('showCompany', true)
            ->assertSee('Beta Buyer')->assertDontSee('Hidden Buyer')
            ->assertViewHas('totals', fn (array $totals): bool => $totals['total'] === 31_500);
        Livewire::withQueryParams(self::SEPTEMBER)->test(Breakdown::class)
            ->assertViewHas('parties', fn (Collection $rows): bool => $rows->pluck('company')->sort()->values()->all() === ['Acme', 'Beta']);
        Livewire::withQueryParams(self::SEPTEMBER)->test(Vat::class)->assertViewHas('total', ['net' => 30_000, 'tax' => 1_500])
            ->assertViewHas('companies', fn (Collection $rows): bool => $rows->count() === 2);
        Livewire::withQueryParams(['asOf' => '2026-12-31'])->test(Ageing::class)
            ->assertViewHas('totals', fn (array $totals): bool => array_sum($totals) === 31_500);

        $this->actingAs(User::factory()->create(['role' => 'data-entry']));
        $this->get(route('admin.sales.reports.index'))->assertForbidden();
    }

    public function test_the_report_pages_render(): void
    {
        $this->issued($this->company, DocumentType::Invoice, $this->customer, [['Design', 1, 100_000, 1500]], ['post_to_accounts' => true]);

        foreach (['index', 'register', 'ageing', 'vat', 'breakdown'] as $report) {
            $this->get(route('admin.sales.reports.'.$report))->assertOk();
        }
        $source = $this->draft($this->company, DocumentType::Invoice, $this->customer, [['Retainer', 1, 5_000]]);
        $schedule = app(RecurringInvoices::class)->save(null, $this->company, ['source_id' => $source->id, 'name' => 'Monthly retainer', 'frequency' => 'monthly',
            'day' => 1, 'starts_on' => '2026-10-01', 'is_active' => true], $this->owner);
        $item = Item::factory()->for($this->company)->create(['name' => 'Consulting']);

        $this->get(route('admin.sales.recurring.index', ['sheet' => 'edit:'.$schedule->id]))->assertOk()->assertSee('Monthly retainer')->assertSee('Save schedule');
        $this->get(route('admin.sales.recurring.index', ['sheet' => 'create:'.$source->id]))->assertOk()->assertSee('Rahman Traders');
        $this->get(route('admin.sales.recurring.create'))->assertOk();
        $this->get(route('admin.sales.items.index', ['sheet' => 'edit:'.$item->id]))->assertOk()->assertSee('Save item');
        $this->get(route('admin.sales.items.create'))->assertOk();
        $this->get(route('admin.sales.settings'))->assertOk()->assertSee('INV-00002');
    }
}
