<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryControl;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\StoreSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected User $manager;
    protected User $other;
    protected Shift $shift;
    protected Sale $sale;
    protected SaleItem $item;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, StoreSettingSeeder::class]);
        InventoryControl::firstOrCreate(['id' => 1], ['active_stocktake_id' => null]);
        $this->cashier = $this->makeUser('cashier');
        $this->manager = $this->makeUser('manager');
        $this->other = $this->makeUser('cashier');
        $terminal = Terminal::create(['code' => 'RETURN-TEST', 'name' => 'Return test', 'is_active' => true]);
        $originalShift = Shift::create(['user_id' => $this->other->id, 'terminal_id' => $terminal->id, 'opened_at' => now()->subHours(2), 'closed_at' => now()->subHour(), 'starting_cash' => 0, 'status' => 'closed']);
        $this->shift = Shift::create(['user_id' => $this->cashier->id, 'terminal_id' => $terminal->id, 'opened_at' => now(), 'starting_cash' => 20000, 'status' => 'open']);
        $category = Category::create(['name' => 'Return fixture', 'slug' => 'return-fixture', 'is_active' => true]);
        $this->product = Product::create(['sku' => 'RETURN-TEST', 'name' => 'Return item', 'category_id' => $category->id, 'unit' => 'pcs', 'selling_price' => 4000, 'average_cost' => '2000.000000', 'stock' => 10]);
        $this->sale = Sale::create(['receipt_number' => 'RETURN-EXACT-001', 'user_id' => $this->other->id, 'shift_id' => $originalShift->id, 'terminal_id' => $terminal->id, 'cashier_name_snapshot' => $this->other->name, 'terminal_code_snapshot' => $terminal->code, 'store_snapshot' => [], 'customer_snapshot' => ['name' => 'Private customer', 'phone' => 'private-phone', 'address' => 'private-address'], 'subtotal' => 12000, 'sale_discount_amount' => 2000, 'net_total' => 10000, 'grand_total' => 10000, 'total_cost' => '6000.000000', 'completed_at' => now()->subHour()]);
        $this->item = SaleItem::create(['sale_id' => $this->sale->id, 'product_id' => $this->product->id, 'sku_snapshot' => $this->product->sku, 'name_snapshot' => $this->product->name, 'unit_snapshot' => 'pcs', 'quantity' => 3, 'unit_price' => 4000, 'cost_price_snapshot' => '2000.000000', 'gross_amount' => 12000, 'sale_discount_allocation' => 2000, 'net_amount' => 10000, 'tax_amount' => 0, 'subtotal' => 10000, 'extended_cost' => '6000.000000']);
        Payment::create(['sale_id' => $this->sale->id, 'shift_id' => $originalShift->id, 'method' => 'cash', 'amount_due' => 10000, 'amount_paid' => 10000, 'change_amount' => 0, 'confirmed_by' => $this->other->id, 'confirmed_at' => now()->subHour()]);
    }

    protected function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        return $user;
    }

    protected function createRequest(array $lines = []): int
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/return-requests', ['sale_id' => $this->sale->id, 'reason' => 'Customer return', 'lines' => $lines ?: [$this->line(1)]]);
        $response->assertCreated();
        return $response->json('data.id');
    }

    protected function line(int $quantity, string $disposition = 'restockable'): array
    {
        return ['sale_item_id' => $this->item->id, 'quantity' => $quantity, 'disposition' => $disposition, 'reason' => 'Customer return'];
    }

    protected function approve(int $id): void
    {
        $this->actingAs($this->manager)->postJson("/api/v1/return-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    protected function completion(int $amount): array
    {
        return ['shift_id' => $this->shift->id, 'confirmed_cash_amount' => $amount];
    }

    public function test_successive_unit_returns_allocate_exactly_and_use_current_shift(): void
    {
        foreach ([3333, 3334, 3333] as $amount) {
            $id = $this->createRequest();
            $this->approve($id);
            $this->actingAs($this->cashier)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/return-requests/{$id}/complete", $this->completion($amount))->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.refund.amount', (string) $amount);
            $this->assertDatabaseHas('refunds', ['return_request_id' => $id, 'handling_shift_id' => $this->shift->id, 'amount' => $amount]);
        }
        $this->assertSame(13, $this->product->fresh()->stock);
        $this->assertSame('full', $this->sale->fresh()->return_status);
        $this->assertSame(10000, $this->sale->fresh()->grand_total);
        $this->assertDatabaseCount('refunds', 3);
    }

    public function test_split_dispositions_share_quantity_and_only_restock_sellable_units(): void
    {
        $id = $this->createRequest([$this->line(1), $this->line(2, 'damaged')]);
        $this->approve($id);
        $this->actingAs($this->cashier)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/return-requests/{$id}/complete", $this->completion(10000))->assertOk()->assertJsonPath('data.refund.amount', '10000');
        $this->assertSame(11, $this->product->fresh()->stock);
        $this->assertDatabaseCount('return_items', 2);
        $this->actingAs($this->cashier)->postJson('/api/v1/return-requests', ['sale_id' => $this->sale->id, 'reason' => 'Over return', 'lines' => [$this->line(1)]])->assertStatus(422);
    }

    public function test_exact_cross_cashier_lookup_and_owned_request_responses_are_private(): void
    {
        $response = $this->actingAs($this->cashier)->getJson('/api/v1/return-requests/lookup?receipt_number=RETURN-EXACT-001')->assertOk();
        $this->assertStringNotContainsString('private-phone', $response->getContent());
        $this->assertStringNotContainsString('private-address', $response->getContent());
        $this->assertStringNotContainsString('cost_price', $response->getContent());
        $this->actingAs($this->cashier)->getJson('/api/v1/return-requests/lookup?receipt_number=RETURN-EXACT')->assertNotFound();
        $id = $this->createRequest();
        $response = $this->actingAs($this->cashier)->getJson("/api/v1/return-requests/{$id}")->assertOk();
        $this->assertStringNotContainsString('cost_reversed', $response->getContent());
        $this->actingAs($this->other)->getJson("/api/v1/return-requests/{$id}")->assertForbidden();
        $this->actingAs($this->other)->getJson('/api/v1/return-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->other)->postJson("/api/v1/return-requests/{$id}/cancel", ['reason' => 'Not mine'])->assertForbidden();
        $this->actingAs($this->cashier)->postJson("/api/v1/return-requests/{$id}/approve")->assertForbidden();
        $this->actingAs($this->manager)->getJson('/api/v1/return-requests')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_completion_retries_recover_and_reject_changed_payload_or_request(): void
    {
        $id = $this->createRequest();
        $this->approve($id);
        $key = (string) Str::uuid();
        $response = $this->actingAs($this->cashier)->withHeader('Idempotency-Key', $key)->postJson("/api/v1/return-requests/{$id}/complete", $this->completion(3333))->assertOk();
        $retry = $this->postJson("/api/v1/return-requests/{$id}/complete", $this->completion(3333))->assertOk();
        $this->assertSame(
            self::canonicalize($response->json('data')),
            self::canonicalize($retry->json('data')),
            'Retry idempoten harus mengembalikan data yang sama secara semantik (urutan key JSON tidak material).'
        );
        $this->postJson("/api/v1/return-requests/{$id}/complete", $this->completion(3334))->assertStatus(409);
        $otherId = $this->createRequest();
        $this->approve($otherId);
        $this->actingAs($this->cashier)->withHeader('Idempotency-Key', $key)->postJson("/api/v1/return-requests/{$otherId}/complete", $this->completion(3333))->assertStatus(409);
        $this->getJson("/api/v1/operations/refund-complete/{$key}")->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.id', $id);
        $this->actingAs($this->other)->getJson("/api/v1/operations/refund-complete/{$key}")->assertOk()->assertJsonPath('data.status', 'absent');
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_expired_approval_requires_authenticated_reapproval(): void
    {
        $id = $this->createRequest();
        $this->approve($id);
        $this->travel(25)->hours();
        $this->actingAs($this->cashier)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/return-requests/{$id}/complete", $this->completion(3333))->assertStatus(409);
        $this->approve($id);
        $this->actingAs($this->cashier)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/return-requests/{$id}/complete", $this->completion(3333))->assertOk();
    }

    public function test_validation_and_shift_ownership_cannot_be_bypassed(): void
    {
        $line = $this->line(1);
        $line['quantity'] = 1.5;
        $this->actingAs($this->cashier)->postJson('/api/v1/return-requests', ['sale_id' => $this->sale->id, 'reason' => 'Return', 'lines' => [$line]])->assertStatus(422);
        $id = $this->createRequest();
        $this->actingAs($this->cashier)->withHeader('Idempotency-Key', 'not-a-uuid')->postJson("/api/v1/return-requests/{$id}/complete", $this->completion(3333))->assertStatus(422);
        $this->approve($id);
        $this->actingAs($this->cashier)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/return-requests/{$id}/complete", ['shift_id' => $this->sale->shift_id, 'confirmed_cash_amount' => 3333])->assertStatus(409);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/return-requests/{$id}/complete", $this->completion(3333) + ['return_request_id' => $id + 1])->assertStatus(422);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/return-requests/{$id}/complete", $this->completion(3334))->assertStatus(422);
        $this->assertDatabaseCount('refunds', 0);
        $this->putJson("/api/v1/return-requests/{$id}", ['reason' => 'Edit'])->assertStatus(405);
        $this->getJson('/api/v1/operations/arbitrary/'.Str::uuid())->assertStatus(422);
    }

    public function test_rejection_and_cancellation_are_terminal(): void
    {
        $id = $this->createRequest();
        $this->actingAs($this->manager)->postJson("/api/v1/return-requests/{$id}/reject", ['reason' => 'Not eligible'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/v1/return-requests/{$id}/approve")->assertStatus(409);
        $cancelled = $this->createRequest();
        $this->approve($cancelled);
        $this->actingAs($this->cashier)->postJson("/api/v1/return-requests/{$cancelled}/cancel", ['reason' => 'Changed disposition'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/return-requests/{$cancelled}/complete", $this->completion(3333))->assertStatus(409);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_noncash_refund_requires_manual_confirmation(): void
    {
        $this->sale->payment->update(['method' => 'qris', 'reference_number' => 'ORIGINAL-PAYMENT']);
        $id = $this->createRequest();
        $this->approve($id);
        $payload = ['shift_id' => $this->shift->id, 'refund_reference' => 'REFUND-REF'];
        $this->actingAs($this->cashier)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/return-requests/{$id}/complete", $payload)->assertStatus(422);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/return-requests/{$id}/complete", $payload + ['non_cash_confirmed' => true])->assertOk()->assertJsonPath('data.refund.method', 'qris');
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    /**
     * Kanonikalisasi array asosiatif secara rekursif untuk membandingkan struktur
     * JSON yang setara secara semantik (urutan key tidak material karena objek
     * JSON tidak berurutan). Kolom JSON MySQL menormalisasi urutan kunci saat
     * menyimpan dan membacanya kembali, sehingga respons idempoten replay
     * (yang dipulihkan dari response_snapshot) akan memiliki urutan key yang
     * berbeda dari respons eksekusi pertama walau isinya identik.
     *
     * @param mixed $value
     * @return mixed
     */
    protected static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        foreach ($value as $key => $v) {
            $value[$key] = self::canonicalize($v);
        }
        return $value;
    }
}
