<?php

namespace Tests\Feature;

use App\Livewire\Admin\Entries\Index as EntryIndex;
use App\Livewire\Admin\Parties\Index as PartyIndex;
use App\Livewire\Admin\Users\Index as UserIndex;
use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

class FormSheetTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    public function test_create_and_edit_sheets_render_the_form_over_the_list(): void
    {
        $company = Company::factory()->create();
        $party = Party::factory()->create(['company_id' => $company->id, 'name' => 'Karim Traders']);
        $this->actingAs($this->user('accountant', $company));
        session([CompanyContext::SESSION_KEY => $company->id]);

        Livewire::test(PartyIndex::class)->call('openSheet', 'create')->assertSet('sheet', 'create')
            ->assertSee(__('Add party'))->assertSeeLivewire('admin.parties.form');
        Livewire::test(PartyIndex::class)->call('openSheet', 'edit:'.$party->id)
            ->assertSee(__('Edit party'))->assertSeeLivewire('admin.parties.form')
            ->call('closeSheet')->assertSet('sheet', '')->assertDontSeeLivewire('admin.parties.form');
        $this->get(route('admin.parties.index', ['sheet' => 'edit:'.$party->id]))->assertOk()->assertSee(__('Edit party'))->assertSee(__('Party details'));
    }

    public function test_a_sheet_never_opens_a_record_of_another_company(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $foreign = Party::factory()->create(['company_id' => $other->id]);
        $this->actingAs($this->user('accountant', $mine));

        $this->get(route('admin.parties.index', ['sheet' => 'edit:'.$foreign->id]))->assertNotFound();
    }

    public function test_create_sheets_that_need_a_company_ask_for_one_first(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        $next = route('admin.entries.index', ['sheet' => 'create:income'], false);
        Livewire::test(EntryIndex::class)->call('openSheet', 'create:income')
            ->assertSet('sheet', '')->assertRedirect(route('admin.choose-company', ['next' => $next]));
        $this->get(route('admin.choose-company', ['next' => $next]))->assertOk()->assertSee($first->name)->assertSee($second->name);

        session([CompanyContext::SESSION_KEY => $first->id]);
        Livewire::test(EntryIndex::class)->call('openSheet', 'create:income')->assertSet('sheet', 'create:income')->assertSeeLivewire('admin.entries.form');
    }

    public function test_sheets_keep_the_form_permissions(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->user('data-entry', $company));

        $this->get(route('admin.users.index', ['sheet' => 'create']))->assertForbidden();
        Livewire::test(PartyIndex::class)->call('openSheet', 'edit:'.Party::factory()->create(['company_id' => $company->id])->id)->assertForbidden();
        $this->assertTrue(true, UserIndex::class);
    }
}
