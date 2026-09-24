<?php

namespace Tests\Feature;

use App\Enums\EntryType;
use App\Livewire\Admin\Entries\Index as EntryIndex;
use App\Livewire\Admin\Parties\Index as PartyIndex;
use App\Livewire\Admin\Users\Index as UserIndex;
use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

class TableToolsTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    /** @return list<list<mixed>> the rows of the first sheet of an .xlsx file */
    private function readXlsx(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'test-xlsx');
        file_put_contents($path, $contents);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    /** @return list<list<mixed>> the rows after the header row that starts with $firstLabel */
    private function bodyRows(array $rows, string $firstLabel): array
    {
        $header = array_search($firstLabel, array_column($rows, 0), true);
        $this->assertNotFalse($header, 'The export has no header row.');

        return array_slice($rows, $header + 1);
    }

    public function test_excel_export_holds_the_filtered_scoped_rows_and_chosen_columns(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        Party::factory()->create(['company_id' => $mine->id, 'name' => 'Karim Traders', 'phone' => '01711']);
        Party::factory()->create(['company_id' => $mine->id, 'name' => 'Rahim Stores', 'is_active' => false]);
        Party::factory()->create(['company_id' => $other->id, 'name' => 'Hidden Supplier']);
        $this->actingAs($this->user('accountant', $mine));

        $download = Livewire::test(PartyIndex::class)->set('status', 'active')->call('exportTable', 'xlsx', 'all', ['name', 'phone'])->effects['download'];
        $rows = $this->readXlsx(base64_decode($download['content']));

        $this->assertSame(__('Parties'), $rows[0][0]);
        $this->assertContains([__('Name'), __('Phone')], $rows);
        $this->assertContains(['Karim Traders', '01711'], $rows);
        $this->assertStringNotContainsString('Rahim Stores', json_encode($rows));
        $this->assertStringNotContainsString('Hidden Supplier', json_encode($rows));
    }

    public function test_selected_rows_only_narrow_the_visible_rows(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $karim = Party::factory()->create(['company_id' => $mine->id, 'name' => 'Karim Traders']);
        Party::factory()->create(['company_id' => $mine->id, 'name' => 'Rahim Stores']);
        $hidden = Party::factory()->create(['company_id' => $other->id, 'name' => 'Hidden Supplier']);
        $this->actingAs($this->user('accountant', $mine));

        $download = Livewire::test(PartyIndex::class)->set('selected', [(string) $karim->id, (string) $hidden->id])
            ->call('exportTable', 'xlsx', 'selected', ['name'])->effects['download'];
        $names = array_column($this->bodyRows($this->readXlsx(base64_decode($download['content'])), __('Name')), 0);

        $this->assertSame(['Karim Traders'], $names);
    }

    public function test_money_exports_as_numbers_and_salary_only_to_those_who_can_edit_employees(): void
    {
        $company = Company::factory()->create();
        $this->bill($company, EntryType::Income, '4000', 1_25_000_50, '2026-09-02', ['description' => 'Consulting fee']);
        $this->actingAs($this->owner);
        session([CompanyContext::SESSION_KEY => $company->id]);

        $download = Livewire::test(EntryIndex::class)->call('exportTable', 'xlsx', 'all', ['number', 'total'])->effects['download'];
        $this->assertSame(125000.5, $this->bodyRows($this->readXlsx(base64_decode($download['content'])), __('Number'))[0][1]);

        $header = fn (): array => collect($this->readXlsx(base64_decode(Livewire::test(UserIndex::class)->call('exportTable', 'xlsx', 'all', ['name', 'salary'])->effects['download']['content'])))
            ->first(fn (array $row): bool => ($row[0] ?? null) === __('Name'));
        $this->assertSame([__('Name'), __('Monthly salary')], $header());
        $viewer = $this->user('accountant', $company);
        $viewer->forceFill(['denied_permissions' => ['users.update']])->save();
        $this->actingAs($viewer->fresh());
        $this->assertSame([__('Name')], $header());
    }

    public function test_print_page_is_prepared_for_and_shown_only_to_its_user(): void
    {
        $company = Company::factory()->create();
        Party::factory()->create(['company_id' => $company->id, 'name' => 'Karim Traders', 'phone' => '01711']);
        $accountant = $this->user('accountant', $company);
        $this->actingAs($accountant);

        $url = Livewire::test(PartyIndex::class)->call('exportTable', 'print', 'all', ['name'], 'landscape')->effects['dispatches'][0]['params']['url'];
        $this->get($url)->assertOk()->assertSee('Karim Traders')->assertDontSee('01711')->assertSee('A4 landscape');

        $this->actingAs($this->user('accountant', $company))->get($url)->assertNotFound();
        Cache::flush();
        $this->actingAs($accountant)->get($url)->assertNotFound();
    }
}
