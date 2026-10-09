<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\InventoryControl;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stocktake (Audit Stok Fisik) — Fase 4.C.
 *
 * While a stocktake is active, the entire store is frozen: every stock
 * mutation (sale decrement, receiving increment, adjustment, etc.) is
 * rejected by StockService::checkInventoryGuard. The freeze is controlled
 * by a single-row `inventory_control` table whose `active_stocktake_id`
 * pointer is set on start and cleared on post or cancel.
 *
 * State machine: counting -> posted, or -> cancelled.
 *
 * Start: snapshot all active products' current stock & cost into
 *        stocktake_items. Set inventory_control.active_stocktake_id.
 * Count: write the physically observed counted_stock per product; difference
 *        is computed but no stock change happens yet.
 * Post:  for every counted product, atomically adjust stock (delta = counted
 *        - snapshot) via StockService, write stock_movements entries of
 *        type=stocktake_adjustment, then clear the active stocktake pointer.
 * Cancel: discard counts, clear the active stocktake pointer. No stock
 *         change.
 */
class StocktakeService
{
    public const STATUS_COUNTING = 'counting';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    public function start(User $user, ?string $notes): Stocktake
    {
        $this->assertCanManage($user);

        return DB::transaction(function () use ($user, $notes) {
            // Lock the singleton control row first to serialize start/cancel/post
            // transitions and prevent two concurrent starts from racing.
            $control = InventoryControl::lockForUpdate()->first();
            if (!$control) {
                $control = InventoryControl::create(['active_stocktake_id' => null]);
                $control = InventoryControl::lockForUpdate()->find($control->id);
            }
            if ($control->active_stocktake_id !== null) {
                $existing = Stocktake::find($control->active_stocktake_id);
                throw new DomainException(
                    'Sudah ada stocktake aktif. Selesaikan atau batalkan stocktake yang sedang berjalan terlebih dahulu.',
                    'STOCKTAKE_ALREADY_ACTIVE',
                    409,
                    ['active_stocktake_id' => $control->active_stocktake_id, 'active_stocktake_number' => $existing?->stocktake_number],
                );
            }

            $stocktake = Stocktake::create([
                'stocktake_number' => $this->generateStocktakeNumber(),
                'status' => self::STATUS_COUNTING,
                'notes' => $notes,
                'started_by' => $user->id,
                'started_at' => Carbon::now(),
            ]);

            // Snapshot every active product. Lock products in ascending ID
            // order to prevent deadlocks with concurrent readers/writers.
            $products = Product::where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($products as $product) {
                StocktakeItem::create([
                    'stocktake_id' => $stocktake->id,
                    'product_id' => $product->id,
                    'snapshot_stock' => (int) $product->stock,
                    'snapshot_average_cost' => (string) $product->average_cost,
                ]);
            }

            // Activate the freeze using the already-locked control row.
            $control->update(['active_stocktake_id' => $stocktake->id]);

            return $stocktake->fresh(['items.product']);
        });
    }

    /**
     * Record the physical count for a single product. Repeatedly calling
     * this overwrites the previous count; the difference is recomputed.
     * Stock is NOT changed here.
     */
    public function count(
        User $user,
        Stocktake $stocktake,
        int $productId,
        int $countedStock
    ): StocktakeItem {
        $this->assertCanManage($user);
        $this->assertCounting($stocktake);

        if ($countedStock < 0) {
            throw new DomainException(
                'Jumlah fisik tidak boleh negatif.',
                'STOCKTAKE_INVALID_COUNT',
                422,
                ['product_id' => $productId],
            );
        }

        $item = StocktakeItem::where('stocktake_id', $stocktake->id)
            ->where('product_id', $productId)
            ->first();

        if (!$item) {
            throw new DomainException(
                "Produk dengan id {$productId} tidak termasuk dalam stocktake ini.",
                'STOCKTAKE_PRODUCT_NOT_IN_SESSION',
                404,
                ['product_id' => $productId],
            );
        }

        $item->update([
            'counted_stock' => $countedStock,
            'difference' => $countedStock - (int) $item->snapshot_stock,
        ]);

        return $item->fresh('product');
    }

