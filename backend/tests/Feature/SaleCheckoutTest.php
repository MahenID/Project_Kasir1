<?php

namespace Tests\Feature;

use App\Models\CheckoutQuote;
use App\Models\OperationKey;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected Terminal $terminal;
    protected Product $buku;
    protected Product $pulpen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->cashier = User::where('email', 'mahengon@gmail.com')->first();
        $this->terminal = Terminal::where('code', 'TERM-01')->first();
        $this->buku = Product::where('sku', 'BRG001')->first();   // price 5000, stock 75, cost 3500
        $this->pulpen = Product::where('sku', 'BRG002')->first(); // price 2500, stock 183, cost 1750

        $this->actingAs($this->cashier)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $this->terminal->id,
            'starting_cash' => 100000,
        ])->assertStatus(201);
    }

    protected function createQuote(array $items, array $extra = []): string
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/checkout/quotes', array_merge(
            ['items' => $items],
            $extra
        ));

        $response->assertStatus(201);

        return $response->json('data.quote_id');
    }

    protected function checkoutPayload(string $quoteId, array $overrides = []): array
    {
        return array_merge([
            'quote_id' => $quoteId,
            'payment_method' => 'cash',
            'tendered_amount' => 20000,
            'idempotency_key' => 'chk-' . uniqid(),
        ], $overrides);
    }

    public function test_cash_checkout_completes_sale_with_change_and_stock_decrement(): void
    {
        $quoteId = $this->createQuote([
            ['product_id' => $this->buku->id, 'quantity' => 2],    // 2 x 5000 = 10000
            ['product_id' => $this->pulpen->id, 'quantity' => 4],  // 4 x 2500 = 10000
        ]);

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId, ['tendered_amount' => 25000])
        );

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'totals' => ['grand_total' => 20000],
                    'payment' => [
                        'method' => 'cash',
                        'amount_due' => 20000,
                        'amount_paid' => 25000,
                        'change_amount' => 5000,
                    ],
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'id', 'receipt_number', 'completed_at',
                    'items' => [['product_id', 'name', 'quantity', 'unit_price', 'line_total']],
                ],
            ]);

        // Stock decremented
        $this->assertEquals(73, $this->buku->fresh()->stock);
        $this->assertEquals(179, $this->pulpen->fresh()->stock);

        // Immutable stock ledger recorded per line
        $sale = Sale::where('receipt_number', $response->json('data.receipt_number'))->first();
        $movements = StockMovement::where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->get();
        $this->assertCount(2, $movements);
        $this->assertTrue($movements->every(fn ($m) => $m->type === 'sale' && $m->quantity < 0));

        // Payment row exists and confirmed
        $payment = Payment::where('sale_id', $sale->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals('none', $payment->refund_status);
        $this->assertNotNull($payment->confirmed_at);

        // Quote is consumed
        $this->assertNotNull(CheckoutQuote::find($quoteId)->consumed_sale_id);

        // Receipt number format DM-YYYYMMDD-XXXXX
        $this->assertMatchesRegularExpression('/^DM-\d{8}-\d{5}$/', $sale->receipt_number);
    }

    public function test_manual_non_cash_checkout_has_zero_change(): void
    {
        $quoteId = $this->createQuote([
            ['product_id' => $this->buku->id, 'quantity' => 1], // 5000
        ]);

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId, [
                'payment_method' => 'qris',
                'tendered_amount' => null,
                'payment_reference' => 'QRIS-REF-12345',
            ])
        );

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'payment' => [
                        'method' => 'qris',
                        'amount_due' => 5000,
                        'amount_paid' => 5000,
                        'change_amount' => 0,
                        'reference_number' => 'QRIS-REF-12345',
                    ],
                ],
            ]);
    }

    public function test_cash_checkout_rejects_insufficient_tendered(): void
    {
        $quoteId = $this->createQuote([
            ['product_id' => $this->buku->id, 'quantity' => 2], // 10000
        ]);

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId, ['tendered_amount' => 5000])
        );

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'error' => ['code' => 'INSUFFICIENT_PAYMENT']]);

        // No business records committed on validation failure
        $this->assertEquals(0, Sale::count());
        $this->assertEquals(75, $this->buku->fresh()->stock);
    }

    public function test_duplicate_idempotency_key_returns_same_sale_without_double_execution(): void
    {
        $quoteId = $this->createQuote([
            ['product_id' => $this->buku->id, 'quantity' => 3], // 15000
        ]);

        $payload = $this->checkoutPayload($quoteId, [
            'tendered_amount' => 15000,
            'idempotency_key' => 'chk-retry-same-key-0001',
        ]);

        $first = $this->actingAs($this->cashier)->postJson('/api/v1/sales', $payload);
        $first->assertStatus(201);

        $second = $this->actingAs($this->cashier)->postJson('/api/v1/sales', $payload);
        $second->assertStatus(201);

        // Same sale returned both times
        $this->assertEquals($first->json('data.id'), $second->json('data.id'));
        $this->assertEquals($first->json('data.receipt_number'), $second->json('data.receipt_number'));

        // Exactly one sale and one stock decrement executed
        $this->assertEquals(1, Sale::count());
        $this->assertEquals(72, $this->buku->fresh()->stock);

        // Operation key recorded as succeeded
        $opKey = OperationKey::where('operation', 'checkout')
            ->where('key', 'chk-retry-same-key-0001')
            ->first();
        $this->assertNotNull($opKey);
        $this->assertEquals('succeeded', $opKey->status);
    }

    public function test_idempotency_conflict_when_same_key_used_with_different_payload(): void
    {
        $quoteA = $this->createQuote([['product_id' => $this->buku->id, 'quantity' => 1]]);
        $quoteB = $this->createQuote([['product_id' => $this->pulpen->id, 'quantity' => 1]]);

        $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteA, ['idempotency_key' => 'chk-conflict-key-00001'])
        )->assertStatus(201);

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteB, ['idempotency_key' => 'chk-conflict-key-00001'])
        );

        $response->assertStatus(409)
            ->assertJson(['success' => false, 'error' => ['code' => 'IDEMPOTENCY_CONFLICT']]);

        $this->assertEquals(1, Sale::count());
    }

    public function test_checkout_rejects_expired_quote(): void
    {
        $quoteId = $this->createQuote([['product_id' => $this->buku->id, 'quantity' => 1]]);

        // Force expiration
        CheckoutQuote::where('id', $quoteId)->update(['expires_at' => now('UTC')->subMinute()]);

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId)
        );

        $response->assertStatus(409)
            ->assertJson(['success' => false, 'error' => ['code' => 'QUOTE_EXPIRED']]);

        $this->assertEquals(0, Sale::count());
        $this->assertEquals(75, $this->buku->fresh()->stock);
    }

    public function test_checkout_rejects_reused_consumed_quote(): void
    {
        $quoteId = $this->createQuote([['product_id' => $this->buku->id, 'quantity' => 1]]);

        $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId)
        )->assertStatus(201);

        // A different idempotency key with the same (now consumed) quote must fail
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId)
        );

        $response->assertStatus(409)
            ->assertJson(['success' => false, 'error' => ['code' => 'QUOTE_ALREADY_CONSUMED']]);

        $this->assertEquals(1, Sale::count());
    }

    public function test_checkout_rejects_when_product_price_changed_after_quote(): void
    {
        $quoteId = $this->createQuote([['product_id' => $this->buku->id, 'quantity' => 1]]);

        // Simulate a commercial change (price update bumps commercial_version)
        $this->buku->forceFill([
            'selling_price' => 5500,
            'commercial_version' => $this->buku->commercial_version + 1,
        ])->save();

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId)
        );

        $response->assertStatus(409)
            ->assertJson(['success' => false, 'error' => ['code' => 'QUOTE_STALE']]);

        $this->assertEquals(0, Sale::count());
        $this->assertEquals(75, $this->buku->fresh()->stock);
    }

    public function test_checkout_rejects_when_stock_insufficient_at_commit_time(): void
    {
        $quoteId = $this->createQuote([['product_id' => $this->buku->id, 'quantity' => 50]]);

        // Another process drains stock between quote and checkout
        $this->buku->forceFill(['stock' => 10])->save();

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId)
        );

        $response->assertStatus(409)
            ->assertJson(['success' => false, 'error' => ['code' => 'INSUFFICIENT_STOCK']]);

        $this->assertEquals(0, Sale::count());
        $this->assertEquals(0, StockMovement::count());
    }

    public function test_checkout_requires_open_shift(): void
    {
        // Close the currently open shift
        $this->actingAs($this->cashier)->postJson('/api/v1/shifts/close', [
            'actual_cash' => 100000,
        ]);

        $quoteId = $this->createQuoteNoShiftGuard();

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId)
        );

        $response->assertStatus(409)
            ->assertJson(['success' => false, 'error' => ['code' => 'SHIFT_MISMATCH']]);
    }

    protected function createQuoteNoShiftGuard(): string
    {
        // Re-open a shift just to mint a quote, then close it again so the
        // checkout runs against a stale shift reference.
        $this->actingAs($this->cashier)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $this->terminal->id,
            'starting_cash' => 50000,
        ])->assertStatus(201);

        $quoteId = $this->createQuote([['product_id' => $this->buku->id, 'quantity' => 1]]);

        $this->actingAs($this->cashier)->postJson('/api/v1/shifts/close', [
            'actual_cash' => 50000,
        ]);

        return $quoteId;
    }

    public function test_sale_detail_and_receipt_render_from_snapshots(): void
    {
        $quoteId = $this->createQuote([['product_id' => $this->buku->id, 'quantity' => 2]]);

        $checkout = $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId, ['tendered_amount' => 10000])
        );
        $checkout->assertStatus(201);

        $saleId = $checkout->json('data.id');
        $originalName = $this->buku->name;

        // Mutate product and store settings after the sale
        $this->buku->forceFill(['name' => 'BUKU BERUBAH', 'commercial_version' => 99])->save();
        \App\Models\StoreSetting::first()->forceFill(['store_name' => 'TOKO BERUBAH'])->save();

        // Detail must still show snapshot values
        $detail = $this->actingAs($this->cashier)->getJson("/api/v1/sales/{$saleId}");
        $detail->assertStatus(200)
            ->assertJson([
                'data' => [
                    'items' => [['name' => $originalName]],
                    'totals' => ['grand_total' => 10000],
                ],
            ]);

        // Receipt must render from immutable snapshots
        $receipt = $this->actingAs($this->cashier)->getJson("/api/v1/sales/{$saleId}/receipt");
        $receipt->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['receipt_number', 'store', 'items', 'totals', 'payment', 'receipt_footer'],
            ])
            ->assertJson([
                'data' => [
                    'store' => ['store_name' => 'DragonMart POS'],
                    'items' => [['name' => $originalName]],
                ],
            ]);
    }

    public function test_checkout_validation_requires_idempotency_key_and_valid_method(): void
    {
        $quoteId = $this->createQuote([['product_id' => $this->buku->id, 'quantity' => 1]]);

        // Missing idempotency key
        $this->actingAs($this->cashier)->postJson('/api/v1/sales', [
            'quote_id' => $quoteId,
            'payment_method' => 'cash',
            'tendered_amount' => 5000,
        ])->assertStatus(422);

        // Invalid payment method
        $this->actingAs($this->cashier)->postJson('/api/v1/sales',
            $this->checkoutPayload($quoteId, ['payment_method' => 'crypto'])
        )->assertStatus(422);

        $this->assertEquals(0, Sale::count());
    }

    public function test_zero_total_cash_sale_is_allowed(): void
    {
        // Owner is not subject to the cashier discount cap, so a 100% item
        // discount can be applied to produce a zero-total sale.
        $owner = User::where('email', 'owner@dragonmart.local')->first();
        $this->actingAs($owner)->postJson('/api/v1/shifts/open', [
            'terminal_id' => $this->terminal->id,
            'starting_cash' => 50000,
        ])->assertStatus(201);

        $quoteResponse = $this->actingAs($owner)->postJson('/api/v1/checkout/quotes', [
            'items' => [
                [
                    'product_id' => $this->buku->id,
                    'quantity' => 1,
                    'discount_type' => 'percent',
                    'discount_value' => 10000, // 100.00% in basis points
                ],
            ],
        ]);
        $quoteResponse->assertStatus(201);
        $quoteId = $quoteResponse->json('data.quote_id');

        $response = $this->actingAs($owner)->postJson('/api/v1/sales', [
            'quote_id' => $quoteId,
            'payment_method' => 'cash',
            'tendered_amount' => 0,
            'idempotency_key' => 'chk-zero-total-' . uniqid(),
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'totals' => ['grand_total' => 0],
                    'payment' => [
                        'method' => 'cash',
                        'amount_due' => 0,
                        'amount_paid' => 0,
                        'change_amount' => 0,
                    ],
                ],
            ]);

        // Stock still decremented on a zero-total sale
        $this->assertEquals(74, $this->buku->fresh()->stock);
    }
}
