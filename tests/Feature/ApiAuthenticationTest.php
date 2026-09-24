<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_ignores_privileged_attributes_and_issues_an_expiring_token(): void
    {
        $this->travelTo(now()->startOfSecond());
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Member', 'email' => 'MEMBER@example.test', 'password' => 'StrongPass12345',
            'password_confirmation' => 'StrongPass12345', 'role' => 'owner',
            'extra_roles' => ['administrator'], 'is_active' => false, 'denied_permissions' => [],
        ])->assertCreated()->assertJsonPath('data.user.email', 'member@example.test');
        $user = User::sole();
        $this->assertSame('member', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertSame([], $user->extra_roles);
        $this->assertTrue(Hash::check('StrongPass12345', $user->password));
        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertSame(86400, (int) now()->diffInSeconds($token->expires_at));
        $this->assertStringNotContainsString('StrongPass12345', $response->getContent());
    }

    public function test_login_profile_update_and_logout_use_real_bearer_authentication(): void
    {
        $user = User::factory()->create(['password' => 'StrongPass12345']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'StrongPass12345'])
            ->assertOk()->json('data.token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->patchJson('/api/v1/me', ['name' => 'Updated Name', 'role' => 'owner'])->assertOk()->assertJsonPath('data.name', 'Updated Name');
        $this->assertSame('member', $user->fresh()->role);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_expired_tokens_are_rejected_with_401(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired', ['profile:read'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_read_only_token_cannot_update_profile(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('readonly', ['profile:read'])->plainTextToken;
        $this->withToken($token)->patchJson('/api/v1/me', ['name' => 'Forbidden'])->assertForbidden();
        $this->assertNotSame('Forbidden', $user->fresh()->name);
    }

    public function test_inactive_users_cannot_login_or_keep_using_an_issued_token(): void
    {
        $user = User::factory()->create(['password' => 'StrongPass12345', 'is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'StrongPass12345'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $token = $user->createToken('before-disable', ['profile:read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_anonymous_requests_return_json_401_and_invalid_registration_returns_422(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/register', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_login_attempts_are_rate_limited_with_429(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertTooManyRequests();
    }
}
