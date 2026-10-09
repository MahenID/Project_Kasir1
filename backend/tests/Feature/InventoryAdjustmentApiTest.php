<?php

namespace Tests\Feature;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAdjustmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $manager;
    protected User $cashier;
    protected Product $buku;
    protected Product $pulpen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Find users; create owner explicitly because the seeder only has
        // manager + cashiers as named users (owner@dragonmart.local exists too).
        $this->owner = User::where('email', 'owner@dragonmart.local')->first();
        $this->manager = User::where('email', 'manager@dragonmart.local')->first();
        $this->cashier = User::where('email', 'mahengon@gmail.com')->first();
        $this->buku = Product::where('sku', 'BRG001')->first();
        $this->pulpen = Product::where('sku', 'BRG002')->first();
    }

    // -------------------------------------------------------------------
    // Quantity adjustment
    // -------------------------------------------------------------------

    public function test_manager_can_create_and_post_quantity_adjustment_minus(): void
    {
        $stockBefore = (int) $this->buku->stock;

        $response = $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Koreksi stok opname 2026-10-09',
            'evidence_reference' => 'OPN-2026-10-09-001',
            'items' => [
                ['product_id' => $this->buku->id, 'quantity_delta' => -3],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'type' => 'quantity',
                    'status' => 'draft',
                    'items' => [[
                        'product_id' => $this->buku->id,
                        'quantity_delta' => -3,
                    ]],
                ],
            ]);

        $aid = $response->json('data.id');

        $this->actingAs($this->manager)
            ->postJson("/api/v1/inventory-adjustments/{$aid}/post")
            ->assertStatus(200)
            ->assertJson(['data' => ['status' => 'posted']]);

        $this->buku->refresh();
        $this->assertEquals($stockBefore - 3, (int) $this->buku->stock);

        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'inventory_adjustment',
            'reference_id' => $aid,
            'type' => 'adjustment_quantity',
            'product_id' => $this->buku->id,
            'quantity' => -3,
        ]);
    }

    public function test_quantity_adjustment_plus_increments_stock(): void
    {
        $stockBefore = (int) $this->buku->stock;

        $response = $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Stok ditemukan di gudang belakang',
            'items' => [
                ['product_id' => $this->buku->id, 'quantity_delta' => 5],
            ],
        ]);
        $aid = $response->json('data.id');

        $this->actingAs($this->manager)
            ->postJson("/api/v1/inventory-adjustments/{$aid}/post")
            ->assertStatus(200);

        $this->buku->refresh();
        $this->assertEquals($stockBefore + 5, (int) $this->buku->stock);
    }

    public function test_quantity_adjustment_rejects_going_below_zero(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Koreksi terlalu besar',
            'items' => [
                ['product_id' => $this->buku->id, 'quantity_delta' => -10000],
            ],
        ]);
        $aid = $response->json('data.id');

        $this->actingAs($this->manager)
            ->postJson("/api/v1/inventory-adjustments/{$aid}/post")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'INSUFFICIENT_STOCK']]);
    }

    public function test_quantity_adjustment_rejects_zero_delta(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Delta nol harus ditolak',
            'items' => [['product_id' => $this->buku->id, 'quantity_delta' => 0]],
        ])->assertStatus(422)
            ->assertJson(['error' => ['code' => 'ADJUSTMENT_ZERO_DELTA']]);
    }

    // -------------------------------------------------------------------
    // Revaluation (owner only)
    // -------------------------------------------------------------------

    public function test_owner_can_create_and_post_revaluation(): void
    {
        $costBefore = (float) $this->buku->average_cost;

        $response = $this->actingAs($this->owner)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'revaluation',
            'reason' => 'Penyesuaian harga modal dari supplier baru',
            'evidence_reference' => 'SUP-NEW-2026-10',
            'items' => [
                ['product_id' => $this->buku->id, 'new_average_cost' => 4200.50],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'type' => 'revaluation',
                    'status' => 'draft',
                    'items' => [[
                        'product_id' => $this->buku->id,
                        'new_average_cost' => 4201,  // rounded for API response
                    ]],
                ],
            ]);

        $aid = $response->json('data.id');

        $this->actingAs($this->owner)
            ->postJson("/api/v1/inventory-adjustments/{$aid}/post")
            ->assertStatus(200);

        $this->buku->refresh();
        $this->assertEquals(4200.5, (float) $this->buku->average_cost);
        $this->assertNotEquals($costBefore, (float) $this->buku->average_cost);

        // Stock must NOT change on revaluation
        $stockMovement = StockMovement::where('reference_type', 'inventory_adjustment')
            ->where('reference_id', $aid)
            ->where('type', 'adjustment_revaluation')
            ->first();
        $this->assertNotNull($stockMovement);
        $this->assertEquals(0, (int) $stockMovement->quantity);
    }

    public function test_manager_cannot_create_revaluation(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'revaluation',
            'reason' => 'Manager tidak boleh revalue',
            'items' => [
                ['product_id' => $this->buku->id, 'new_average_cost' => 5000],
            ],
        ])->assertStatus(403)
            ->assertJson(['error' => ['code' => 'REVALUATION_FORBIDDEN']]);
    }

    public function test_cashier_cannot_create_any_adjustment(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Kasir tidak boleh adjust',
            'items' => [['product_id' => $this->buku->id, 'quantity_delta' => -1]],
        ])->assertStatus(403)
            ->assertJson(['error' => ['code' => 'INVENTORY_FORBIDDEN']]);
    }

    public function test_revaluation_rejects_negative_new_cost(): void
    {
        // FormRequest rejects negative cost at validation (422 VALIDATION_ERROR).
        $this->actingAs($this->owner)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'revaluation',
            'reason' => 'Harga modal negatif harus ditolak',
            'items' => [
                ['product_id' => $this->buku->id, 'new_average_cost' => -100],
            ],
        ])->assertStatus(422)
            ->assertJson(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR']]);
    }

    // -------------------------------------------------------------------
    // State machine
    // -------------------------------------------------------------------

    public function test_cannot_double_post(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Test double post',
            'items' => [['product_id' => $this->buku->id, 'quantity_delta' => 1]],
        ]);
        $aid = $response->json('data.id');

        $this->actingAs($this->manager)
            ->postJson("/api/v1/inventory-adjustments/{$aid}/post")
            ->assertStatus(200);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/inventory-adjustments/{$aid}/post")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'ADJUSTMENT_NOT_POSTABLE']]);
    }

    public function test_can_cancel_draft(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Akan dibatalkan',
            'items' => [['product_id' => $this->buku->id, 'quantity_delta' => 1]],
        ]);
        $aid = $response->json('data.id');

        $this->actingAs($this->manager)
            ->postJson("/api/v1/inventory-adjustments/{$aid}/cancel")
            ->assertStatus(200)
            ->assertJson(['data' => ['status' => 'cancelled']]);

        $this->buku->refresh();
        // Cancel must not change stock
        $this->assertEquals(75, (int) $this->buku->stock);
    }

    public function test_cannot_cancel_posted_adjustment(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Sudah diposting',
            'items' => [['product_id' => $this->buku->id, 'quantity_delta' => 1]],
        ]);
        $aid = $response->json('data.id');
        $this->actingAs($this->manager)->postJson("/api/v1/inventory-adjustments/{$aid}/post")
            ->assertStatus(200);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/inventory-adjustments/{$aid}/cancel")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'ADJUSTMENT_NOT_CANCELLABLE']]);
    }

    public function test_empty_adjustment_cannot_post(): void
    {
        $adjustment = InventoryAdjustment::create([
            'adjustment_number' => 'ADJ-TEST-EMPTY',
            'type' => 'quantity',
            'reason' => 'Empty test',
            'status' => 'draft',
            'created_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/inventory-adjustments/{$adjustment->id}/post")
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'ADJUSTMENT_EMPTY']]);
    }

    // -------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------

    public function test_create_rejects_unknown_type(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'invalid',
            'reason' => 'Test type',
            'items' => [['product_id' => $this->buku->id, 'quantity_delta' => 1]],
        ])->assertStatus(422);
    }

    public function test_create_rejects_short_reason(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'abc',
            'items' => [['product_id' => $this->buku->id, 'quantity_delta' => 1]],
        ])->assertStatus(422);
    }

    public function test_create_rejects_duplicate_product(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Produk duplikat harus ditolak',
            'items' => [
                ['product_id' => $this->buku->id, 'quantity_delta' => 1],
                ['product_id' => $this->buku->id, 'quantity_delta' => -1],
            ],
        ])->assertStatus(422)
            ->assertJson(['error' => ['code' => 'ADJUSTMENT_DUPLICATE_PRODUCT']]);
    }

    public function test_inactive_product_rejected(): void
    {
        $this->buku->update(['is_active' => false]);

        $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Produk inactive',
            'items' => [['product_id' => $this->buku->id, 'quantity_delta' => 1]],
        ])->assertStatus(422)
            ->assertJson(['error' => ['code' => 'PRODUCT_INACTIVE']]);
    }

    // -------------------------------------------------------------------
    // List & access
    // -------------------------------------------------------------------

    public function test_list_returns_paginated(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/inventory-adjustments', [
            'type' => 'quantity',
            'reason' => 'Test list',
            'items' => [['product_id' => $this->buku->id, 'quantity_delta' => 1]],
        ]);

        $response = $this->actingAs($this->manager)->getJson('/api/v1/inventory-adjustments');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'adjustment_number', 'type', 'status', 'created_at']],
                'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']],
            ]);
    }

    public function test_show_returns_404_for_missing(): void
    {
        $this->actingAs($this->manager)->getJson('/api/v1/inventory-adjustments/999999')
            ->assertStatus(404);
    }

    public function test_unauthenticated_rejected(): void
    {
        $this->getJson('/api/v1/inventory-adjustments')->assertStatus(401);
        $this->postJson('/api/v1/inventory-adjustments', [])->assertStatus(401);
    }
}