    /**
     * Bulk record counts. Each item is a [product_id, counted_stock] pair.
     * @param array<int, array{product_id:int, counted_stock:int}> $counts
     */
    public function bulkCount(User $user, Stocktake $stocktake, array $counts): Stocktake
    {
        $this->assertCanManage($user);
        $this->assertCounting($stocktake);

        if (!is_array($counts) || count($counts) === 0) {
            throw new DomainException(
                'Daftar hitungan kosong.',
                'STOCKTAKE_EMPTY_COUNTS',
                422,
            );
        }

        DB::transaction(function () use ($user, $stocktake, $counts) {
            foreach ($counts as $entry) {
                if (!is_array($entry) || !isset($entry['product_id'], $entry['counted_stock'])) {
                    throw new DomainException(
                        'Setiap entri hitungan harus memiliki product_id dan counted_stock.',
                        'STOCKTAKE_INVALID_COUNT_SHAPE',
                        422,
                    );
                }
                $this->count($user, $stocktake, (int) $entry['product_id'], (int) $entry['counted_stock']);
            }
        });

        return $stocktake->fresh(['items.product']);
    }

    /**
     * Post the stocktake: apply every counted difference atomically, then
     * clear the active stocktake pointer. The freeze is lifted at the end.
     */
    public function post(User $user, Stocktake $stocktake): Stocktake
    {
        $this->assertCanManage($user);
        $this->assertCounting($stocktake);

        $items = $stocktake->items()->with('product')->get();

        $unprocessed = $items->whereNull('counted_stock');
        if ($unprocessed->isNotEmpty()) {
            throw new DomainException(
                "Sebanyak {$unprocessed->count()} produk belum dihitung. Selesaikan input fisik terlebih dahulu atau batalkan stocktake.",
                'STOCKTAKE_INCOMPLETE',
                409,
                ['uncounted_count' => $unprocessed->count()],
            );
        }

        DB::transaction(function () use ($user, $stocktake, $items) {
            // Lock products ascending by id; this matches the convention
            // used by ReceivingService, SaleService, and others to prevent
            // deadlocks under concurrent activity.
            $productIds = $items->pluck('product_id')->sort()->values()->all();
            $lockedProducts = Product::whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $delta = (int) $item->difference;
                if ($delta === 0) {
                    continue; // No movement for accurate counts
                }
                $product = $lockedProducts[$item->product_id];

                if ($delta > 0) {
                    $this->stockService->incrementStock(
                        product: $product,
                        quantity: $delta,
                        unitCost: (string) $product->average_cost,
                        type: StockMovement::TYPE_STOCKTAKE_ADJUSTMENT,
                        referenceType: 'stocktake',
                        referenceId: $stocktake->id,
                        referenceLineId: $item->id,
                        userId: $user->id,
                        reason: 'stocktake:' . $stocktake->stocktake_number,
                        bypassInventoryGuard: true,
                    );
                } else {
                    $this->stockService->decrementStock(
                        product: $product,
                        quantity: abs($delta),
                        type: StockMovement::TYPE_STOCKTAKE_ADJUSTMENT,
                        referenceType: 'stocktake',
                        referenceId: $stocktake->id,
                        referenceLineId: $item->id,
                        userId: $user->id,
                        reason: 'stocktake:' . $stocktake->stocktake_number,
                        bypassInventoryGuard: true,
                    );
                }
            }

            $stocktake->update([
                'status' => self::STATUS_POSTED,
                'posted_by' => $user->id,
                'posted_at' => Carbon::now(),
            ]);

            // Lift the freeze (lock the control row to serialize with start).
            $control = InventoryControl::lockForUpdate()->first();
            if ($control && $control->active_stocktake_id === $stocktake->id) {
                $control->update(['active_stocktake_id' => null]);
            }
        });

