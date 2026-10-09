<?php

namespace Tests\Feature;

use App\Models\InventoryControl;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakeApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected User $manager;
    protected Product $buku;
    protected Product $pulpen;
    protected Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->cashier = User::where('email', 'mahengon@gmail.com')->first();
        $this->manager = User::where('email', 'manager@dragonmart.local')->first();
        $this->buku = Product::where('sku', 'BRG001')->first();
        $this->pulpen = Product::where('sku', 'BRG002')->first();
        $this->terminal = Terminal::where('code', 'TERM-01')->first();
    }

    // -------------------------------------------------------------------
    // Start
    // -------------------------------------------------------------------

    public function test_manager_can_start_stocktake_freezing_store(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/stocktakes', [
            'notes' => 'Audit triwulan',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'status' => 'counting',
                    'notes' => 'Audit triwulan',
                    'started_by' => ['id' => $this->manager->id],
                    'summary' => [
                        'total_products' => 6, // 6 active products from seeder
                    ],
                ],
            ]);

        $this->assertDatabaseHas('inventory_control', [
            'active_stocktake_id' => $response->json('data.id'),
        ]);

        // Each active product has a snapshot
        $this->assertDatabaseHas('stocktake_items', [
            'stocktake_id' => $response->json('data.id'),
            'product_id' => $this->buku->id,
            'snapshot_stock' => $this->buku->stock,
        ]);
    }

    public function test_cannot_start_when_one_already_active(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/stocktakes', [])
            ->assertStatus(201);

        $this->actingAs($this->manager)->postJson('/api/v1/stocktakes', [])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'STOCKTAKE_ALREADY_ACTIVE']]);
    }

    public function test_cashier_cannot_start_stocktake(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/v1/stocktakes', [])
            ->assertStatus(403)
            ->assertJson(['error' => ['code' => 'INVENTORY_FORBIDDEN']]);
    }

    public function test_active_endpoint_returns_active_stocktake(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/stocktakes', [])
            ->assertStatus(201);

        $response = $this->actingAs($this->manager)->getJson('/api/v1/stocktakes/active');
        $response->assertStatus(200)
            ->assertJson(['data' => ['status' => 'counting']]);
    }

    public function test_active_endpoint_returns_null_when_no_stocktake(): void
    {
        $response = $this->actingAs($this->manager)->getJson('/api/v1/stocktakes/active');
        $response->assertStatus(200)
            ->assertJson(['data' => null]);
    }

    // -------------------------------------------------------------------
    // Count
    // -------------------------------------------------------------------

    public function test_can_record_single_count(): void
    {
        $sid = $this->startStocktake();

        $this->actingAs($this->manager)->postJson(
            "/api/v1/stocktakes/{$sid}/count/{$this->buku->id}",
            ['counted_stock' => 80] // was 75, counted 80
        )->assertStatus(200)
            ->assertJson([
                'data' => [
                    'product_id' => $this->buku->id,
                    'counted_stock' => 80,
                    'difference' => 5,
                ],
            ]);
    }

    public function test_can_bulk_count(): void
    {
        $sid = $this->startStocktake();

        $this->actingAs($this->manager)->postJson(
            "/api/v1/stocktakes/{$sid}/counts",
            [
                'counts' => [
                    ['product_id' => $this->buku->id, 'counted_stock' => 75],
                    ['product_id' => $this->pulpen->id, 'counted_stock' => 200], // was 183, +17
                ],
            ]
        )->assertStatus(200)
            ->assertJson([
                'data' => [
                    'summary' => [
                        'counted' => 2,
                        'with_difference' => 1,
                    ],
                ],
            ]);
    }

    public function test_count_rejects_negative(): void
    {
        $sid = $this->startStocktake();

        // FormRequest rejects -1 at validation (422 VALIDATION_ERROR).
        $this->actingAs($this->manager)->postJson(
            "/api/v1/stocktakes/{$sid}/count/{$this->buku->id}",
            ['counted_stock' => -1]
        )->assertStatus(422)
            ->assertJson(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR']]);
    }

    public function test_count_rejects_product_not_in_stocktake(): void
    {
        $sid = $this->startStocktake();

        $this->actingAs($this->manager)->postJson(
            "/api/v1/stocktakes/{$sid}/count/999999",
            ['counted_stock' => 1]
        )->assertStatus(404);
    }

    public function test_cannot_count_after_posted(): void
    {
        $sid = $this->startStocktake();
        $this->countAllProducts($sid);
        $this->actingAs($this->manager)->postJson("/api/v1/stocktakes/{$sid}/post")
            ->assertStatus(200);

        $this->actingAs($this->manager)->postJson(
            "/api/v1/stocktakes/{$sid}/count/{$this->buku->id}",
            ['counted_stock' => 80]
        )->assertStatus(409)
            ->assertJson(['error' => ['code' => 'STOCKTAKE_NOT_COUNTING']]);
    }

    // -------------------------------------------------------------------
    // Post
    // -------------------------------------------------------------------

    public function test_post_applies_differences_and_lifts_freeze(): void
    {
        $stockBeforeBuku = (int) $this->buku->stock;
        $stockBeforePulpen = (int) $this->pulpen->stock;

        $sid = $this->startStocktake();
        $this->countAllProducts($sid, [
            $this->buku->id => 5,    // +5
            $this->pulpen->id => -3, // -3
        ]);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/stocktakes/{$sid}/post")
            ->assertStatus(200)
            ->assertJson(['data' => ['status' => 'posted']]);

        $this->buku->refresh();
        $this->pulpen->refresh();
        $this->assertEquals($stockBeforeBuku + 5, (int) $this->buku->stock);
        $this->assertEquals($stockBeforePulpen - 3, (int) $this->pulpen->stock);

        // Freeze lifted
        $control = InventoryControl::first();
        $this->assertNull($control->active_stocktake_id);

        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'stocktake',
            'reference_id' => $sid,
            'product_id' => $this->buku->id,
            'type' => 'stocktake_adjustment',
            'quantity' => 5,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'stocktake',
            'reference_id' => $sid,
            'product_id' => $this->pulpen->id,
            'type' => 'stocktake_adjustment',
            'quantity' => -3,
        ]);
    }

    public function test_post_rejects_when_uncounted_products_remain(): void
    {
        $sid = $this->startStocktake();
        // Count only one of 6 products
        $this->actingAs($this->manager)->postJson(
            "/api/v1/stocktakes/{$sid}/count/{$this->buku->id}",
            ['counted_stock' => 75]
        )->assertStatus(200);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/stocktakes/{$sid}/post")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'STOCKTAKE_INCOMPLETE']]);
    }

    public function test_post_with_no_differences_just_closes(): void
    {
        $sid = $this->startStocktake();
        $this->countAllProducts($sid);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/stocktakes/{$sid}/post")
            ->assertStatus(200);

        // No new stock movements from this stocktake
        $count = StockMovement::where('reference_type', 'stocktake')
            ->where('reference_id', $sid)
            ->count();
        $this->assertEquals(0, $count);
    }

    public function test_cannot_double_post(): void
    {
        $sid = $this->startStocktake();
        $this->countAllProducts($sid);
        $this->actingAs($this->manager)->postJson("/api/v1/stocktakes/{$sid}/post")
            ->assertStatus(200);

        $this->actingAs($this->manager)->postJson("/api/v1/stocktakes/{$sid}/post")
            ->assertStatus(409);
    }

    // -------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------

    public function test_cancel_lifts_freeze_without_stock_change(): void
    {
        $stockBefore = (int) $this->buku->stock;

        $sid = $this->startStocktake();
        $this->actingAs($this->manager)->postJson(
            "/api/v1/stocktakes/{$sid}/count/{$this->buku->id}",
            ['counted_stock' => 999]
        )->assertStatus(200);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/stocktakes/{$sid}/cancel")
            ->assertStatus(200)
            ->assertJson(['data' => ['status' => 'cancelled']]);

        $this->buku->refresh();
        $this->assertEquals($stockBefore, (int) $this->buku->stock);

        $control = InventoryControl::first();
        $this->assertNull($control->active_stocktake_id);
    }

    public function test_cannot_cancel_posted_stocktake(): void
    {
        $sid = $this->startStocktake();
        $this->countAllProducts($sid);
        $this->actingAs($this->manager)->postJson("/api/v1/stocktakes/{$sid}/post")
            ->assertStatus(200);

        $this->actingAs($this->manager)->postJson("/api/v1/stocktakes/{$sid}/cancel")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'STOCKTAKE_NOT_CANCELLABLE']]);
    }

    // -------------------------------------------------------------------
    // Inventory freeze integration
    // -------------------------------------------------------------------

    public function test_sale_checkout_rejected_during_active_stocktake(): void
    {
        // Open a shift first
        $this->actingAs($this->cashier)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $this->terminal->id,
            'starting_cash' => 100000,
        ])->assertStatus(201);

        // Start a stocktake as manager
        $this->startStocktake();

        // Try to checkout as cashier — should be frozen
        $quoteResponse = $this->actingAs($this->cashier)->postJson('/api/v1/checkout/quotes', [
            'items' => [['product_id' => $this->buku->id, 'quantity' => 1]],
        ]);
        $quoteResponse->assertStatus(201);
        $quoteId = $quoteResponse->json('data.quote_id');

        $this->actingAs($this->cashier)->postJson('/api/v1/sales', [
            'quote_id' => $quoteId,
            'payment_method' => 'cash',
            'tendered_amount' => 10000,
            'idempotency_key' => 'frozen-' . uniqid(),
        ])->assertStatus(409); // INVENTORY_FROZEN
    }

    public function test_can_start_new_stocktake_after_cancel(): void
    {
        $first = $this->startStocktake();
        $this->actingAs($this->manager)->postJson("/api/v1/stocktakes/{$first}/cancel")
            ->assertStatus(200);

        $this->actingAs($this->manager)->postJson('/api/v1/stocktakes', [])
            ->assertStatus(201);
    }

    public function test_can_start_new_stocktake_after_post(): void
    {
        $first = $this->startStocktake();
        $this->countAllProducts($first);
        $this->actingAs($this->manager)->postJson("/api/v1/stocktakes/{$first}/post")
            ->assertStatus(200);

        $this->actingAs($this->manager)->postJson('/api/v1/stocktakes', [])
            ->assertStatus(201);
    }

    // -------------------------------------------------------------------
    // Access & list
    // -------------------------------------------------------------------

    public function test_cashier_cannot_list_or_show_stocktakes(): void
    {
        $this->actingAs($this->cashier)->getJson('/api/v1/stocktakes')->assertStatus(403);
        $sid = $this->startStocktake();
        $this->actingAs($this->cashier)->getJson("/api/v1/stocktakes/{$sid}")->assertStatus(403);
    }

    public function test_show_returns_404_for_missing(): void
    {
        $this->actingAs($this->manager)->getJson('/api/v1/stocktakes/999999')
            ->assertStatus(404);
    }

    public function test_unauthenticated_rejected(): void
    {
        $this->getJson('/api/v1/stocktakes')->assertStatus(401);
        $this->postJson('/api/v1/stocktakes', [])->assertStatus(401);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    protected function startStocktake(): int
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/stocktakes', []);
        $response->assertStatus(201);
        return $response->json('data.id');
    }

    /**
     * Count every active product. By default uses the current stock as
     * the counted value (no difference). $adjustments is an optional
     * [product_id => delta] map to add to the current stock.
     */
    protected function countAllProducts(int $stocktakeId, array $adjustments = []): void
    {
        $allProducts = Product::where('is_active', true)->get();
        foreach ($allProducts as $p) {
            $counted = (int) $p->stock + (int) ($adjustments[$p->id] ?? 0);
            $this->actingAs($this->manager)->postJson(
                "/api/v1/stocktakes/{$stocktakeId}/count/{$p->id}",
                ['counted_stock' => $counted]
            )->assertStatus(200);
        }
    }
}
