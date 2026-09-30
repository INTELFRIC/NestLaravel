<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_self_registration_cannot_grant_a_privileged_role(): void
    {
        foreach (['platform_admin', 'fleet_manager'] as $role) {
            $this->postJson('/api/auth/register', [
                'name' => 'Mallory',
                'email' => "mallory-{$role}@example.com",
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'role' => $role,
            ])->assertStatus(422)->assertJsonValidationErrors('role');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_auth_endpoints_are_rate_limited_per_account(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'victim@example.com', 'password' => 'wrong-'.$i])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'victim@example.com', 'password' => 'guess'])
            ->assertStatus(429);
    }

    public function test_user_can_register_login_and_fetch_profile(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Casey User',
            'email' => 'casey@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $register
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'casey@example.com')
            ->assertJsonPath('data.user.roles.0', 'customer')
            ->assertJsonStructure([
                'data' => ['token', 'token_type', 'abilities', 'user'],
                'meta' => ['request_id'],
            ]);

        $token = $register->json('data.token');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'casey@example.com');

        $login = $this->postJson('/api/auth/login', [
            'email' => 'casey@example.com',
            'password' => 'Password123!',
        ]);

        $login
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->withToken($login->json('data.token'))
            ->getJson('/api/users/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'casey@example.com')
            ->assertJsonPath('data.roles.0', 'customer');
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@example.com',
            'password' => 'Password123!',
        ]);
        $user->assignRole('customer');

        $token = $user->createToken('api', $user->tokenAbilities())->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unauthenticated_api_requests_return_json_401(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_permission_middleware_denies_without_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('customer');

        Sanctum::actingAs($user);

        $this->getJson('/api/users/me')
            ->assertOk();

        // Inline route to exercise permission middleware in isolation.
        Route::middleware(['api', 'auth:sanctum', 'permission:users.manage'])
            ->get('/api/_permission_probe', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/_permission_probe')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }
}
