<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InventoryFrozenException;
use App\Models\InventoryControl;
use App\Models\Product;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class StockService
{
    /**
     * Check if store inventory is paused due to an active stocktake.
     * Throws InventoryFrozenException if paused.
     */
    public function checkInventoryGuard(): void
    {
        $control = InventoryControl::first();
        if ($control && $control->active_stocktake_id !== null) {
            throw new InventoryFrozenException(
                'Operasi inventaris ditolak karena toko sedang dalam jeda stocktake (audit stok fisik).'
            );
        }
    }

    /**
     * Acquire row-level locks on products in strictly ascending product ID order,
     * and verify stock sufficiency.
     *
     * @param array $requestedQuantities Array mapping [product_id => quantity]
     * @return Collection<int, Product> Locked products keyed by id
     * @throws InventoryFrozenException|InsufficientStockException
     */
    public function lockAndVerifyStock(array $requestedQuantities): Collection
    {
        // 1. Guard check
        $this->checkInventoryGuard();

        // 2. Sort product IDs ascending to prevent deadlocks
        $productIds = array_keys($requestedQuantities);
        sort($productIds, SORT_NUMERIC);

        // 3. Acquire SELECT ... FOR UPDATE
        $products = Product::whereIn('id', $productIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // 4. Verify stock and active status
        foreach ($requestedQuantities as $productId => $qty) {
            $product = $products->get($productId);

            if (!$product) {
                throw new InsufficientStockException(
                    "Produk dengan ID {$productId} tidak ditemukan.",
                    ['product_id' => $productId]
                );
            }

            if (!$product->is_active) {
                throw new InsufficientStockException(
                    "Produk '{$product->name}' tidak aktif atau telah diarsipkan.",
                    ['product_id' => $productId, 'sku' => $product->sku]
                );
            }

            if ($product->stock < $qty) {
                throw new InsufficientStockException(
                    "Stok produk '{$product->name}' tidak mencukupi (tersedia: {$product->stock}, diminta: {$qty}).",
                    [
                        'product_id' => $productId,
                        'sku' => $product->sku,
                        'available_stock' => $product->stock,
                        'requested_quantity' => $qty,
                    ]
                );
            }
        }

        return $products;
    }

    /**
     * Decrement stock atomically and record immutable movement ledger.
     */
    public function decrementStock(
        Product $product,
        int $quantity,
        string $type,
        string $referenceType,
        int $referenceId,
        ?int $referenceLineId,
        int $userId,
        ?string $reason = null
    ): StockMovement {
        $this->checkInventoryGuard();

        $stockBefore = (int) $product->stock;
        $stockAfter = $stockBefore - $quantity;
        $costBefore = (string) $product->average_cost;
        $costAfter = $costBefore;

        $totalValBefore = bcmul((string) $stockBefore, $costBefore, 6);
        $totalValAfter = bcmul((string) $stockAfter, $costAfter, 6);

        $product->update([
            'stock' => $stockAfter,
        ]);

        return StockMovement::create([
            'product_id' => $product->id,
            'quantity' => -$quantity, // Negative delta
            'type' => $type,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_line_id' => $referenceLineId,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'cost_before' => $costBefore,
            'cost_after' => $costAfter,
            'total_value_before' => $totalValBefore,
            'total_value_after' => $totalValAfter,
            'user_id' => $userId,
            'reason' => $reason,
            'posted_at' => Carbon::now('UTC'),
        ]);
    }

    /**
     * Increment stock atomically, recalculate moving weighted-average cost,
     * and record immutable movement ledger.
     */
    public function incrementStock(
        Product $product,
        int $quantity,
        string|float $unitCost,
        string $type,
        string $referenceType,
        int $referenceId,
        ?int $referenceLineId,
        int $userId,
        ?string $reason = null
    ): StockMovement {
        $this->checkInventoryGuard();

        $stockBefore = (int) $product->stock;
        $costBefore = (string) $product->average_cost;

        $newAverageCost = CalculationService::calculateMovingAverageCost(
            $stockBefore,
            $costBefore,
            $quantity,
            $unitCost
        );

        $stockAfter = $stockBefore + $quantity;
        $costAfter = $newAverageCost;

        $totalValBefore = bcmul((string) $stockBefore, $costBefore, 6);
        $totalValAfter = bcmul((string) $stockAfter, $costAfter, 6);

        $product->update([
            'stock' => $stockAfter,
            'average_cost' => $newAverageCost,
        ]);

        return StockMovement::create([
            'product_id' => $product->id,
            'quantity' => $quantity, // Positive delta
            'type' => $type,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_line_id' => $referenceLineId,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'cost_before' => $costBefore,
            'cost_after' => $costAfter,
            'total_value_before' => $totalValBefore,
            'total_value_after' => $totalValAfter,
            'user_id' => $userId,
            'reason' => $reason,
            'posted_at' => Carbon::now('UTC'),
        ]);
    }
}
