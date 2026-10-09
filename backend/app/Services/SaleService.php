<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Exceptions\QuoteExpiredException;
use App\Models\CheckoutQuote;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StoreSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class SaleService
{
    /** Maximum whole-sale grand total in integer rupiah (PRD §5.1). */
    public const MAX_SALE_TOTAL = 1_000_000_000;

    public function __construct(
        protected StockService $stockService,
        protected PaymentService $paymentService,
        protected IdempotencyService $idempotencyService,
    ) {}

    /**
     * Finalize a sale atomically from a server quote (PRD §5.3).
     *
     * Flow: consume quote -> re-validate commercial versions -> lock stock ->
     * write sale + items + payment + stock ledger -> commit. Wrapped in durable
     * idempotency so a client retry returns the same sale without re-execution.
     *
     * @param User $user Cashier / owner performing the sale
     * @param string $quoteId UUID of the server quote
     * @param string $paymentMethod 'cash' or manual non-cash method
     * @param int|null $tenderedAmount Rupiah handed over (cash only)
     * @param string|null $paymentReference Manual non-cash reference
     * @param string $idempotencyKey Client-supplied durable key
     * @param array $canonicalPayload Canonical request payload for idempotency hashing
     * @return array The idempotent response snapshot
     */
    public function checkout(
        User $user,
        string $quoteId,
        string $paymentMethod,
        ?int $tenderedAmount,
        ?string $paymentReference,
        string $idempotencyKey,
        array $canonicalPayload
    ): array {
        return $this->idempotencyService->execute(
            $user->id,
            'checkout',
            $idempotencyKey,
            $canonicalPayload,
            fn () => $this->finalizeSale(
                $user,
                $quoteId,
                $paymentMethod,
                $tenderedAmount,
                $paymentReference
            )
        );
    }

    /**
     * The atomic transactional body of a checkout. Returns the callback payload
     * expected by IdempotencyService (resource_type, resource_id, response).
     *
     * @throws Throwable
     */
    protected function finalizeSale(
        User $user,
        string $quoteId,
        string $paymentMethod,
        ?int $tenderedAmount,
        ?string $paymentReference
    ): array {
        // Retry on deadlock / lock wait timeout (MySQL 1213 / 1205).
        return DB::transaction(function () use ($user, $quoteId, $paymentMethod, $tenderedAmount, $paymentReference) {
            // 1. Inventory freeze guard: reject if a stocktake pause is active.
            $this->stockService->checkInventoryGuard();

            // 2. Load and lock the quote row; verify ownership and freshness.
            $quote = CheckoutQuote::where('id', $quoteId)->lockForUpdate()->first();

            if (!$quote) {
                throw new DomainException('Kutipan checkout tidak ditemukan.', 'QUOTE_NOT_FOUND', 404);
            }

            if ((int) $quote->user_id !== (int) $user->id) {
                throw new DomainException(
                    'Kutipan ini milik pengguna lain dan tidak dapat Anda proses.',
                    'QUOTE_FORBIDDEN',
                    403
                );
            }

            if ($quote->consumed_sale_id !== null) {
                throw new DomainException(
                    'Kutipan ini sudah digunakan untuk transaksi lain.',
                    'QUOTE_ALREADY_CONSUMED',
                    409
                );
            }

            if (Carbon::now('UTC')->greaterThan($quote->expires_at)) {
                throw new QuoteExpiredException(
                    'Kutipan harga telah kedaluwarsa (berlaku 120 detik). Silakan hitung ulang.'
                );
            }

            // 3. Verify the cashier still has the same open shift.
            //    Query fresh (not the cached relation) so a shift closed/re-opened
            //    since the quote was minted is detected correctly.
            $shift = $user->currentOpenShift()->first();
            if (!$shift || (int) $shift->id !== (int) $quote->shift_id) {
                throw new DomainException(
                    'Shift kasir telah berubah sejak kutipan dibuat. Silakan hitung ulang kutipan.',
                    'SHIFT_MISMATCH',
                    409
                );
            }

            // 4. Re-validate commercial versions: products and store settings must
            //    not have changed since the quote was issued.
            $this->assertCommercialVersionsUnchanged($quote);

            $calc = $quote->result_snapshot;
            $items = collect($calc['items'] ?? []);

            if ($items->isEmpty()) {
                throw new DomainException('Kutipan tidak memiliki item yang valid.', 'EMPTY_CART', 422);
            }

            // 5. Validate payment instruction against the quoted grand total.
            $grandTotal = (int) $calc['grand_total'];

            if ($grandTotal > self::MAX_SALE_TOTAL) {
                throw new DomainException(
                    'Total transaksi melebihi batas maksimum Rp ' .
                        number_format(self::MAX_SALE_TOTAL, 0, ',', '.') . '.',
                    'SALE_TOTAL_LIMIT_EXCEEDED',
                    422
                );
            }

            $paymentData = $this->paymentService->validatePayment(
                $paymentMethod,
                $grandTotal,
                $tenderedAmount,
                $paymentReference
            );

            // 6. Lock product rows in ascending ID order and verify stock sufficiency.
            $requested = $items->mapWithKeys(fn ($i) => [(int) $i['product_id'] => (int) $i['quantity']])->all();
            $lockedProducts = $this->stockService->lockAndVerifyStock($requested);

            // 7. Build immutable header + store snapshot.
            $settings = StoreSetting::first();
            $completedAt = Carbon::now('UTC');
            $terminal = $shift->terminal;

            $sale = Sale::create([
                'receipt_number' => $this->generateReceiptNumber($completedAt),
                'user_id' => $user->id,
                'shift_id' => $shift->id,
                'terminal_id' => $shift->terminal_id,
                'customer_id' => $quote->payload_snapshot['customer_id'] ?? null,
                'customer_snapshot' => $this->buildCustomerSnapshot(
                    isset($quote->payload_snapshot['customer_id'])
                        ? (int) $quote->payload_snapshot['customer_id']
                        : null
                ),
                'cashier_name_snapshot' => $user->name,
                'terminal_code_snapshot' => $terminal?->code ?? 'UNKNOWN',
                'store_snapshot' => $this->buildStoreSnapshot($settings),
                'subtotal' => (int) $calc['gross_total'],
                'item_discount_total' => (int) $calc['item_discount_total'],
                'sale_discount_type' => $calc['sale_discount_type'] ?? null,
                'sale_discount_input' => $calc['sale_discount_input'] ?? null,
                'sale_discount_amount' => (int) $calc['sale_discount_total'],
                'net_total' => (int) $calc['net_total'],
                'tax_rate_percent_snapshot' => isset($calc['tax_rate_bps'])
                    ? (int) $calc['tax_rate_bps'] / 100
                    : 0,
                'tax_amount' => (int) $calc['tax_total'],
                'grand_total' => $grandTotal,
                'total_cost' => '0', // accumulated below
                'status' => 'completed',
                'return_status' => 'none',
                'completed_at' => $completedAt,
            ]);

            // 8. Persist line items and decrement stock with immutable ledger entries.
            $totalCost = '0';

            foreach ($items as $line) {
                $product = $lockedProducts->get((int) $line['product_id']);
                $qty = (int) $line['quantity'];
                $unitCost = (string) $product->average_cost;
                $extendedCost = bcmul($unitCost, (string) $qty, 6);

                $saleItem = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'sku_snapshot' => $product->sku,
                    'barcode_snapshot' => $product->barcode,
                    'name_snapshot' => $product->name,
                    'unit_snapshot' => $product->unit,
                    'quantity' => $qty,
                    'unit_price' => (int) $line['unit_price'],
                    'cost_price_snapshot' => $unitCost,
                    'gross_amount' => (int) $line['gross'],
                    'discount_type' => $line['item_discount_type'] ?? null,
                    'discount_input' => $line['item_discount_input'] ?? null,
                    'item_discount_amount' => (int) $line['item_discount'],
                    'sale_discount_allocation' => (int) $line['allocated_sale_discount'],
                    'net_amount' => (int) $line['net'],
                    'tax_amount' => (int) $line['tax'],
                    'subtotal' => (int) $line['line_total'],
                    'extended_cost' => $extendedCost,
                ]);

                $totalCost = bcadd($totalCost, $extendedCost, 6);

                $this->stockService->decrementStock(
                    $product,
                    $qty,
                    'sale',
                    'sale',
                    $sale->id,
                    $saleItem->id,
                    $user->id,
                    'Penjualan struk ' . $sale->receipt_number
                );
            }

            $sale->forceFill(['total_cost' => $totalCost])->save();

            // 9. Record the payment (single payment per sale in Phase 3).
            Payment::create([
                'sale_id' => $sale->id,
                'shift_id' => $shift->id,
                'method' => $paymentData['method'],
                'amount_due' => $paymentData['amount_due'],
                'amount_paid' => $paymentData['amount_paid'],
                'change_amount' => $paymentData['change_amount'],
                'reference_number' => $paymentData['reference_number'],
                'confirmed_by' => $user->id,
                'confirmed_at' => $completedAt,
                'refund_status' => 'none',
            ]);

            // 10. Consume the quote so it cannot be reused.
            $quote->forceFill(['consumed_sale_id' => $sale->id])->save();

            // 11. Build the response payload from stored state.
            $sale->refresh();

            return [
                'resource_type' => 'sale',
                'resource_id' => $sale->id,
                'response' => $this->buildSalePayload($sale),
            ];
        }, attempts: 3);
    }

    /**
     * Reject checkout when product prices/names or store commercial settings have
     * changed since the quote was issued.
     *
     * @throws DomainException
     */
    protected function assertCommercialVersionsUnchanged(CheckoutQuote $quote): void
    {
        $versions = $quote->commercial_versions ?? [];
        $quotedProducts = $versions['products'] ?? [];
        $quotedSettingsVersion = $versions['settings_version'] ?? null;

        // Products
        $currentVersions = Product::whereIn('id', array_keys($quotedProducts))
            ->pluck('commercial_version', 'id');

        foreach ($quotedProducts as $productId => $quotedVersion) {
            $current = $currentVersions->get((int) $productId);

            if ($current === null) {
                throw new DomainException(
                    'Sebuah produk dalam kutipan telah dihapus. Silakan hitung ulang kutipan.',
                    'QUOTE_STALE',
                    409
                );
            }

            if ((int) $current !== (int) $quotedVersion) {
                throw new DomainException(
                    'Harga atau data komersial produk berubah sejak kutipan dibuat. Silakan hitung ulang kutipan.',
                    'QUOTE_STALE',
                    409
                );
            }
        }

        // Store settings (tax, discount policy)
        $settings = StoreSetting::first();
        $currentSettingsVersion = $settings?->settings_version ?? 1;

        if ($quotedSettingsVersion !== null && (int) $currentSettingsVersion !== (int) $quotedSettingsVersion) {
            throw new DomainException(
                'Pengaturan toko (pajak/diskon) berubah sejak kutipan dibuat. Silakan hitung ulang kutipan.',
                'QUOTE_STALE',
                409
            );
        }
    }

    /**
     * Generate a human-readable, unique receipt number: DM-YYYYMMDD-XXXXX
     * (sequential per calendar day, derived inside the transaction).
     */
    protected function generateReceiptNumber(Carbon $completedAt): string
    {
        $datePart = $completedAt->format('Ymd');
        $prefix = "DM-{$datePart}-";

        $lastReceipt = Sale::where('receipt_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('receipt_number')
            ->value('receipt_number');

        $nextSequence = 1;
        if ($lastReceipt) {
            $nextSequence = ((int) substr($lastReceipt, -5)) + 1;
        }

        return $prefix . str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);
    }

    protected function buildCustomerSnapshot(?int $customerId): ?array
    {
        if (!$customerId) {
            return null;
        }

        $customer = \App\Models\Customer::find($customerId);
        if (!$customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'phone' => $customer->phone,
        ];
    }

    protected function buildStoreSnapshot(?StoreSetting $settings): array
    {
        if (!$settings) {
            return [
                'store_name' => config('app.name', 'DragonMart POS'),
                'address' => null,
                'phone' => null,
                'receipt_footer' => null,
            ];
        }

        return [
            'store_name' => $settings->store_name,
            'address' => $settings->address,
            'phone' => $settings->phone,
            'email' => $settings->email,
            'receipt_footer' => $settings->receipt_footer,
            'settings_version' => $settings->settings_version,
        ];
    }

    /**
     * Full public representation of a completed sale with relations loaded.
     */
    public function buildSalePayload(Sale $sale): array
    {
        $sale->loadMissing(['items', 'payment']);

        return [
            'id' => $sale->id,
            'receipt_number' => $sale->receipt_number,
            'status' => $sale->status,
            'return_status' => $sale->return_status,
            'completed_at' => $sale->completed_at?->toIso8601String(),
            'shift_id' => $sale->shift_id,
            'cashier_name' => $sale->cashier_name_snapshot,
            'terminal_code' => $sale->terminal_code_snapshot,
            'customer' => $sale->customer_snapshot,
            'items' => $sale->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->sku_snapshot,
                'name' => $item->name_snapshot,
                'unit' => $item->unit_snapshot,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'gross_amount' => $item->gross_amount,
                'item_discount_amount' => $item->item_discount_amount,
                'sale_discount_allocation' => $item->sale_discount_allocation,
                'net_amount' => $item->net_amount,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->subtotal,
            ])->values()->all(),
            'totals' => [
                'subtotal' => $sale->subtotal,
                'item_discount_total' => $sale->item_discount_total,
                'sale_discount_type' => $sale->sale_discount_type,
                'sale_discount_input' => $sale->sale_discount_input,
                'sale_discount_amount' => $sale->sale_discount_amount,
                'net_total' => $sale->net_total,
                'tax_rate_percent' => $sale->tax_rate_percent_snapshot !== null
                    ? (float) $sale->tax_rate_percent_snapshot
                    : null,
                'tax_total' => $sale->tax_amount,
                'grand_total' => $sale->grand_total,
            ],
            'payment' => $sale->payment ? [
                'method' => $sale->payment->method,
                'amount_due' => $sale->payment->amount_due,
                'amount_paid' => $sale->payment->amount_paid,
                'change_amount' => $sale->payment->change_amount,
                'reference_number' => $sale->payment->reference_number,
            ] : null,
        ];
    }
}
