<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inventory Adjustments (Penyesuaian Stok & Revaluasi) — Fase 4.B.
 *
 * Two document types share one state machine (draft -> posted) and one
 * service to keep authorization and audit consistent:
 *
 *  - type=quantity: signed quantity_delta per line. Posting decrements or
 *    increments stock and writes a stock_movements ledger entry. Does NOT
 *    touch average_cost. Requires manage-inventory (owner/manager).
 *
 *  - type=revaluation: replaces product.average_cost with new_average_cost
 *    per line. Stock is NOT changed. Writes a stock_movements ledger entry
 *    with quantity=0 and the cost delta. OWNER ONLY per PRD.
 *
 * Posted adjustments are immutable. Corrections require a new adjustment
 * document (this matches the receiving design and the immutable-ledger rule).
 */
class InventoryAdjustmentService
{
    public const TYPE_QUANTITY = 'quantity';
    public const TYPE_REVALUATION = 'revaluation';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        protected StockService $stockService,
    ) {}

    /**
     * Create a draft adjustment. The line set is finalized at creation:
     * adjustments don't support PUT /lines (the type of change is already
     * encoded in the line shape and a separate update endpoint would only
     * complicate audit). To correct, cancel this draft and create a new one.
     *
     * @param array $items Per-type line shape, see normalizeAndValidateItems
     */
    public function createDraft(
        User $user,
        string $type,
        string $reason,
        ?string $evidenceReference,
        array $items,
    ): InventoryAdjustment {
        $this->assertValidType($type);
        $normalizedItems = $this->normalizeAndValidateItems($type, $items);

        return DB::transaction(function () use ($user, $type, $reason, $evidenceReference, $normalizedItems) {
            $adjustment = InventoryAdjustment::create([
                'adjustment_number' => $this->generateAdjustmentNumber(),
                'type' => $type,
                'reason' => $reason,
                'evidence_reference' => $evidenceReference,
                'status' => self::STATUS_DRAFT,
                'created_by' => $user->id,
            ]);

            foreach ($normalizedItems as $line) {
                $this->createLine($adjustment, $line);
            }

            return $adjustment->fresh(['items.product']);
        });
    }

    /**
     * Post a draft adjustment atomically. Quantity adjustments update
     * stock via StockService; revaluations overwrite average_cost
     * directly. Both write the immutable stock_movements ledger.
     */
    public function post(User $user, InventoryAdjustment $adjustment): InventoryAdjustment
    {
        $this->assertPostable($adjustment);

        $lines = $adjustment->items()->with('product')->get();
        if ($lines->isEmpty()) {
            throw new DomainException(
                'Dokumen penyesuaian tanpa item tidak dapat diposting.',
                'ADJUSTMENT_EMPTY',
                422,
            );
        }

        // Re-validate products at post time (a product could have been
        // deactivated between draft and post).
        foreach ($lines as $line) {
            if (!$line->product || !$line->product->is_active) {
                throw new DomainException(
                    "Produk pada baris item tidak aktif atau tidak ditemukan.",
                    'PRODUCT_INACTIVE',
                    409,
                    ['product_id' => $line->product_id],
                );
            }
        }

        DB::transaction(function () use ($user, $adjustment, $lines) {
            // Lock products in strictly ascending ID order
            $productIds = $lines->pluck('product_id')->sort()->values()->all();
            $lockedProducts = Product::whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lines as $line) {
                $product = $lockedProducts[$line->product_id];

                if ($adjustment->type === self::TYPE_QUANTITY) {
                    $this->postQuantityLine($user, $adjustment, $line, $product);
                } else {
                    $this->postRevaluationLine($user, $adjustment, $line, $product);
                }
            }

            $adjustment->update([
                'status' => self::STATUS_POSTED,
                'posted_by' => $user->id,
                'posted_at' => Carbon::now(),
            ]);
        });

        return $adjustment->fresh(['items.product', 'poster']);
    }

    /**
     * Cancel a draft adjustment. Posted adjustments are immutable.
     */
    public function cancel(User $user, InventoryAdjustment $adjustment): InventoryAdjustment
    {
        if ($adjustment->status !== self::STATUS_DRAFT) {
            throw new DomainException(
                'Hanya dokumen draft yang dapat dibatalkan.',
                'ADJUSTMENT_NOT_CANCELLABLE',
                409,
                ['status' => $adjustment->status],
            );
        }

        $adjustment->update(['status' => self::STATUS_CANCELLED]);

        return $adjustment->fresh(['items.product']);
    }

    /**
     * Public, post-load representation of an adjustment with relations.
     */
    public function buildAdjustmentPayload(InventoryAdjustment $adjustment): array
    {
        $adjustment->loadMissing(['items.product', 'creator', 'poster']);

        return [
            'id' => $adjustment->id,
            'adjustment_number' => $adjustment->adjustment_number,
            'type' => $adjustment->type,
            'status' => $adjustment->status,
            'reason' => $adjustment->reason,
            'evidence_reference' => $adjustment->evidence_reference,
            'created_by' => [
                'id' => $adjustment->creator?->id,
                'name' => $adjustment->creator?->name,
            ],
            'posted_by' => $adjustment->poster ? [
                'id' => $adjustment->poster->id,
                'name' => $adjustment->poster->name,
            ] : null,
            'posted_at' => $adjustment->posted_at?->toIso8601String(),
            'items' => $adjustment->items->map(function (InventoryAdjustmentItem $item) {
                $line = [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    'product_sku' => $item->product?->sku,
                    'before_stock' => (int) $item->before_stock,
                    'after_stock' => (int) $item->after_stock,
                ];
                if ($item->quantity_delta !== null) {
                    $line['quantity_delta'] = (int) $item->quantity_delta;
                }
                if ($item->current_average_cost !== null) {
                    $line['current_average_cost'] = (int) round((float) $item->current_average_cost);
                }
                if ($item->new_average_cost !== null) {
                    $line['new_average_cost'] = (int) round((float) $item->new_average_cost);
                }
                return $line;
            })->values()->all(),
            'created_at' => $adjustment->created_at?->toIso8601String(),
            'updated_at' => $adjustment->updated_at?->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------------
    // Posting internals
    // -------------------------------------------------------------------

    protected function postQuantityLine(
        User $user,
        InventoryAdjustment $adjustment,
        InventoryAdjustmentItem $line,
        Product $product,
    ): void {
        $delta = (int) $line->quantity_delta;
        $beforeStock = (int) $product->stock;
        $afterStock = $beforeStock + $delta;

        if ($afterStock < 0) {
            throw new DomainException(
                "Stok produk {$product->sku} tidak boleh menjadi negatif setelah penyesuaian.",
                'INSUFFICIENT_STOCK',
                409,
                [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'available_stock' => $beforeStock,
                    'requested_delta' => $delta,
                ],
            );
        }

        if ($delta > 0) {
            $movement = $this->stockService->incrementStock(
                product: $product,
                quantity: $delta,
                unitCost: (string) $product->average_cost,
                type: StockMovement::TYPE_ADJUSTMENT_QUANTITY,
                referenceType: 'inventory_adjustment',
                referenceId: $adjustment->id,
                referenceLineId: $line->id,
                userId: $user->id,
                reason: 'adjustment:' . $adjustment->adjustment_number,
            );
        } else {
            $movement = $this->stockService->decrementStock(
                product: $product,
                quantity: abs($delta),
                type: StockMovement::TYPE_ADJUSTMENT_QUANTITY,
                referenceType: 'inventory_adjustment',
                referenceId: $adjustment->id,
                referenceLineId: $line->id,
                userId: $user->id,
                reason: 'adjustment:' . $adjustment->adjustment_number,
            );
        }

        $line->update([
            'current_average_cost' => (string) $product->average_cost,
            'before_stock' => $beforeStock,
            'after_stock' => $afterStock,
        ]);
    }

    protected function postRevaluationLine(
        User $user,
        InventoryAdjustment $adjustment,
        InventoryAdjustmentItem $line,
        Product $product,
    ): void {
        $costBefore = (string) $product->average_cost;
        $costAfter = (string) $line->new_average_cost;

        // Touch product.average_cost directly. No stock change.
        $product->update(['average_cost' => $costAfter]);

        // Write a 0-quantity ledger entry to keep the audit trail complete.
        StockMovement::create([
            'product_id' => $product->id,
            'quantity' => 0,
            'type' => StockMovement::TYPE_ADJUSTMENT_REVALUATION,
            'reference_type' => 'inventory_adjustment',
            'reference_id' => $adjustment->id,
            'reference_line_id' => $line->id,
            'stock_before' => (int) $product->stock,
            'stock_after' => (int) $product->stock,
            'cost_before' => $costBefore,
            'cost_after' => $costAfter,
            'total_value_before' => bcmul((string) $product->stock, $costBefore, 6),
            'total_value_after' => bcmul((string) $product->stock, $costAfter, 6),
            'user_id' => $user->id,
            'reason' => 'revaluation:' . $adjustment->adjustment_number,
            'posted_at' => Carbon::now('UTC'),
        ]);

        $line->update([
            'current_average_cost' => $costBefore,
            'before_stock' => (int) $product->stock,
            'after_stock' => (int) $product->stock,
        ]);
    }

    // -------------------------------------------------------------------
    // Line creation
    // -------------------------------------------------------------------

    protected function createLine(InventoryAdjustment $adjustment, array $line): InventoryAdjustmentItem
    {
        $product = Product::find($line['product_id']);

        $row = [
            'inventory_adjustment_id' => $adjustment->id,
            'product_id' => $product->id,
            'before_stock' => 0,
            'after_stock' => 0,
            'current_average_cost' => (string) $product->average_cost,
        ];

        if ($adjustment->type === self::TYPE_QUANTITY) {
            $row['quantity_delta'] = (int) $line['quantity_delta'];
            $row['new_average_cost'] = null;
        } else {
            $row['quantity_delta'] = 0;
            $row['new_average_cost'] = (string) $line['new_average_cost'];
        }

        return InventoryAdjustmentItem::create($row);
    }

    /**
     * Validate and normalize the line set per document type.
     *
     *  - quantity: items[].{product_id, quantity_delta}
     *  - revaluation: items[].{product_id, new_average_cost}
     */
    protected function normalizeAndValidateItems(string $type, array $items): array
    {
        if (!is_array($items)) {
            throw new DomainException(
                'Daftar item tidak valid.',
                'ADJUSTMENT_INVALID_ITEMS',
                422,
            );
        }

        $normalized = [];
        $seenProductIds = [];

        foreach ($items as $index => $line) {
            if (!is_array($line) || !isset($line['product_id'])) {
                throw new DomainException(
                    "Item baris ke-" . ($index + 1) . " tidak memiliki product_id.",
                    'ADJUSTMENT_INVALID_ITEM_SHAPE',
                    422,
                    ['index' => $index],
                );
            }

            $productId = (int) $line['product_id'];

            if (isset($seenProductIds[$productId])) {
                throw new DomainException(
                    "Produk dengan id {$productId} muncul lebih dari satu kali.",
                    'ADJUSTMENT_DUPLICATE_PRODUCT',
                    422,
                    ['product_id' => $productId],
                );
            }
            $seenProductIds[$productId] = true;

            $product = Product::find($productId);
            if (!$product) {
                throw new DomainException(
                    "Produk dengan id {$productId} tidak ditemukan.",
                    'PRODUCT_NOT_FOUND',
                    422,
                    ['product_id' => $productId],
                );
            }
            if (!$product->is_active) {
                throw new DomainException(
                    "Produk {$product->sku} tidak aktif.",
                    'PRODUCT_INACTIVE',
                    422,
                    ['product_id' => $productId],
                );
            }

            if ($type === self::TYPE_QUANTITY) {
                if (!array_key_exists('quantity_delta', $line)) {
                    throw new DomainException(
                        "Item baris ke-" . ($index + 1) . " tidak memiliki quantity_delta.",
                        'ADJUSTMENT_INVALID_ITEM_SHAPE',
                        422,
                        ['index' => $index],
                    );
                }
                $delta = (int) $line['quantity_delta'];
                if ($delta === 0) {
                    throw new DomainException(
                        "quantity_delta tidak boleh nol.",
                        'ADJUSTMENT_ZERO_DELTA',
                        422,
                        ['index' => $index, 'product_id' => $productId],
                    );
                }
                $normalized[] = [
                    'product_id' => $productId,
                    'quantity_delta' => $delta,
                ];
            } else {
                if (!array_key_exists('new_average_cost', $line)) {
                    throw new DomainException(
                        "Item baris ke-" . ($index + 1) . " tidak memiliki new_average_cost.",
                        'ADJUSTMENT_INVALID_ITEM_SHAPE',
                        422,
                        ['index' => $index],
                    );
                }
                $newCost = (string) $line['new_average_cost'];
                if (!is_numeric($newCost) || (float) $newCost < 0) {
                    throw new DomainException(
                        "new_average_cost tidak boleh negatif.",
                        'ADJUSTMENT_INVALID_COST',
                        422,
                        ['index' => $index, 'product_id' => $productId],
                    );
                }
                $normalized[] = [
                    'product_id' => $productId,
                    'new_average_cost' => $newCost,
                ];
            }
        }

        return $normalized;
    }

    // -------------------------------------------------------------------
    // Guards
    // -------------------------------------------------------------------

    protected function assertValidType(string $type): void
    {
        if (!in_array($type, [self::TYPE_QUANTITY, self::TYPE_REVALUATION], true)) {
            throw new DomainException(
                "Tipe penyesuaian '{$type}' tidak didukung.",
                'ADJUSTMENT_INVALID_TYPE',
                422,
            );
        }
    }

    protected function assertPostable(InventoryAdjustment $adjustment): void
    {
        if ($adjustment->status !== self::STATUS_DRAFT) {
            throw new DomainException(
                "Hanya dokumen draft yang dapat diposting.",
                'ADJUSTMENT_NOT_POSTABLE',
                409,
                ['status' => $adjustment->status],
            );
        }
    }

    public function authorizeCreate(User $user, string $type): void
    {
        if ($type === self::TYPE_REVALUATION) {
            if (!($user->can('revalue-inventory-cost') || $user->hasRole('owner'))) {
                throw new DomainException(
                    'Hanya owner yang dapat membuat dokumen revaluasi biaya modal.',
                    'REVALUATION_FORBIDDEN',
                    403,
                );
            }
            return;
        }

        if (!($user->can('manage-inventory') || $user->hasAnyRole(['owner', 'manager']))) {
            throw new DomainException(
                'Anda tidak memiliki izin untuk membuat penyesuaian inventaris.',
                'INVENTORY_FORBIDDEN',
                403,
            );
        }
    }

    public function authorizePost(User $user, InventoryAdjustment $adjustment): void
    {
        if ($adjustment->type === self::TYPE_REVALUATION) {
            if (!($user->can('revalue-inventory-cost') || $user->hasRole('owner'))) {
                throw new DomainException(
                    'Hanya owner yang dapat memposting revaluasi biaya modal.',
                    'REVALUATION_FORBIDDEN',
                    403,
                );
            }
            return;
        }

        if (!($user->can('manage-inventory') || $user->hasAnyRole(['owner', 'manager']))) {
            throw new DomainException(
                'Anda tidak memiliki izin untuk memposting penyesuaian inventaris.',
                'INVENTORY_FORBIDDEN',
                403,
            );
        }
    }

    public function authorizeCancel(User $user, InventoryAdjustment $adjustment): void
    {
        // Cancel authorization mirrors create: revaluation needs owner.
        $this->authorizeCreate($user, $adjustment->type);
    }

    /**
     * Generate a human-readable adjustment number: ADJ-YYYYMMDD-XXXXX
     */
    protected function generateAdjustmentNumber(): string
    {
        $today = Carbon::now()->format('Ymd');
        $prefix = "ADJ-{$today}-";
        $latest = InventoryAdjustment::where('adjustment_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('adjustment_number');

        $next = 1;
        if ($latest && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $latest, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