        return $stocktake->fresh(['items.product', 'poster', 'starter']);
    }

    /**
     * Cancel a counting stocktake. No stock change. Lifts the freeze.
     */
    public function cancel(User $user, Stocktake $stocktake): Stocktake
    {
        $this->assertCanManage($user);

        if ($stocktake->status !== self::STATUS_COUNTING) {
            throw new DomainException(
                "Hanya stocktake dengan status 'counting' yang dapat dibatalkan.",
                'STOCKTAKE_NOT_CANCELLABLE',
                409,
                ['status' => $stocktake->status],
            );
        }

        DB::transaction(function () use ($stocktake) {
            $stocktake->update([
                'status' => self::STATUS_CANCELLED,
                'cancelled_at' => Carbon::now(),
            ]);

            // Lift the freeze if this stocktake was the active one.
            $control = InventoryControl::lockForUpdate()->first();
            if ($control && $control->active_stocktake_id === $stocktake->id) {
                $control->update(['active_stocktake_id' => null]);
            }
        });

        return $stocktake->fresh(['items.product']);
    }

    /**
     * Public representation of a stocktake document with relations.
     */
    public function buildStocktakePayload(Stocktake $stocktake): array
    {
        $stocktake->loadMissing(['items.product', 'starter', 'poster']);

        return [
            'id' => $stocktake->id,
            'stocktake_number' => $stocktake->stocktake_number,
            'status' => $stocktake->status,
            'notes' => $stocktake->notes,
            'started_by' => [
                'id' => $stocktake->starter?->id,
                'name' => $stocktake->starter?->name,
            ],
            'started_at' => $stocktake->started_at?->toIso8601String(),
            'posted_by' => $stocktake->poster ? [
                'id' => $stocktake->poster->id,
                'name' => $stocktake->poster->name,
            ] : null,
            'posted_at' => $stocktake->posted_at?->toIso8601String(),
            'cancelled_at' => $stocktake->cancelled_at?->toIso8601String(),
            'items' => $stocktake->items->map(fn (StocktakeItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'product_sku' => $item->product?->sku,
                'snapshot_stock' => (int) $item->snapshot_stock,
                'snapshot_average_cost' => (int) round((float) $item->snapshot_average_cost),
                'counted_stock' => $item->counted_stock !== null ? (int) $item->counted_stock : null,
                'difference' => $item->difference !== null ? (int) $item->difference : null,
            ])->values()->all(),
            'summary' => [
                'total_products' => $stocktake->items->count(),
                'counted' => $stocktake->items->whereNotNull('counted_stock')->count(),
                'with_difference' => $stocktake->items->where('difference', '!=', 0)
                    ->whereNotNull('difference')->count(),
                'net_difference' => (int) $stocktake->items->whereNotNull('difference')->sum('difference'),
            ],
            'created_at' => $stocktake->created_at?->toIso8601String(),
            'updated_at' => $stocktake->updated_at?->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    public function __construct(
        protected StockService $stockService,
    ) {}

    protected function assertCanManage(User $user): void
    {
        if ($user->can('manage-inventory') || $user->hasAnyRole(['owner', 'manager'])) {
            return;
        }
        throw new DomainException(
            'Anda tidak memiliki izin untuk mengelola stocktake.',
            'INVENTORY_FORBIDDEN',
            403,
        );
    }

    protected function assertCounting(Stocktake $stocktake): void
    {
        if ($stocktake->status !== self::STATUS_COUNTING) {
            throw new DomainException(
                "Hanya stocktake dengan status 'counting' yang menerima input atau posting.",
                'STOCKTAKE_NOT_COUNTING',
                409,
                ['status' => $stocktake->status],
            );
        }
    }

    /**
     * Generate a human-readable stocktake number: STK-YYYYMMDD-XXXXX
     */
    protected function generateStocktakeNumber(): string
    {
        $today = Carbon::now()->format('Ymd');
        $prefix = "STK-{$today}-";
        $latest = Stocktake::where('stocktake_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('stocktake_number');

        $next = 1;
        if ($latest && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $latest, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
