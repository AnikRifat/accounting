<?php

namespace Tests\Feature;

use App\Livewire\Admin\Crm\Leads\Form as LeadForm;
use App\Livewire\Admin\Crm\Leads\Import;
use App\Livewire\Admin\Crm\Leads\Index as LeadIndex;
use App\Livewire\Admin\Crm\Sources\Form;
use App\Livewire\Admin\Crm\Sources\Index;
use App\Models\Company;
use App\Models\CrmSource;
use App\Models\Lead;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\Crm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class CrmSourcesTest extends TestCase
{
    use RefreshDatabase;

    private function userFor(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    private function sourceId(Company $company, string $name): int
    {
        return CrmSource::query()->where('company_id', $company->id)->where('name', $name)->value('id');
    }

    public function test_every_company_starts_with_the_default_sources_and_manages_its_own(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->assertEqualsCanonicalizing(Crm::DEFAULT_SOURCES, CrmSource::query()->where('company_id', $first->id)->pluck('name')->all());
        $this->actingAs($this->userFor('sales-manager', $first, $second));
        session([CompanyContext::SESSION_KEY => $first->id]);

        Livewire::test(Form::class)->set('name', ' Trade fair ')->call('save')->assertHasNoErrors()->assertRedirect(route('admin.crm.sources.index'));
        Livewire::test(Form::class)->set('name', 'Facebook')->call('save')->assertHasErrors(['name' => 'unique']);
        $this->assertSame(1, CrmSource::query()->where('name', 'Trade fair')->count());
        $this->assertSame($first->id, CrmSource::query()->where('name', 'Trade fair')->value('company_id'));

        $used = $this->sourceId($first, 'Referral');
        Lead::factory()->for($first)->create(['crm_source_id' => $used]);
        Livewire::test(Index::class)->call('delete', $used)->assertHasErrors('delete');
        Livewire::test(Index::class)->call('delete', $this->sourceId($first, 'Walk-in'))->assertHasNoErrors();
        $this->assertDatabaseMissing('crm_sources', ['company_id' => $first->id, 'name' => 'Walk-in']);
    }

    public function test_a_lead_takes_a_source_of_its_own_company_and_the_list_filters_by_it(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $this->actingAs($this->userFor('sales-manager', $mine));

        Livewire::test(LeadForm::class)->set('phone', '01711000000')->set('sourceId', (string) $this->sourceId($other, 'Facebook'))->call('save')->assertHasErrors('sourceId');
        Livewire::test(LeadForm::class)->set('name', 'From Facebook')->set('phone', '01711000000')->set('sourceId', (string) $this->sourceId($mine, 'Facebook'))->call('save')->assertHasNoErrors();
        Lead::factory()->for($mine)->create(['name' => 'Unknown Origin']);

        Livewire::test(LeadIndex::class)->set('source', 'Facebook')->assertSee('From Facebook')->assertDontSee('Unknown Origin')
            ->set('source', 'none')->assertSee('Unknown Origin')->assertDontSee('From Facebook');
        $this->get('/admin/crm/leads/'.Lead::query()->where('name', 'From Facebook')->value('id'))->assertOk()->assertSee('Facebook');
    }

    public function test_import_matches_sources_by_name_and_falls_back_to_the_default(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('sales-manager', $company));
        $csv = "Name,Phone,Source\nA,01711000001,website\nB,01711000002,Billboard\nC,01711000003,";

        Livewire::test(Import::class)->set('file', UploadedFile::fake()->createWithContent('leads.csv', $csv))
            ->set('sourceId', (string) $this->sourceId($company, 'Referral'))->call('import')->assertHasNoErrors();

        $this->assertSame(['Website', 'Referral', 'Referral'], Lead::query()->orderBy('phone')->with('source')->get()->map(fn (Lead $lead): string => $lead->source->name)->all());
    }
}
