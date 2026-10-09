<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Receiving;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceivingApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected User $manager;
    protected Product $buku;
    protected Product $pulpen;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->cashier = User::where('email', 'mahengon@gmail.com')->first();
        $this->manager = User::where('email', 'manager@dragonmart.local')->first();
        $this->buku = Product::where('sku', 'BRG001')->first();
        $this->pulpen = Product::where('sku', 'BRG002')->first();
        $this->supplier = Supplier::where('code', 'SUP001')->first();
    }

    // -------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------

    public function test_manager_can_create_draft_receiving_with_lines(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/receivings', [
            'type' => 'purchase',
            'supplier_id' => $this->supplier->id,
            'external_reference' => 'PO-2026-001',
            'items' => [
                ['product_id' => $this->buku->id, 'quantity' => 10, 'unit_cost' => 3200],
                ['product_id' => $this->pulpen->id, 'quantity' => 20, 'unit_cost' => 1500],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'purchase',
                    'status' => 'draft',
                    'supplier' => ['code' => 'SUP001'],
                    'external_reference' => 'PO-2026-001',
                    'items' => [
                        ['product_id' => $this->buku->id, 'quantity' => 10, 'unit_cost' => 3200, 'extended_cost' => 32000],
                        ['product_id' => $this->pulpen->id, 'quantity' => 20, 'unit_cost' => 1500, 'extended_cost' => 30000],
                    ],
                    'totals' => [
                        'total_quantity' => 30,
                        'total_extended_cost' => 62000,
                    ],
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'id', 'receiving_number', 'created_by' => ['id', 'name'],
                    'items' => [['id', 'product_id', 'product_name', 'product_sku']],
                ],
            ]);

        $this->assertDatabaseHas('receivings', [
            'id' => $response->json('data.id'),
            'type' => 'purchase',
            'status' => 'draft',
            'supplier_id' => $this->supplier->id,
            'external_reference' => 'PO-2026-001',
            'created_by' => $this->manager->id,
        ]);

        // No stock movement on draft creation
        $this->assertEquals(0, StockMovement::where('reference_type', 'receiving')
            ->where('reference_id', $response->json('data.id'))
            ->count());
    }

    public function test_cashier_can_create_draft_but_cannot_manage_inventory(): void
    {
        // Cashier has no manage-inventory permission; create itself is allowed
        // (draft only), but approve/post/cancel should reject.
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/receivings', [
            'type' => 'purchase',
            'items' => [
                ['product_id' => $this->buku->id, 'quantity' => 5, 'unit_cost' => 3300],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson(['data' => ['status' => 'draft']]);
    }

    public function test_create_rejects_invalid_type(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/receivings', [
            'type' => 'invalid',
        ])->assertStatus(422)
            ->assertJson(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR']]);
    }

    public function test_create_rejects_unknown_supplier(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/receivings', [
            'type' => 'purchase',
            'supplier_id' => 999999,
        ])->assertStatus(422)
            ->assertJson(['error' => ['code' => 'VALIDATION_ERROR']]);
    }

    public function test_create_rejects_inactive_product(): void
    {
        $this->buku->update(['is_active' => false]);

        $this->actingAs($this->manager)->postJson('/api/v1/receivings', [
            'type' => 'purchase',
            'items' => [['product_id' => $this->buku->id, 'quantity' => 1, 'unit_cost' => 1000]],
        ])->assertStatus(422)
            ->assertJson(['error' => ['code' => 'PRODUCT_INACTIVE']]);
    }

    public function test_create_rejects_duplicate_product_in_lines(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/receivings', [
            'type' => 'purchase',
            'items' => [
                ['product_id' => $this->buku->id, 'quantity' => 1, 'unit_cost' => 1000],
                ['product_id' => $this->buku->id, 'quantity' => 2, 'unit_cost' => 1000],
            ],
        ])->assertStatus(422)
            ->assertJson(['error' => ['code' => 'RECEIVING_DUPLICATE_PRODUCT']]);
    }

    public function test_create_rejects_zero_quantity(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/receivings', [
            'type' => 'purchase',
            'items' => [['product_id' => $this->buku->id, 'quantity' => 0, 'unit_cost' => 1000]],
        ])->assertStatus(422);
    }

    public function test_create_rejects_negative_unit_cost(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/receivings', [
            'type' => 'purchase',
            'items' => [['product_id' => $this->buku->id, 'quantity' => 1, 'unit_cost' => -100]],
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // Update lines
    // -------------------------------------------------------------------

    public function test_can_replace_lines_on_draft_receiving(): void
    {
        $receiving = $this->createDraft([
            ['product_id' => $this->buku->id, 'quantity' => 5, 'unit_cost' => 3000],
        ]);

        $response = $this->actingAs($this->manager)->putJson(
            "/api/v1/receivings/{$receiving->id}/lines",
            [
                'items' => [
                    ['product_id' => $this->pulpen->id, 'quantity' => 12, 'unit_cost' => 1700],
                ],
            ]
        );

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $receiving->id,
                    'items' => [
                        ['product_id' => $this->pulpen->id, 'quantity' => 12, 'unit_cost' => 1700, 'extended_cost' => 20400],
                    ],
                ],
            ]);

        $this->assertEquals(1, \DB::table('receiving_items')->where('receiving_id', $receiving->id)->count());
    }

    public function test_cannot_edit_lines_on_approved_receiving(): void
    {
        $receiving = $this->createDraft([
            ['product_id' => $this->buku->id, 'quantity' => 5, 'unit_cost' => 3000],
        ]);
        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/approve")
            ->assertStatus(200);

        $this->actingAs($this->manager)->putJson(
            "/api/v1/receivings/{$receiving->id}/lines",
            ['items' => [['product_id' => $this->pulpen->id, 'quantity' => 1, 'unit_cost' => 100]]]
        )->assertStatus(409)
            ->assertJson(['error' => ['code' => 'RECEIVING_NOT_EDITABLE']]);
    }

    // -------------------------------------------------------------------
    // Approve / Post
    // -------------------------------------------------------------------

    public function test_manager_can_approve_then_post_incrementing_stock_and_cost(): void
    {
        $stockBefore = (int) $this->buku->stock;
        $costBefore = (float) $this->buku->average_cost;

        $receiving = $this->createDraft([
            ['product_id' => $this->buku->id, 'quantity' => 10, 'unit_cost' => 3600],
        ]);

        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/approve")
            ->assertStatus(200)
            ->assertJson(['data' => ['status' => 'approved']]);

        // No stock change yet
        $this->assertEquals($stockBefore, (int) $this->buku->fresh()->stock);

        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/post")
            ->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'posted',
                    'posted_by' => ['id' => $this->manager->id, 'name' => $this->manager->name],
                ],
            ]);

        $this->buku->refresh();
        $this->assertEquals($stockBefore + 10, (int) $this->buku->stock);

        // Moving weighted average cost: (stockBefore*costBefore + 10*3600) / (stockBefore + 10)
        $expectedAverage = (($stockBefore * $costBefore) + (10 * 3600)) / ($stockBefore + 10);
        $this->assertEqualsWithDelta(
            $expectedAverage,
            (float) $this->buku->average_cost,
            0.0001,
            'Moving weighted-average cost must be recalculated'
        );

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->buku->id,
            'reference_type' => 'receiving',
            'reference_id' => $receiving->id,
            'type' => 'receiving_purchase',
            'quantity' => 10,
            'stock_before' => $stockBefore,
            'stock_after' => $stockBefore + 10,
        ]);
    }

    public function test_cashier_cannot_approve_or_post(): void
    {
        $receiving = $this->createDraft([
            ['product_id' => $this->buku->id, 'quantity' => 5, 'unit_cost' => 3000],
        ]);

        $this->actingAs($this->cashier)->postJson("/api/v1/receivings/{$receiving->id}/approve")
            ->assertStatus(403)
            ->assertJson(['error' => ['code' => 'INVENTORY_FORBIDDEN']]);

        // Even if we manually flip to approved via DB, post must also reject.
        $receiving->update(['status' => 'approved']);
        $this->actingAs($this->cashier)->postJson("/api/v1/receivings/{$receiving->id}/post")
            ->assertStatus(403)
            ->assertJson(['error' => ['code' => 'INVENTORY_FORBIDDEN']]);
    }

    public function test_cannot_post_draft_without_approval(): void
    {
        $receiving = $this->createDraft([
            ['product_id' => $this->buku->id, 'quantity' => 1, 'unit_cost' => 1000],
        ]);

        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/post")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'RECEIVING_NOT_POSTABLE']]);
    }

    public function test_cannot_approve_empty_receiving(): void
    {
        $receiving = Receiving::create([
            'receiving_number' => 'RCV-TEST-EMPTY',
            'type' => 'purchase',
            'status' => 'draft',
            'created_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/approve")
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'RECEIVING_EMPTY']]);
    }

    public function test_cannot_double_post_same_receiving(): void
    {
        $receiving = $this->createDraft([
            ['product_id' => $this->buku->id, 'quantity' => 2, 'unit_cost' => 3500],
        ]);
        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/approve")
            ->assertStatus(200);
        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/post")
            ->assertStatus(200);

        // Second post must reject
        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/post")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'RECEIVING_NOT_POSTABLE']]);
    }

    // -------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------

    public function test_manager_can_cancel_draft(): void
    {
        $receiving = $this->createDraft([
            ['product_id' => $this->buku->id, 'quantity' => 5, 'unit_cost' => 3000],
        ]);

        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/cancel", [
            'notes' => 'Salah input supplier',
        ])->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'cancelled',
                    'correction_notes' => 'Salah input supplier',
                ],
            ]);

        $this->buku->refresh();
        $this->assertEquals(75, (int) $this->buku->stock, 'Cancel must not change stock');
    }

    public function test_cannot_cancel_posted_receiving(): void
    {
        $receiving = $this->createDraft([
            ['product_id' => $this->buku->id, 'quantity' => 1, 'unit_cost' => 1000],
        ]);
        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/approve")
            ->assertStatus(200);
        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/post")
            ->assertStatus(200);

        $this->actingAs($this->manager)->postJson("/api/v1/receivings/{$receiving->id}/cancel")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'RECEIVING_NOT_CANCELLABLE']]);
    }

    public function test_cashier_cannot_cancel(): void
    {
        $receiving = $this->createDraft([
            ['product_id' => $this->buku->id, 'quantity' => 1, 'unit_cost' => 1000],
        ]);

        $this->actingAs($this->cashier)->postJson("/api/v1/receivings/{$receiving->id}/cancel")
            ->assertStatus(403)
            ->assertJson(['error' => ['code' => 'INVENTORY_FORBIDDEN']]);
    }

    // -------------------------------------------------------------------
    // List / show / access
    // -------------------------------------------------------------------

    public function test_manager_can_list_all_receivings(): void
    {
        $this->createDraft([['product_id' => $this->buku->id, 'quantity' => 1, 'unit_cost' => 1000]]);
        $this->createDraft([['product_id' => $this->pulpen->id, 'quantity' => 1, 'unit_cost' => 1000]]);

        $response = $this->actingAs($this->manager)->getJson('/api/v1/receivings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'receiving_number', 'type', 'status', 'created_at']],
                'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']],
            ]);

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_cashier_only_sees_own_drafts(): void
    {
        // Manager creates
        $managerDraft = $this->createDraft([
            ['product_id' => $this->buku->id, 'quantity' => 1, 'unit_cost' => 1000],
        ]);

        // Cashier creates
        $cashierDraft = Receiving::create([
            'receiving_number' => 'RCV-CASH-1',
            'type' => 'purchase',
            'status' => 'draft',
            'created_by' => $this->cashier->id,
        ]);

        $response = $this->actingAs($this->cashier)->getJson('/api/v1/receivings');
        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($cashierDraft->id, $ids);
        $this->assertNotContains($managerDraft->id, $ids, 'Cashier must not see other users drafts');
    }

    public function test_show_returns_404_for_missing(): void
    {
        $this->actingAs($this->manager)->getJson('/api/v1/receivings/999999')
            ->assertStatus(404);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/receivings')->assertStatus(401);
        $this->postJson('/api/v1/receivings', [])->assertStatus(401);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    protected function createDraft(array $items): Receiving
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/receivings', [
            'type' => 'purchase',
            'items' => $items,
        ]);

        $response->assertStatus(201);

        return Receiving::with('items')->findOrFail($response->json('data.id'));
    }
}
