<?php

namespace Tests\Feature;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InventoryFrozenException;
use App\Models\InventoryControl;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\Terminal;
use App\Models\User;
use App\Services\IdempotencyService;
use App\Services\StockService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAndIdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_stock_service_locks_and_validates_stock_sufficiency(): void
    {
        $stockService = app(StockService::class);
        $buku = Product::where('sku', 'BRG001')->first(); // Stock = 75

        // 1. Sufficient stock request succeeds
        $locked = $stockService->lockAndVerifyStock([$buku->id => 10]);
        $this->assertTrue($locked->has($buku->id));

        // 2. Insufficient stock request throws InsufficientStockException
        $this->expectException(InsufficientStockException::class);
        $stockService->lockAndVerifyStock([$buku->id => 100]);
    }

    public function test_inventory_freeze_guard_blocks_stock_mutations_during_stocktake(): void
    {
        $stockService = app(StockService::class);
        $owner = User::where('email', 'owner@dragonmart.local')->first();
        $buku = Product::where('sku', 'BRG001')->first();

        // 1. Declare active stocktake
        $stocktake = Stocktake::create([
            'stocktake_number' => 'STK-TEST-001',
            'status' => 'counting',
            'started_by' => $owner->id,
            'started_at' => now(),
        ]);

        $control = InventoryControl::firstOrCreate(['id' => 1]);
        $control->update(['active_stocktake_id' => $stocktake->id]);

        // 2. Any stock check or mutation throws InventoryFrozenException
        $this->expectException(InventoryFrozenException::class);
        $stockService->lockAndVerifyStock([$buku->id => 1]);
    }

    public function test_stock_decrement_and_increment_record_immutable_ledger(): void
    {
        $stockService = app(StockService::class);
        $owner = User::where('email', 'owner@dragonmart.local')->first();
        $buku = Product::where('sku', 'BRG001')->first(); // 75 units, cost 3500.000000

        // Decrement 5 units
        $movement = $stockService->decrementStock(
            $buku,
            5,
            'sale',
            'sale',
            999,
            null,
            $owner->id,
            'Penjualan kasir test'
        );

        $this->assertEquals(-5, $movement->quantity);
        $this->assertEquals(75, $movement->stock_before);
        $this->assertEquals(70, $movement->stock_after);

        $buku->refresh();
        $this->assertEquals(70, $buku->stock);

        // Increment 30 units @ Rp 4,000
        // New cost = ((70 * 3500) + (30 * 4000)) / (70 + 30) = (245,000 + 120,000) / 100 = 3,650.000000
        $incMovement = $stockService->incrementStock(
            $buku,
            30,
            '4000.000000',
            'receiving_purchase',
            'receiving',
            888,
            null,
            $owner->id,
            'Penerimaan barang'
        );

        $this->assertEquals(30, $incMovement->quantity);
        $this->assertEquals(70, $incMovement->stock_before);
        $this->assertEquals(100, $incMovement->stock_after);
        $this->assertEquals('3650.000000', (string) $incMovement->cost_after);

        $buku->refresh();
        $this->assertEquals(100, $buku->stock);
        $this->assertEquals('3650.000000', (string) $buku->average_cost);
    }

    public function test_idempotency_service_caches_success_and_detects_payload_conflict(): void
    {
        $idempotency = app(IdempotencyService::class);
        $owner = User::where('email', 'owner@dragonmart.local')->first();

        $key = 'test-key-uuid-12345';
        $payload1 = ['item_id' => 1, 'qty' => 5];
        $executionCount = 0;

        // 1. First execution
        $res1 = $idempotency->execute($owner->id, 'test_op', $key, $payload1, function () use (&$executionCount) {
            $executionCount++;
            return ['order_number' => 'ORD-001', 'total' => 50000];
        });

        $this->assertEquals(1, $executionCount);
        $this->assertEquals('ORD-001', $res1['order_number']);

        // 2. Second execution with SAME payload returns cached result without re-executing callback
        $res2 = $idempotency->execute($owner->id, 'test_op', $key, $payload1, function () use (&$executionCount) {
            $executionCount++;
            return ['order_number' => 'ORD-002', 'total' => 99999];
        });

        $this->assertEquals(1, $executionCount);
        $this->assertEquals('ORD-001', $res2['order_number']);

        // 3. Execution with SAME key but DIFFERENT payload throws IdempotencyConflictException
        $payloadConflict = ['item_id' => 1, 'qty' => 999]; // Altered quantity
        $this->expectException(IdempotencyConflictException::class);

        $idempotency->execute($owner->id, 'test_op', $key, $payloadConflict, function () {
            return [];
        });
    }

    public function test_quote_endpoint_enforces_cashier_discount_limit(): void
    {
        $cashier = User::where('email', 'mahengon@gmail.com')->first();
        $terminal = Terminal::where('code', 'TERM-01')->first();
        $buku = Product::where('sku', 'BRG001')->first();

        // 1. Cashier opens shift
        $this->actingAs($cashier)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $terminal->id,
            'starting_cash' => 100000,
        ])->assertStatus(201);

        // 2. Cashier requests quote with 5% item discount (500 bps) -> within 10% limit -> SUCCESS
        $okResponse = $this->actingAs($cashier)->postJson('/api/v1/checkout/quotes', [
            'items' => [
                [
                    'product_id' => $buku->id,
                    'quantity' => 2,
                    'discount_type' => 'percent',
                    'discount_value' => 500,
                ],
            ],
        ]);
        $okResponse->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'calculation' => [
                        'gross_total' => 10000,
                        'item_discount_total' => 500,
                        'net_total' => 9500,
                    ],
                ],
            ]);

        // 3. Cashier requests quote with 15% item discount (1,500 bps) -> exceeds 10% limit -> REJECTED
        $failResponse = $this->actingAs($cashier)->postJson('/api/v1/checkout/quotes', [
            'items' => [
                [
                    'product_id' => $buku->id,
                    'quantity' => 2,
                    'discount_type' => 'percent',
                    'discount_value' => 1500,
                ],
            ],
        ]);
        $failResponse->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'DISCOUNT_LIMIT_EXCEEDED',
                ],
            ]);
    }
}
