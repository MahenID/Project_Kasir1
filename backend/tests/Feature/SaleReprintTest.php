<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleReprint;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SaleReprintTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected User $manager;
    protected Terminal $terminal;
    protected Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->cashier = User::where('email', 'mahengon@gmail.com')->first();
        $this->manager = User::where('email', 'manager@dragonmart.local')->first();
        $this->terminal = Terminal::where('code', 'TERM-01')->first();
        $buku = \App\Models\Product::where('sku', 'BRG001')->first();

        // Open shift for the cashier
        $this->actingAs($this->cashier)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $this->terminal->id,
            'starting_cash' => 100000,
        ])->assertStatus(201);

        // Create a sale to reprint
        $quoteResponse = $this->actingAs($this->cashier)->postJson('/api/v1/checkout/quotes', [
            'items' => [['product_id' => $buku->id, 'quantity' => 1]],
        ]);
        $quoteResponse->assertStatus(201);

        $checkout = $this->actingAs($this->cashier)->postJson('/api/v1/sales', [
            'quote_id' => $quoteResponse->json('data.quote_id'),
            'payment_method' => 'cash',
            'tendered_amount' => 10000,
            'idempotency_key' => 'reprint-seed-' . uniqid(),
        ]);
        $checkout->assertStatus(201);

        $this->sale = Sale::where('receipt_number', $checkout->json('data.receipt_number'))->first();
    }

    public function test_cashier_can_reprint_own_sale_with_copy_flag_and_audit_row(): void
    {
        $response = $this->actingAs($this->cashier)->postJson(
            "/api/v1/sales/{$this->sale->id}/reprints",
            ['reason' => 'Kertas struk robek']
        );

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_copy' => true,
                    'copy_label' => 'SALINAN',
                    'receipt_number' => $this->sale->receipt_number,
                    'reprint' => [
                        'reprinted_by_user_id' => $this->cashier->id,
                        'reprinted_by_name' => $this->cashier->name,
                        'reason' => 'Kertas struk robek',
                    ],
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'is_copy', 'copy_label', 'reprint' => ['id', 'reprinted_at'],
                    'items', 'totals', 'payment', 'store', 'cashier_name',
                ],
            ]);

        $this->assertDatabaseHas('sale_reprints', [
            'sale_id' => $this->sale->id,
            'reprinted_by_user_id' => $this->cashier->id,
            'reason' => 'Kertas struk robek',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sales',
            'subject_type' => Sale::class,
            'subject_id' => $this->sale->id,
            'causer_id' => $this->cashier->id,
        ]);
    }

    public function test_reprint_does_not_mutate_parent_sale_or_stock(): void
    {
        $saleBefore = $this->sale->replicate(['created_at', 'updated_at']);
        $saleBefore->id = $this->sale->id;
        $saleBeforeArray = $saleBefore->toArray();

        $this->actingAs($this->cashier)->postJson(
            "/api/v1/sales/{$this->sale->id}/reprints"
        )->assertStatus(201);

        $this->sale->refresh();
        $this->assertEquals(
            $saleBeforeArray['grand_total'],
            $this->sale->grand_total,
            'Grand total must not change after reprint'
        );
        $this->assertEquals(
            $saleBeforeArray['total_cost'],
            (string) $this->sale->total_cost,
            'Total cost must not change after reprint'
        );

        // Reprint should not introduce additional stock movements
        $movements = \App\Models\StockMovement::where('reference_type', 'sale')
            ->where('reference_id', $this->sale->id)
            ->count();
        $this->assertEquals(1, $movements, 'No new stock movements on reprint');
    }

    public function test_reprint_records_source_ip_and_optional_reason(): void
    {
        $response = $this->actingAs($this->cashier)->postJson(
            "/api/v1/sales/{$this->sale->id}/reprints"
        );

        $response->assertStatus(201);

        $reprint = SaleReprint::where('sale_id', $this->sale->id)->latest('id')->first();
        $this->assertNotNull($reprint->source_ip);
        $this->assertNotNull($reprint->reprinted_at);
        $this->assertNull($reprint->reason);
    }

    public function test_cashier_cannot_reprint_another_cashier_sale(): void
    {
        // Create a second cashier
        $otherCashier = User::create([
            'name' => 'Kasir Dua',
            'email' => 'kasirdua@dragonmart.local',
            'phone' => '+6281200000002',
            'password' => bcrypt('Password123!'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $otherCashier->assignRole('cashier');

        $this->actingAs($otherCashier)->postJson(
            "/api/v1/sales/{$this->sale->id}/reprints"
        )->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'REPRINT_FORBIDDEN'],
            ]);

        $this->assertDatabaseMissing('sale_reprints', [
            'sale_id' => $this->sale->id,
            'reprinted_by_user_id' => $otherCashier->id,
        ]);
    }

    public function test_manager_can_reprint_any_sale(): void
    {
        $this->actingAs($this->manager)->postJson(
            "/api/v1/sales/{$this->sale->id}/reprints",
            ['reason' => 'Audit bulanan']
        )->assertStatus(201)
            ->assertJson([
                'data' => [
                    'is_copy' => true,
                    'reprint' => [
                        'reprinted_by_user_id' => $this->manager->id,
                        'reason' => 'Audit bulanan',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('sale_reprints', [
            'sale_id' => $this->sale->id,
            'reprinted_by_user_id' => $this->manager->id,
            'reason' => 'Audit bulanan',
        ]);
    }

    public function test_reprint_returns_404_for_missing_sale(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/v1/sales/999999/reprints')
            ->assertStatus(404);
    }

    public function test_reprint_rejects_reason_longer_than_100_chars(): void
    {
        $this->actingAs($this->cashier)->postJson(
            "/api/v1/sales/{$this->sale->id}/reprints",
            ['reason' => str_repeat('a', 101)]
        )->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR'],
            ]);
    }
}
