<?php

namespace Tests\Feature;

use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_cashier_can_open_shift_and_view_x_report(): void
    {
        $cashier = User::where('email', 'mahengon@gmail.com')->first();
        $terminal = Terminal::where('code', 'TERM-01')->first();

        // 1. Open shift
        $response = $this->actingAs($cashier)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $terminal->id,
            'starting_cash' => 200000,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'shift' => [
                        'status' => 'open',
                        'starting_cash' => 200000,
                    ],
                ],
            ]);

        // 2. View current shift X-report preview
        $currentResponse = $this->actingAs($cashier)->getJson('/api/v1/shifts/current');
        $currentResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'x_report' => [
                        'starting_cash' => 200000,
                        'expected_cash' => 200000,
                    ],
                ],
            ]);
    }

    public function test_concurrent_or_duplicate_open_shift_is_rejected(): void
    {
        $cashier1 = User::where('email', 'mahengon@gmail.com')->first();
        $cashier2 = User::where('email', 'munyukgelok@gmail.com')->first();
        $terminal = Terminal::where('code', 'TERM-01')->first();

        // Cashier 1 opens shift on TERM-01
        $this->actingAs($cashier1)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $terminal->id,
            'starting_cash' => 100000,
        ])->assertStatus(201);

        // Cashier 1 tries to open another shift -> rejected (SHIFT_ALREADY_OPEN)
        $dupUserResponse = $this->actingAs($cashier1)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $terminal->id,
            'starting_cash' => 100000,
        ]);
        $dupUserResponse->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'SHIFT_ALREADY_OPEN',
                ],
            ]);

        // Cashier 2 tries to open shift on SAME terminal -> rejected (TERMINAL_IN_USE)
        $dupTerminalResponse = $this->actingAs($cashier2)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $terminal->id,
            'starting_cash' => 100000,
        ]);
        $dupTerminalResponse->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'TERMINAL_IN_USE',
                ],
            ]);
    }

    public function test_manager_can_record_cash_movements_and_close_shift_with_z_report(): void
    {
        $owner = User::where('email', 'owner@dragonmart.local')->first();
        $terminal = Terminal::where('code', 'TERM-01')->first();

        // 1. Open shift
        $openResponse = $this->actingAs($owner)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $terminal->id,
            'starting_cash' => 500000,
        ]);
        $shiftId = $openResponse->json('data.shift.id');

        // 2. Record cash_in Rp 100,000
        $cashInResponse = $this->actingAs($owner)->postJson("/api/v1/shifts/{$shiftId}/cash-movements", [
            'type' => 'cash_in',
            'amount' => 100000,
            'reason' => 'Tambah uang kembalian pecahan kecil',
        ]);
        $cashInResponse->assertStatus(201);

        // 3. Expected cash is now 500,000 + 100,000 = 600,000
        $currentResponse = $this->actingAs($owner)->getJson('/api/v1/shifts/current');
        $this->assertEquals(600000, $currentResponse->json('data.x_report.expected_cash'));

        // 4. Record cash_out exceeding drawer is rejected
        $excessCashOut = $this->actingAs($owner)->postJson("/api/v1/shifts/{$shiftId}/cash-movements", [
            'type' => 'cash_out',
            'amount' => 700000,
            'reason' => 'Tarik uang berlebih',
        ]);
        $excessCashOut->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'CASH_OUT_EXCEEDS_DRAWER',
                ],
            ]);

        // 5. Close shift with cash difference requires explanation
        $closeFailResponse = $this->actingAs($owner)->postJson('/api/v1/shifts/close', [
            'actual_cash' => 550000, // Shortage of 50,000 without explanation
        ]);
        $closeFailResponse->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'EXPLANATION_REQUIRED',
                ],
            ]);

        // 6. Close shift with explanation succeeds and generates Z-report
        $closeSuccessResponse = $this->actingAs($owner)->postJson('/api/v1/shifts/close', [
            'actual_cash' => 550000,
            'explanation' => 'Selisih Rp 50.000 karena salah kembalian saat transaksi ramai',
        ]);
        $closeSuccessResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'shift' => [
                        'status' => 'closed',
                        'expected_cash' => 600000,
                        'actual_cash' => 550000,
                        'difference' => -50000,
                    ],
                ],
            ]);
    }
}
