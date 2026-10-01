<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_active_user_can_login_and_receive_safe_session(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@dragonmart.local',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'email' => 'owner@dragonmart.local',
                    'name' => 'Owner DragonMart',
                    'is_active' => true,
                ],
            ]);

        $this->assertAuthenticated();
    }

    public function test_inactive_user_is_rejected_at_login(): void
    {
        $user = User::where('email', 'mahengon@gmail.com')->first();
        $user->update(['is_active' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'mahengon@gmail.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'ACCOUNT_INACTIVE',
                ],
            ]);

        $this->assertGuest();
    }

    public function test_invalid_password_returns_unauthenticated(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@dragonmart.local',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                ],
            ]);

        $this->assertGuest();
    }

    public function test_authenticated_user_can_get_me_and_logout(): void
    {
        $user = User::where('email', 'owner@dragonmart.local')->first();

        $meResponse = $this->actingAs($user)->getJson('/api/v1/auth/me');
        $meResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'email' => 'owner@dragonmart.local',
                ],
            ]);

        $logoutResponse = $this->actingAs($user)->postJson('/api/v1/auth/logout');
        $logoutResponse->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_password_update_requires_valid_current_password(): void
    {
        $user = User::where('email', 'owner@dragonmart.local')->first();

        // 1. Wrong current password fails
        $failResponse = $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'WrongPassword!',
            'password' => 'NewSecretPassword123!',
            'password_confirmation' => 'NewSecretPassword123!',
        ]);

        $failResponse->assertStatus(422);

        // 2. Correct current password succeeds
        $successResponse = $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'Password123!',
            'password' => 'NewSecretPassword123!',
            'password_confirmation' => 'NewSecretPassword123!',
        ]);

        $successResponse->assertStatus(200);
        $user->refresh();
        $this->assertTrue(Hash::check('NewSecretPassword123!', $user->password));
    }
}
