<?php

namespace Tests\Feature;

use App\Livewire\Admin\Profile;
use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_or_member_cannot_access_profile(): void
    {
        $this->get('/admin/profile')->assertRedirect('/admin/login');

        $member = User::factory()->create(['role' => 'member']);
        $this->actingAs($member)->get('/admin/profile')->assertForbidden();
    }

    public function test_admin_user_can_view_profile_page(): void
    {
        $user = User::factory()->create([
            'role' => 'administrator',
            'name' => 'Jane Admin',
            'email' => 'jane@example.test',
        ]);

        $this->actingAs($user)
            ->get('/admin/profile')
            ->assertOk()
            ->assertSee('Jane Admin')
            ->assertSee('jane@example.test')
            ->assertSee(__('Change password'))
            ->assertSee(__('Profile information'));
    }

    public function test_user_can_change_password_with_valid_current_password(): void
    {
        $user = User::factory()->create([
            'role' => 'administrator',
            'password' => 'OldPassword1234',
        ]);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('current_password', 'OldPassword1234')
            ->set('password', 'NewSecretPass1234')
            ->set('password_confirmation', 'NewSecretPass1234')
            ->call('updatePassword')
            ->assertHasNoErrors()
            ->assertSet('current_password', '')
            ->assertSet('password', '')
            ->assertSet('password_confirmation', '');

        $this->assertTrue(Hash::check('NewSecretPass1234', $user->fresh()->password));
    }

    public function test_password_change_fails_with_incorrect_current_password(): void
    {
        $user = User::factory()->create([
            'role' => 'accountant',
            'password' => 'CorrectPassword123',
        ]);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('current_password', 'WrongPassword123')
            ->set('password', 'NewSecretPass1234')
            ->set('password_confirmation', 'NewSecretPass1234')
            ->call('updatePassword')
            ->assertHasErrors(['current_password']);

        $this->assertTrue(Hash::check('CorrectPassword123', $user->fresh()->password));
    }

    public function test_password_change_requires_confirmation_and_complexity(): void
    {
        $user = User::factory()->create([
            'role' => 'data-entry',
            'password' => 'ValidPass123456',
        ]);

        $this->actingAs($user);

        // Mismatched confirmation
        Livewire::test(Profile::class)
            ->set('current_password', 'ValidPass123456')
            ->set('password', 'NewPass123456')
            ->set('password_confirmation', 'DifferentPass123')
            ->call('updatePassword')
            ->assertHasErrors(['password']);

        // Too short (<12 chars)
        Livewire::test(Profile::class)
            ->set('current_password', 'ValidPass123456')
            ->set('password', 'Short12')
            ->set('password_confirmation', 'Short12')
            ->call('updatePassword')
            ->assertHasErrors(['password']);

        // No numbers
        Livewire::test(Profile::class)
            ->set('current_password', 'ValidPass123456')
            ->set('password', 'NoNumbersAtAllHere')
            ->set('password_confirmation', 'NoNumbersAtAllHere')
            ->call('updatePassword')
            ->assertHasErrors(['password']);
    }

    public function test_user_can_update_profile_information_and_syncs_parties(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create([
            'role' => 'data-entry',
            'name' => 'Original Name',
            'email' => 'original@example.test',
            'phone' => '01700000000',
        ]);
        $user->companies()->attach($company->id);
        $user->syncParties();

        $party = Party::where('company_id', $company->id)->where('user_id', $user->id)->sole();
        $this->assertSame('Original Name', $party->name);
        $this->assertSame('01700000000', $party->phone);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('name', 'Updated Name')
            ->set('email', 'updated@example.test')
            ->set('phone', '01811111111')
            ->call('updateProfile')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('updated@example.test', $user->email);
        $this->assertSame('01811111111', $user->phone);

        $party->refresh();
        $this->assertSame('Updated Name', $party->name);
        $this->assertSame('01811111111', $party->phone);
    }

    public function test_profile_update_validates_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        $user = User::factory()->create(['role' => 'accountant', 'email' => 'my@example.test']);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('name', 'New Name')
            ->set('email', 'taken@example.test')
            ->call('updateProfile')
            ->assertHasErrors(['email']);
    }

    public function test_super_admin_can_update_password_and_profile(): void
    {
        $root = User::factory()->create([
            'role' => 'owner',
            'password' => 'RootCurrentPass12',
            'name' => 'Super Boss',
        ]);

        $this->actingAs($root);

        Livewire::test(Profile::class)
            ->set('current_password', 'RootCurrentPass12')
            ->set('password', 'RootNewPass98765')
            ->set('password_confirmation', 'RootNewPass98765')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('RootNewPass98765', $root->fresh()->password));

        Livewire::test(Profile::class)
            ->set('name', 'Super Boss Updated')
            ->set('email', $root->email)
            ->call('updateProfile')
            ->assertHasNoErrors();

        $this->assertSame('Super Boss Updated', $root->fresh()->name);
    }
}
