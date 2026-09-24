<?php

namespace Tests\Feature;

use App\Livewire\Admin\Settings;
use App\Models\ApplicationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_public_settings_are_exposed_and_registration_switch_is_enforced(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Settings::class)->set('appName', 'My Application')->set('supportEmail', 'help@example.test')->set('registrationEnabled', false)->call('save')->assertHasNoErrors();
        auth()->logout();
        $this->getJson('/api/v1/configuration')->assertOk()->assertJsonPath('data.app_name', 'My Application')->assertJsonPath('data.registration_enabled', false);
        $this->postJson('/api/v1/auth/register', ['name' => 'Member', 'email' => 'member@example.test', 'password' => 'StrongPass12345', 'password_confirmation' => 'StrongPass12345'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'member@example.test']);
    }

    public function test_non_public_database_keys_are_not_returned_by_configuration(): void
    {
        ApplicationSetting::create(['key' => 'internal_key', 'value' => 'private-placeholder']);
        $this->getJson('/api/v1/configuration')->assertOk()->assertJsonMissing(['internal_key' => 'private-placeholder']);
    }

    public function test_employee_cannot_change_application_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'employee']));
        $this->get('/admin/settings')->assertForbidden();
    }
}
