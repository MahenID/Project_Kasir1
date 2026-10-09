<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\ReceivingItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receiving (Penerimaan Barang) — Fase 4.A.
 *
 * State machine: draft -> approved -> posted
 *                                  \-> cancelled
 *
 * Only `posted` increments stock and updates the moving weighted-average
 * cost. Drafts are freely editable (add/update/remove lines). Approval
 * freezes the line set; posting executes the immutable stock and ledger
 * effects atomically and locks products in ascending ID order.
 */
class ReceivingService
{
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_OPENING = 'opening';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        protected StockService $stockService,
    ) {}

    /**
     * Create a new draft receiving with the initial set of line items.
     * Items may be empty at creation; use updateLines() to populate.
     *
     * @param array $items [['product_id' => int, 'quantity' => int, 'unit_cost' => string|int|float], ...]
     */
    public function createDraft(
        User $user,
        string $type,
        ?int $supplierId,
        ?string $externalReference,
        ?string $receivedAt,
        array $items,
    ): Receiving {
        $this->assertValidType($type);

        $normalizedItems = $this->normalizeAndValidateItems($items);

        return DB::transaction(function () use ($user, $type, $supplierId, $externalReference, $receivedAt, $normalizedItems) {
            $this->assertSupplierActive($supplierId);

            $receiving = Receiving::create([
                'receiving_number' => $this->generateReceivingNumber(),
                'type' => $type,
                'supplier_id' => $supplierId,
                'external_reference' => $externalReference,
                'status' => self::STATUS_DRAFT,
                'received_at' => $receivedAt ? Carbon::parse($receivedAt) : null,
                'created_by' => $user->id,
            ]);

            foreach ($normalizedItems as $line) {
                $this->createLine($receiving, $line);
            }

            return $receiving->fresh(['items.product']);
        });
    }

    /**
     * Replace the entire line set on a draft receiving.
     * Approves/posted/cancelled documents cannot be edited.
     */
    public function updateLines(Receiving $receiving, array $items): Receiving
    {
        $this->assertEditable($receiving);

        $normalizedItems = $this->normalizeAndValidateItems($items);

        return DB::transaction(function () use ($receiving, $normalizedItems) {
            $receiving->items()->delete();

            foreach ($normalizedItems as $line) {
                $this->createLine($receiving, $line);
            }

            $receiving->touch();

            return $receiving->fresh(['items.product']);
        });
    }

    /**
     * Mark the draft as approved. Only `manage-inventory` users may do this.
     * Approval freezes the line set: the document is no longer editable,
     * but no stock or cost change happens yet.
     */
    public function approve(User $user, Receiving $receiving): Receiving
    {
        $this->assertCanManageInventory($user);
        $this->assertApprovable($receiving);

        if ($receiving->items()->count() === 0) {
            throw new DomainException(
                'Dokumen penerimaan tanpa item tidak dapat disetujui.',
                'RECEIVING_EMPTY',
                422,
            );
        }

        $receiving->update([
            'status' => self::STATUS_APPROVED,
        ]);

        return $receiving->fresh(['items.product']);
    }

    /**
     * Post an approved receiving: increment stock per line, recalculate
     * moving weighted-average cost, and write the immutable stock_movements
     * ledger. Locks products in ascending ID order to prevent deadlocks.
     */
    public function post(User $user, Receiving $receiving): Receiving
    {
        $this->assertCanManageInventory($user);
        $this->assertPostable($receiving);

        $lines = $receiving->items()->with('product')->get();
        $this->assertAllProductsActive($lines);

        DB::transaction(function () use ($user, $receiving, $lines) {
            // Lock products in strictly ascending ID order
            $productIds = $lines->pluck('product_id')->sort()->values()->all();
            $lockedProducts = Product::whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lines as $line) {
                $product = $lockedProducts[$line->product_id];

                $movementType = $receiving->type === self::TYPE_OPENING
                    ? StockMovement::TYPE_RECEIVING_OPENING
                    : StockMovement::TYPE_RECEIVING_PURCHASE;

                $this->stockService->incrementStock(
                    product: $product,
                    quantity: (int) $line->quantity,
                    unitCost: (string) $line->unit_cost,
                    type: $movementType,
                    referenceType: 'receiving',
                    referenceId: $receiving->id,
                    referenceLineId: $line->id,
                    userId: $user->id,
                    reason: 'receiving:' . $receiving->receiving_number,
                );
            }

            $receiving->update([
                'status' => self::STATUS_POSTED,
                'posted_by' => $user->id,
                'received_at' => $receiving->received_at ?? Carbon::now(),
            ]);
        });

        return $receiving->fresh(['items.product', 'poster']);
    }

    /**
     * Cancel a draft or approved receiving. Stock is never decremented
     * (a cancellation only blocks a not-yet-posted document from being
     * posted). Posted documents are immutable; create a correction via
     * `inventory_adjustments` instead.
     */
    public function cancel(User $user, Receiving $receiving, ?string $notes = null): Receiving
    {
        $this->assertCanManageInventory($user);

        if (!in_array($receiving->status, [self::STATUS_DRAFT, self::STATUS_APPROVED], true)) {
            throw new DomainException(
                'Hanya dokumen draft atau yang sudah disetujui yang dapat dibatalkan.',
                'RECEIVING_NOT_CANCELLABLE',
                409,
                ['status' => $receiving->status],
            );
        }

        $receiving->update([
            'status' => self::STATUS_CANCELLED,
            'correction_notes' => $notes ?: $receiving->correction_notes,
        ]);

        return $receiving->fresh(['items.product']);
    }

    /**
     * Public, post-load representation of a receiving document with relations.
     */
    public function buildReceivingPayload(Receiving $receiving): array
    {
        $receiving->loadMissing(['items.product', 'supplier', 'creator', 'poster']);

        return [
            'id' => $receiving->id,
            'receiving_number' => $receiving->receiving_number,
            'type' => $receiving->type,
            'status' => $receiving->status,
            'supplier' => $receiving->supplier ? [
                'id' => $receiving->supplier->id,
                'code' => $receiving->supplier->code,
                'name' => $receiving->supplier->name,
            ] : null,
            'external_reference' => $receiving->external_reference,
            'received_at' => $receiving->received_at?->toIso8601String(),
            'created_by' => [
                'id' => $receiving->creator?->id,
                'name' => $receiving->creator?->name,
            ],
            'posted_by' => $receiving->poster ? [
                'id' => $receiving->poster->id,
                'name' => $receiving->poster->name,
            ] : null,
            'correction_notes' => $receiving->correction_notes,
            'items' => $receiving->items->map(fn (ReceivingItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'product_sku' => $item->product?->sku,
                'quantity' => (int) $item->quantity,
                'unit_cost' => (int) round((float) $item->unit_cost),
                'extended_cost' => (int) round((float) $item->extended_cost),
            ])->values()->all(),
            'totals' => [
                'total_quantity' => (int) $receiving->items->sum('quantity'),
                'total_extended_cost' => (int) round((float) $receiving->items->sum('extended_cost')),
            ],
            'created_at' => $receiving->created_at?->toIso8601String(),
            'updated_at' => $receiving->updated_at?->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    protected function createLine(Receiving $receiving, array $line): ReceivingItem
    {
        $product = Product::find($line['product_id']);
        $unitCost = (string) $line['unit_cost'];
        $quantity = (int) $line['quantity'];
        $extendedCost = bcmul($unitCost, (string) $quantity, 6);

        $snapshot = [
            'sku' => $product->sku,
            'name' => $product->name,
            'unit' => $product->unit,
            'unit_cost' => $unitCost,
            'commercial_version' => $product->commercial_version,
        ];

        return ReceivingItem::create([
            'receiving_id' => $receiving->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'extended_cost' => $extendedCost,
            'product_snapshot' => $snapshot,
        ]);
    }

    protected function normalizeAndValidateItems(array $items): array
    {
        if (!is_array($items)) {
            throw new DomainException(
                'Daftar item tidak valid.',
                'RECEIVING_INVALID_ITEMS',
                422,
            );
        }

        $normalized = [];
        $seenProductIds = [];

        foreach ($items as $index => $line) {
            if (!is_array($line) || !isset($line['product_id'], $line['quantity'], $line['unit_cost'])) {
                throw new DomainException(
                    "Item baris ke-" . ($index + 1) . " tidak memiliki product_id, quantity, atau unit_cost.",
                    'RECEIVING_INVALID_ITEM_SHAPE',
                    422,
                    ['index' => $index],
                );
            }

            $productId = (int) $line['product_id'];
            $quantity = (int) $line['quantity'];
            $unitCost = (string) $line['unit_cost'];

            if (isset($seenProductIds[$productId])) {
                throw new DomainException(
                    "Produk dengan id {$productId} muncul lebih dari satu kali pada item.",
                    'RECEIVING_DUPLICATE_PRODUCT',
                    422,
                    ['product_id' => $productId],
                );
            }
            $seenProductIds[$productId] = true;

            if ($quantity <= 0) {
                throw new DomainException(
                    "Kuantitas item harus lebih besar dari nol.",
                    'RECEIVING_INVALID_QUANTITY',
                    422,
                    ['index' => $index, 'product_id' => $productId],
                );
            }

            if (!is_numeric($unitCost) || (float) $unitCost < 0) {
                throw new DomainException(
                    "Harga modal satuan tidak boleh negatif.",
                    'RECEIVING_INVALID_UNIT_COST',
                    422,
                    ['index' => $index, 'product_id' => $productId],
                );
            }

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
                    "Produk {$product->sku} tidak aktif dan tidak dapat diterima.",
                    'PRODUCT_INACTIVE',
                    422,
                    ['product_id' => $productId],
                );
            }

            $normalized[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ];
        }

        return $normalized;
    }

    protected function assertValidType(string $type): void
    {
        if (!in_array($type, [self::TYPE_PURCHASE, self::TYPE_OPENING], true)) {
            throw new DomainException(
                "Tipe penerimaan '{$type}' tidak didukung.",
                'RECEIVING_INVALID_TYPE',
                422,
            );
        }
    }

    protected function assertSupplierActive(?int $supplierId): void
    {
        if ($supplierId === null) {
            return;
        }
        $supplier = Supplier::find($supplierId);
        if (!$supplier) {
            throw new DomainException(
                "Supplier tidak ditemukan.",
                'SUPPLIER_NOT_FOUND',
                422,
                ['supplier_id' => $supplierId],
            );
        }
    }

    protected function assertAllProductsActive(iterable $lines): void
    {
        foreach ($lines as $line) {
            if ($line->product && !$line->product->is_active) {
                throw new DomainException(
                    "Produk {$line->product->sku} tidak aktif; dokumen tidak dapat diposting.",
                    'PRODUCT_INACTIVE',
                    409,
                    ['product_id' => $line->product_id],
                );
            }
        }
    }

    protected function assertEditable(Receiving $receiving): void
    {
        if ($receiving->status !== self::STATUS_DRAFT) {
            throw new DomainException(
                "Dokumen dengan status '{$receiving->status}' tidak dapat diedit.",
                'RECEIVING_NOT_EDITABLE',
                409,
                ['status' => $receiving->status],
            );
        }
    }

    protected function assertApprovable(Receiving $receiving): void
    {
        if ($receiving->status !== self::STATUS_DRAFT) {
            throw new DomainException(
                "Hanya dokumen draft yang dapat disetujui.",
                'RECEIVING_NOT_APPROVABLE',
                409,
                ['status' => $receiving->status],
            );
        }
    }

    protected function assertPostable(Receiving $receiving): void
    {
        if ($receiving->status !== self::STATUS_APPROVED) {
            throw new DomainException(
                "Hanya dokumen yang sudah disetujui yang dapat diposting.",
                'RECEIVING_NOT_POSTABLE',
                409,
                ['status' => $receiving->status],
            );
        }
    }

    protected function assertCanManageInventory(User $user): void
    {
        if ($user->can('manage-inventory') || $user->hasAnyRole(['owner', 'manager'])) {
            return;
        }
        throw new DomainException(
            'Anda tidak memiliki izin untuk mengelola inventaris.',
            'INVENTORY_FORBIDDEN',
            403,
        );
    }

    /**
     * Generate a human-readable receiving number: RCV-YYYYMMDD-XXXXX
     * Sequential per calendar day, derived inside the transaction.
     */
    protected function generateReceivingNumber(): string
    {
        $today = Carbon::now()->format('Ymd');
        $prefix = "RCV-{$today}-";
        $latest = Receiving::where('receiving_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('receiving_number');

        $next = 1;
        if ($latest && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $latest, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
