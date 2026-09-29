<?php

namespace Tests\Feature;

use App\Livewire\Admin\Sales\ItemCategories\Form;
use App\Livewire\Admin\Sales\ItemCategories\Index;
use App\Livewire\Admin\Sales\Items\Form as ItemForm;
use App\Livewire\Admin\Sales\Items\Index as ItemIndex;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ItemCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_each_company_manages_its_own_categories_and_a_used_one_cannot_be_deleted(): void
    {
        ItemCategory::factory()->for($this->other)->create(['name' => 'Hardware']);
        $this->actingAs($this->manager($this->company, $this->other));
        session([CompanyContext::SESSION_KEY => $this->company->id]);

        Livewire::test(Form::class)->set('name', ' Hardware ')->call('save')->assertHasNoErrors()->assertRedirect(route('admin.sales.item-categories.index'));
        Livewire::test(Form::class)->set('name', 'Hardware')->call('save')->assertHasErrors(['name' => 'unique']);
        $hardware = ItemCategory::query()->where('company_id', $this->company->id)->sole();
        $this->assertSame('Hardware', $hardware->name);

        Livewire::test(Form::class, ['itemCategory' => $hardware])->set('name', 'Devices')->set('isActive', false)->call('save')->assertHasNoErrors();
        $this->assertSame(['Devices', false], [$hardware->fresh()->name, $hardware->fresh()->is_active]);

        Item::factory()->for($this->company)->create(['item_category_id' => $hardware->id]);
        $unused = ItemCategory::factory()->for($this->company)->create(['name' => 'Spare']);
        Livewire::test(Index::class)->call('delete', $hardware->id)->assertHasErrors('delete');
        Livewire::test(Index::class)->call('delete', $unused->id)->assertHasNoErrors();
        $this->assertModelExists($hardware);
        $this->assertModelMissing($unused);
    }

    public function test_an_item_takes_an_active_category_of_its_own_company_and_the_list_filters_by_it(): void
    {
        $services = ItemCategory::factory()->for($this->company)->create(['name' => 'Services']);
        $retired = ItemCategory::factory()->for($this->company)->create(['name' => 'Retired', 'is_active' => false]);
        $foreign = ItemCategory::factory()->for($this->other)->create(['name' => 'Foreign']);
        $this->actingAs($this->manager($this->company));

        Livewire::test(ItemForm::class)->set('name', 'Bad')->set('price', '10')->set('categoryId', (string) $foreign->id)->call('save')->assertHasErrors('categoryId');
        Livewire::test(ItemForm::class)->set('name', 'Bad')->set('price', '10')->set('categoryId', (string) $retired->id)->call('save')->assertHasErrors('categoryId');
        Livewire::test(ItemForm::class)->assertSee('Services')->assertDontSee('Retired')->assertDontSee('Foreign')
            ->set('name', 'Web design')->set('price', '100')->set('categoryId', (string) $services->id)->call('save')->assertHasNoErrors();
        $item = Item::query()->where('name', 'Web design')->sole();
        $this->assertSame($services->id, $item->item_category_id);

        $services->update(['is_active' => false]);
        Livewire::test(ItemForm::class, ['item' => $item])->assertSet('categoryId', (string) $services->id)->set('price', '120')->call('save')->assertHasNoErrors();
        Livewire::test(ItemForm::class, ['item' => $item])->set('categoryId', '')->call('save')->assertHasNoErrors();
        $this->assertNull($item->fresh()->item_category_id);

        $item->fresh()->update(['item_category_id' => $services->id]);
        Item::factory()->for($this->company)->create(['name' => 'Loose screw']);
        Livewire::test(ItemIndex::class)->set('category', 'Services')->assertSee('Web design')->assertDontSee('Loose screw')
            ->set('category', 'none')->assertSee('Loose screw')->assertDontSee('Web design');
    }

    public function test_categories_of_other_companies_are_out_of_reach_and_read_only_users_cannot_change_them(): void
    {
        $foreign = ItemCategory::factory()->for($this->other)->create(['name' => 'Foreign group']);
        ItemCategory::factory()->for($this->company)->create(['name' => 'Own group']);
        $this->actingAs($this->manager($this->company));

        $this->get(route('admin.sales.item-categories.index'))->assertOk()->assertSee('Own group')->assertDontSee('Foreign group');
        $this->get(route('admin.sales.item-categories.edit', $foreign))->assertNotFound();
        try {
            Livewire::test(Index::class)->call('delete', $foreign->id);
            $this->fail('Another company\'s category must not be deleted.');
        } catch (ModelNotFoundException) {
        }
        $this->assertModelExists($foreign);

        $dataEntry = User::factory()->create(['role' => 'data-entry']);
        $dataEntry->companies()->attach($this->company->id);
        $this->actingAs($dataEntry);
        $this->get(route('admin.sales.item-categories.index'))->assertOk()->assertSee('Own group')->assertDontSee(route('admin.sales.item-categories.create'));
        $this->get(route('admin.sales.item-categories.create'))->assertForbidden();
    }
}
