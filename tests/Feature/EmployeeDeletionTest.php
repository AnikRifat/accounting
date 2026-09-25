<?php

namespace Tests\Feature;

use App\Enums\EntryType;
use App\Livewire\DeleteRecordDrawer;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\RecordDeletion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

/** Employees are deleted through the shared delete drawer, only while no transaction names them. */
class EmployeeDeletionTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->company = Company::factory()->create(['code' => 'FT']);
    }

    private function employee(string $role, Company ...$companies): User
    {
        $user = $this->user($role, ...$companies);
        $user->syncParties();

        return $user;
    }

    private function expense(User $actor, array $extra = []): JournalEntry
    {
        return app(LedgerService::class)->record($this->company, EntryType::Expense, [
            'entry_date' => '2026-09-01', 'amount' => 1_000, 'category_account_id' => $this->accountId($this->company, '5100'),
            'payments' => [['account_id' => $this->accountId($this->company, '1000'), 'amount' => 1_000]], ...$extra,
        ], $actor);
    }

    public function test_employee_no_transaction_names_is_deleted_with_login_assignments_and_parties(): void
    {
        $employee = $this->employee('data-entry', $this->company, Company::factory()->create());
        $employee->createToken('phone');
        DB::table('sessions')->insert(['id' => 'employee-session', 'user_id' => $employee->id, 'payload' => '', 'last_activity' => time()]);
        $this->assertSame(2, $employee->parties()->count());
        $this->actingAs($this->owner);

        Livewire::test(DeleteRecordDrawer::class)->call('load', 'user', $employee->id)->assertSet('open', true)
            ->assertSee(__('Delete employee'))->call('deleteUnused')->assertHasNoErrors()->assertRedirect();

        $this->assertModelMissing($employee);
        $this->assertFalse(Party::where('user_id', $employee->id)->exists());
        $this->assertFalse(DB::table('company_user')->where('user_id', $employee->id)->exists());
        $this->assertFalse(DB::table('sessions')->where('user_id', $employee->id)->exists());
        $this->assertFalse(DB::table('personal_access_tokens')->where('tokenable_id', $employee->id)->exists());
    }

    public function test_employee_named_by_any_transaction_is_kept(): void
    {
        $author = $this->employee('data-entry', $this->company);
        $this->expense($author);
        $payer = $this->employee('data-entry', $this->company);
        $this->expense($this->owner, ['paid_by' => $payer->id]);
        $party = $this->employee('data-entry', $this->company);
        $this->expense($this->owner, ['party_id' => $party->parties()->sole()->id])->delete();
        $voider = $this->employee('accountant', $this->company);
        app(LedgerService::class)->void($this->expense($this->owner), 'Mistake', $voider);
        $this->actingAs($this->owner);

        foreach ([$author, $payer, $party, $voider] as $employee) {
            Livewire::test(DeleteRecordDrawer::class)->call('load', 'user', $employee->id)
                ->assertSee(__('Deactivate the employee instead, so the history keeps their name.'))->assertDontSee(__('Delete employee'))
                ->call('deleteUnused')->assertHasErrors('record');
            $this->assertModelExists($employee);
        }
        $this->assertSame(4, JournalEntry::withTrashed()->count());
    }

    public function test_only_managers_with_the_permission_reach_employees_they_manage(): void
    {
        $other = Company::factory()->create();
        RolePermission::factory()->create(['role' => 'hr', 'permissions' => [...config('permissions.roles.data-entry'), 'users.view', 'users.delete']]);
        $manager = $this->employee('hr', $this->company);
        $shared = $this->employee('data-entry', $this->company, $other);
        $elsewhere = $this->employee('data-entry', $other);
        $administrator = $this->employee('administrator', $this->company);
        $local = $this->employee('data-entry', $this->company);

        $this->actingAs($this->employee('accountant', $this->company));
        Livewire::test(DeleteRecordDrawer::class)->call('load', 'user', $local->id)->assertForbidden();

        $this->actingAs($manager);
        foreach ([$shared, $elsewhere, $administrator, $this->owner, $manager] as $unreachable) {
            Livewire::test(DeleteRecordDrawer::class)->call('load', 'user', $unreachable->id)->assertNotFound();
        }
        $this->assertThrows(fn () => app(RecordDeletion::class)->deleteUnused($manager, $manager), AuthorizationException::class);
        $this->assertThrows(fn () => app(RecordDeletion::class)->deleteUnused($shared, $manager), AuthorizationException::class);

        foreach (['hardDelete', 'transfer'] as $action) {
            Livewire::test(DeleteRecordDrawer::class)->call('load', 'user', $local->id)->call($action)->assertNotFound();
        }
        Livewire::test(DeleteRecordDrawer::class)->call('load', 'user', $local->id)->assertSet('open', true)->call('deleteUnused')->assertHasNoErrors();
        $this->assertModelMissing($local);
    }

    public function test_employee_rows_offer_delete_to_managers_except_for_themselves(): void
    {
        $administrator = $this->employee('administrator', $this->company);
        $employee = $this->employee('data-entry', $this->company);

        $this->actingAs($administrator);
        $this->get(route('admin.users.index'))->assertOk()->assertSee("kind: 'user', id: {$employee->id}", false)
            ->assertDontSee("kind: 'user', id: {$administrator->id}", false);

        $this->actingAs($this->employee('accountant', $this->company));
        $this->get(route('admin.users.index'))->assertOk()->assertDontSee("kind: 'user'", false);
    }
}
