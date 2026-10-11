<?php

namespace Tests\Feature;

use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerminalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    protected function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Endpoint butuh autentikasi (first-party Sanctum). */
    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/terminals')->assertUnauthorized();
    }

    /** Semua peran terautentikasi (termasuk kasir) boleh melihat daftar terminal. */
    public function test_all_roles_can_list_active_terminals(): void
    {
        Terminal::create(['code' => 'TERM-01', 'name' => 'Terminal Kasir 01', 'is_active' => true]);
        Terminal::create(['code' => 'TERM-02', 'name' => 'Terminal Kasir 02', 'is_active' => true]);
        Terminal::create(['code' => 'TERM-99', 'name' => 'Terminal Lama', 'is_active' => false]);

        foreach (['cashier', 'manager', 'owner'] as $role) {
            $response = $this->actingAs($this->makeUser($role))->getJson('/api/v1/terminals');
            $response->assertOk();

            $data = $response->json('data');
            // Default hanya terminal aktif, terurut by code.
            $this->assertSame(['TERM-01', 'TERM-02'], array_column($data, 'code'));
            $this->assertSame(['id', 'code', 'name', 'is_active'], array_keys($data[0]));
            $this->assertTrue($data[0]['is_active']);
        }
    }

    /** active_only=false mengembalikan seluruh terminal termasuk non-aktif. */
    public function test_active_only_false_includes_inactive_terminals(): void
    {
        Terminal::create(['code' => 'TERM-01', 'name' => 'Terminal Kasir 01', 'is_active' => true]);
        Terminal::create(['code' => 'TERM-99', 'name' => 'Terminal Lama', 'is_active' => false]);

        $active = $this->actingAs($this->makeUser('cashier'))
            ->getJson('/api/v1/terminals');
        $this->assertSame(['TERM-01'], array_column($active->json('data'), 'code'));

        $all = $this->actingAs($this->makeUser('cashier'))
            ->getJson('/api/v1/terminals?active_only=false');

        $all->assertOk();
        $codes = array_column($all->json('data'), 'code');
        sort($codes);

        $this->assertSame(['TERM-01', 'TERM-99'], $codes);
    }

    /** Terminal non-aktif tetap boleh diperiksa saat re-approval shift lama. */
    public function test_default_hides_inactive_terminals(): void
    {
        Terminal::create(['code' => 'TERM-99', 'name' => 'Terminal Lama', 'is_active' => false]);

        $response = $this->actingAs($this->makeUser('manager'))->getJson('/api/v1/terminals');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }
}
