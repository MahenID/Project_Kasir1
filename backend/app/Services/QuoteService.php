<?php

namespace App\Services;

use App\Exceptions\DiscountLimitException;
use App\Exceptions\DomainException;
use App\Models\CheckoutQuote;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class QuoteService
{
    /**
     * Create an opaque server quote for checkout.
     * Valid for 120 seconds. Reserves no stock.
     */
    public function createQuote(
        User $user,
        array $items,
        ?string $saleDiscountType = null,
        ?int $saleDiscountValue = null,
        ?int $customerId = null
    ): array {
        // 1. Verify user has an open shift
        $shift = $user->currentOpenShift;
        if (!$shift) {
            throw new DomainException(
                'Anda harus membuka shift kasir terlebih dahulu sebelum membuat kutipan harga atau memproses transaksi.',
                'SHIFT_REQUIRED',
                409
            );
        }

        // 2. Validate items array
        if (empty($items)) {
            throw new DomainException('Keranjang belanja kosong. Minimal harus ada 1 produk.', 'EMPTY_CART', 422);
        }

        if (count($items) > 100) {
            throw new DomainException('Jumlah baris produk melebihi batas maksimum 100 baris.', 'MAX_ITEMS_EXCEEDED', 422);
        }

        // Merge duplicate product IDs
        $mergedItems = [];
        foreach ($items as $item) {
            $pid = (int) $item['product_id'];
            $qty = (int) ($item['quantity'] ?? 1);
            if ($qty < 1 || $qty > 10000) {
                throw new DomainException("Kuantitas produk (ID: {$pid}) harus antara 1 sampai 10.000.", 'INVALID_QUANTITY', 422);
            }

            if (isset($mergedItems[$pid])) {
                $mergedItems[$pid]['quantity'] += $qty;
            } else {
                $mergedItems[$pid] = [
                    'product_id' => $pid,
                    'quantity' => $qty,
                    'discount_type' => $item['discount_type'] ?? null,
                    'discount_value' => isset($item['discount_value']) ? (int) $item['discount_value'] : null,
                ];
            }
        }

        // 3. Fetch store settings for tax and discount limits
        $settings = StoreSetting::first() ?? new StoreSetting([
            'tax_enabled' => false,
            'tax_rate_percent' => 11.00,
            'max_cashier_discount_percent' => 10.00,
            'settings_version' => 1,
        ]);

        $taxEnabled = (bool) $settings->tax_enabled;
        $taxRateBps = (int) ($settings->tax_rate_percent * 100);
        $maxCashierDiscountBps = (int) ($settings->max_cashier_discount_percent * 100);

        // 4. Validate permissions and discount limits
        $isCashier = $user->hasRole('cashier') && !$user->hasRole('owner') && !$user->hasRole('manager');

        if ($isCashier && !empty($saleDiscountValue) && $saleDiscountValue > 0) {
            if (!$user->can('apply-cart-discount')) {
                throw new DiscountLimitException(
                    'Kasir tidak memiliki izin untuk memberikan diskon grosir/faktur.',
                    ['sale_discount' => 'Unauthorized']
                );
            }
        }

        // 5. Fetch products
        $productIds = array_keys($mergedItems);
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $linesForCalc = [];
        $productVersions = [];

        foreach ($mergedItems as $pid => $itemData) {
            $product = $products->get($pid);

            if (!$product) {
                throw new DomainException("Produk dengan ID {$pid} tidak ditemukan.", 'PRODUCT_NOT_FOUND', 404);
            }

            if (!$product->is_active) {
                throw new DomainException("Produk '{$product->name}' tidak aktif atau telah diarsipkan.", 'PRODUCT_INACTIVE', 422);
            }

            // Check item discount limit for cashier
            if ($isCashier && !empty($itemData['discount_value']) && $itemData['discount_value'] > 0) {
                if ($itemData['discount_type'] === 'percent') {
                    if ($itemData['discount_value'] > $maxCashierDiscountBps) {
                        throw new DiscountLimitException(
                            "Diskon item untuk '{$product->name}' ({$itemData['discount_value']} bps) melebihi batas wewenang kasir (maksimal {$settings->max_cashier_discount_percent}%).",
                            ['product_id' => $pid, 'max_allowed_percent' => $settings->max_cashier_discount_percent]
                        );
                    }
                } elseif ($itemData['discount_type'] === 'fixed') {
                    $grossEstimate = $itemData['quantity'] * $product->selling_price;
                    $percentEquivalentBps = (int) CalculationService::roundHalfUp(($itemData['discount_value'] * 10000) / $grossEstimate);
                    if ($percentEquivalentBps > $maxCashierDiscountBps) {
                        throw new DiscountLimitException(
                            "Diskon nominal untuk '{$product->name}' melebihi batas persentase wewenang kasir (maksimal {$settings->max_cashier_discount_percent}%).",
                            ['product_id' => $pid, 'max_allowed_percent' => $settings->max_cashier_discount_percent]
                        );
                    }
                }
            }

            $isAvailable = $product->stock >= $itemData['quantity'];

            $linesForCalc[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'unit' => $product->unit,
                'quantity' => $itemData['quantity'],
                'unit_price' => $product->selling_price,
                'discount_type' => $itemData['discount_type'],
                'discount_value' => $itemData['discount_value'],
                'is_available' => $isAvailable,
                'stock_available' => $product->stock,
                'unit_cost_snapshot' => $product->average_cost,
                'commercial_version' => $product->commercial_version,
            ];

            $productVersions[$product->id] = $product->commercial_version;
        }

        // 6. Calculate Quote
        $quoteCalc = CalculationService::calculateSaleQuote(
            $linesForCalc,
            $saleDiscountType,
            $saleDiscountValue,
            $taxEnabled,
            $taxRateBps
        );

        // 7. Verify Customer if supplied
        $customerSnapshot = null;
        if ($customerId) {
            $customer = Customer::find($customerId);
            if ($customer) {
                $customerSnapshot = [
                    'id' => $customer->id,
                    'code' => $customer->code,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ];
            }
        }

        $now = Carbon::now('UTC');
        $expiresAt = $now->copy()->addSeconds(120);
        $quoteId = (string) Str::uuid();

        $quotePayload = [
            'items' => $items,
            'sale_discount_type' => $saleDiscountType,
            'sale_discount_value' => $saleDiscountValue,
            'customer_id' => $customerId,
        ];

        $quoteHash = hash('sha256', json_encode($quoteCalc));

        // 8. Store Quote record in DB
        CheckoutQuote::create([
            'id' => $quoteId,
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'quote_hash' => $quoteHash,
            'payload_snapshot' => $quotePayload,
            'result_snapshot' => $quoteCalc,
            'commercial_versions' => [
                'products' => $productVersions,
                'settings_version' => $settings->settings_version,
            ],
            'expires_at' => $expiresAt,
        ]);

        return [
            'quote_id' => $quoteId,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in_seconds' => 120,
            'shift' => [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
            ],
            'customer' => $customerSnapshot,
            'calculation' => $quoteCalc,
        ];
    }
}
