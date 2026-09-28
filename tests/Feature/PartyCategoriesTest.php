<?php

namespace Tests\Feature;

use App\Livewire\Admin\Parties\Form as PartyForm;
use App\Livewire\Admin\Parties\Index as PartyIndex;
use App\Livewire\Admin\PartyCategories\Form;
use App\Livewire\Admin\PartyCategories\Index;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyCategory;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PartyCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function userFor(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    private function employeeCategory(Company $company): PartyCategory
    {
        return PartyCategory::query()->where('company_id', $company->id)->where('is_system', true)->sole();
    }

    public function test_every_company_has_a_built_in_employee_category_that_employee_parties_get(): void
    {
        $company = Company::factory()->create();
        $employee = User::factory()->employeeOf($company)->create();

        $category = $this->employeeCategory($company);
        $this->assertSame('Employee', $category->name);
        $this->assertSame($category->id, $employee->parties()->sole()->party_category_id);
        $this->assertSame($category->id, PartyCategory::employeeCategoryId($company->id));
        $this->assertSame(1, PartyCategory::query()->where('company_id', $company->id)->count());
    }

    public function test_a_custom_category_named_employee_becomes_the_built_in_one(): void
    {
        $company = Company::factory()->create();
        $category = $this->employeeCategory($company);
        $category->forceFill(['is_system' => false, 'is_active' => false])->save();

        $this->assertSame($category->id, PartyCategory::employeeCategoryId($company->id));
        $this->assertTrue($category->fresh()->is_system);
        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_the_built_in_category_cannot_be_edited_deleted_or_picked_for_a_custom_party(): void
    {
        $company = Company::factory()->create();
        $category = $this->employeeCategory($company);
        $party = Party::factory()->for($company)->create();
        $this->actingAs($this->userFor('accountant', $company));

        $this->get('/admin/party-categories')->assertOk()->assertSee('Built-in')->assertDontSee(route('admin.party-categories.edit', $category));
        $this->get('/admin/party-categories/'.$category->id.'/edit')->assertNotFound();
        try {
            Livewire::test(Index::class)->call('delete', $category->id);
            $this->fail('The built-in category must not be deleted.');
        } catch (ModelNotFoundException) {
        }
        Livewire::test(PartyForm::class, ['party' => $party])->assertViewHas('categories', ['' => __('No category')])
            ->set('categoryId', (string) $category->id)->call('save')->assertHasErrors('categoryId');
        $this->assertModelExists($category);
        $this->assertNull($party->fresh()->party_category_id);
    }

    public function test_custom_categories_are_per_header_company_and_parties_use_their_own_company_ones(): void
    {
        [$first, $second] = Company::factory()->count(2)->create();
        $this->actingAs($this->userFor('accountant', $first, $second));
        session([CompanyContext::SESSION_KEY => $first->id]);

        Livewire::test(Form::class)->set('name', ' Supplier ')->call('save')->assertHasNoErrors()->assertRedirect(route('admin.party-categories.index'));
        Livewire::test(Form::class)->set('name', 'Supplier')->call('save')->assertHasErrors(['name' => 'unique']);
        Livewire::test(Form::class)->set('name', 'Employee')->call('save')->assertHasErrors(['name' => 'unique']);
        session([CompanyContext::SESSION_KEY => $second->id]);
        Livewire::test(Form::class)->set('name', 'Supplier')->call('save')->assertHasNoErrors();
        $foreign = PartyCategory::query()->where('company_id', $second->id)->where('name', 'Supplier')->sole();

        session([CompanyContext::SESSION_KEY => $first->id]);
        $own = PartyCategory::query()->where('company_id', $first->id)->where('name', 'Supplier')->sole();
        Livewire::test(PartyForm::class)->set('name', 'Noor Rice Mills')->set('categoryId', (string) $foreign->id)->call('save')->assertHasErrors('categoryId');
        Livewire::test(PartyForm::class)->set('name', 'Noor Rice Mills')->set('categoryId', (string) $own->id)->call('save')->assertHasNoErrors();
        $this->assertSame($own->id, Party::query()->where('name', 'Noor Rice Mills')->value('party_category_id'));
    }

    public function test_the_party_list_filters_by_category_and_deleting_a_category_keeps_its_parties(): void
    {
        $company = Company::factory()->create();
        $customer = PartyCategory::factory()->for($company)->create(['name' => 'Customer']);
        $party = Party::factory()->for($company)->create(['name' => 'Rahman Traders', 'party_category_id' => $customer->id]);
        Party::factory()->for($company)->create(['name' => 'Loose Party']);
        User::factory()->employeeOf($company)->create(['name' => 'Staff Member']);
        $this->actingAs($this->userFor('accountant', $company));

        Livewire::test(PartyIndex::class)->set('category', 'Customer')->assertSee('Rahman Traders')->assertDontSee('Loose Party')
            ->set('category', 'Employee')->assertSee('Staff Member')->assertDontSee('Rahman Traders')
            ->set('category', 'none')->assertSee('Loose Party')->assertDontSee('Staff Member');

        Livewire::test(Index::class)->call('delete', $customer->id)->assertHasNoErrors();
        $this->assertModelMissing($customer);
        $this->assertNull($party->fresh()->party_category_id);
    }

    public function test_managing_categories_needs_parties_update(): void
    {
        $company = Company::factory()->create();
        $custom = PartyCategory::factory()->for($company)->create(['name' => 'Walk-in']);
        $this->actingAs($this->userFor('data-entry', $company));

        $this->get('/admin/party-categories')->assertOk()->assertSee('Walk-in')->assertDontSee(route('admin.party-categories.edit', $custom));
        $this->get('/admin/party-categories/create')->assertForbidden();
        Livewire::test(Index::class)->call('delete', $custom->id)->assertForbidden();
    }
}
