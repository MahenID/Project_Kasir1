<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\InventoryControl;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\StoreSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Returns & Refunds (Retur & Pengembalian Dana) — Fase 4.
 *
 * State machine: requested -> approved -> completed
 *                              \-> rejected (from requested)
 *                              \-> cancelled (from requested or approved)
 *
 * Financial/stock effects happen ONLY on completion, atomically with the
 * refund, stock restock movements, derived sale/payment statuses, and the
 * durable idempotency outcome (PRD §6.2).
 */
class ReturnService
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const DISPOSITION_RESTOCK = 'restockable';
    public const DISPOSITION_DAMAGED = 'damaged';

    /** Approval validity window (PRD §6.2: 24 hours). */
    public const APPROVAL_TTL_HOURS = 24;

    public function __construct(
        protected StockService $stockService,
        protected IdempotencyService $idempotencyService,
        protected ShiftService $shiftService,
    ) {}

    /**
     * Look up a completed sale by exact receipt number for return preparation
     * (FR-RET-01). Cashiers only ever see receipt/item/refund-eligibility data:
     * never cost, margin, or unrelated customer info.
     */
    public function lookupByReceipt(User $user, string $receiptNumber): array
    {
        $sale = Sale::with(['items', 'payment'])->where('receipt_number', $receiptNumber)->first();

        if (!$sale) {
            throw new DomainException(
                'Struk tidak ditemukan. Periksa kembali nomor struk.',
                'SALE_NOT_FOUND',
                404
            );
        }

        if ($sale->status !== 'completed') {
            throw new DomainException(
                'Struk ini tidak dalam status selesai dan tidak dapat diretur.',
                'SALE_NOT_RETURNABLE',
                422
            );
        }

        $settings = StoreSetting::first();
        $windowDays = $settings?->return_window_days ?? 7;
        $timezone = $settings?->timezone ?? 'Asia/Makassar';

        $windowEndsAt = Carbon::parse($sale->completed_at)->setTimezone($timezone)->addDays($windowDays);
        $withinWindow = Carbon::now('UTC')->lessThanOrEqualTo($windowEndsAt);

        $returnedBySaleItem = $this->returnedQuantityMap((int) $sale->id);

        $lines = $sale->items->map(function (SaleItem $item) use ($returnedBySaleItem) {
            $alreadyReturned = $returnedBySaleItem[$item->id] ?? 0;
            return [
                'sale_item_id' => $item->id,
                'sku' => $item->sku_snapshot,
                'name' => $item->name_snapshot,
                'unit' => $item->unit_snapshot,
                'quantity_sold' => $item->quantity,
                'quantity_returnable' => $item->quantity - $alreadyReturned,
                'quantity_already_returned' => $alreadyReturned,
                'unit_price' => (string) $item->unit_price,
                'line_net_amount' => (string) $item->net_amount,
                'line_tax_amount' => (string) $item->tax_amount,
                'line_subtotal' => (string) $item->subtotal,
            ];
        })->values()->all();

        return [
            'sale_id' => $sale->id,
            'receipt_number' => $sale->receipt_number,
            'completed_at' => $sale->completed_at?->toIso8601String(),
            'store_time' => Carbon::parse($sale->completed_at)->setTimezone($timezone)->toIso8601String(),
            'cashier_name' => $sale->cashier_name_snapshot,
            'terminal_code' => $sale->terminal_code_snapshot,
            'grand_total' => (string) $sale->grand_total,
            'payment_method' => $sale->payment?->method,
            'payment_refund_status' => $sale->payment?->refund_status,
            'return_status' => $sale->return_status,
            'return_window_days' => $windowDays,
            'return_window_ends_at' => $windowEndsAt->toIso8601String(),
            'within_return_window' => $withinWindow,
            'requires_window_override' => !$withinWindow,
            'items' => $lines,
        ];
    }

    /**
     * Create a return request referencing original sale items (FR-RET-01).
     *
     * @param array $items [['sale_item_id'=>int,'quantity'=>int,'disposition'=>string,'reason'=>string], ...]
     */
    public function createRequest(
        User $user,
        int $saleId,
        string $reason,
        array $items,
        ?string $overrideReason = null
    ): ReturnRequest {
        $sale = Sale::find($saleId);
        if (!$sale || $sale->status !== 'completed') {
            throw new DomainException(
                'Transaksi asal tidak ditemukan atau tidak selesai.',
                'SALE_NOT_FOUND',
                404
            );
        }

        $settings = StoreSetting::first();
        $windowDays = $settings?->return_window_days ?? 7;
        $user = User::findOrFail($user->id);
        $windowEndsAt = Carbon::parse($sale->completed_at)->setTimezone($settings?->timezone ?? 'Asia/Makassar')->addDays($windowDays);
        $expired = Carbon::now('UTC')->greaterThan($windowEndsAt);

        if ($expired) {
            if (!$user->is_active || !$user->hasRole('owner') || trim((string) $overrideReason) === '') {
                throw new DomainException(
                    "Batas waktu retur {$windowDays} hari telah lewat. Override owner wajib disertai alasan.",
                    'RETURN_WINDOW_EXPIRED',
                    422
                );
            }
        }

        $normalized = $this->normalizeLines($items);

        return DB::transaction(function () use ($user, $sale, $reason, $normalized, $expired, $overrideReason) {
            $this->assertQuantitiesWithinReturnable($sale, $normalized);

            $request = ReturnRequest::create([
                'request_number' => $this->generateRequestNumber(),
                'sale_id' => $sale->id,
                'requested_by' => $user->id,
                'status' => self::STATUS_REQUESTED,
                'reason' => $reason,
                'override_reason' => $expired ? $overrideReason : null,
            ]);

            foreach ($normalized as $line) {
                ReturnItem::create([
                    'return_request_id' => $request->id,
                    'sale_item_id' => $line['sale_item_id'],
                    'quantity' => $line['quantity'],
                    'disposition' => $line['disposition'],
                    'reason' => $line['reason'],
                ]);
            }

            activity('returns')
                ->performedOn($request)
                ->causedBy($user)
                ->withProperties([
                    'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-ID'),
                    'return_request_id' => $request->id,
                    'request_number' => $request->request_number,
                    'sale_id' => $sale->id,
                    'receipt_number' => $sale->receipt_number,
                    'override_reason' => $expired ? $overrideReason : null,
                ])
                ->log('return.requested');

            if ($expired) {
                activity('returns')->performedOn($request)->causedBy($user)
                    ->withProperties([
                        'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-ID'),
                        'return_request_id' => $request->id,
                        'override_reason' => $overrideReason,
                    ])
                    ->log('return.window_overridden');
            }

            return $request;
        });
    }

    /**
     * Approve a pending request (FR-RET-02). Approval binds the exact line
     * content via a hash and expires after APPROVAL_TTL_HOURS. Any later line
     * change invalidates the approval; completion re-checks the hash.
     */
    public function approve(User $user, ReturnRequest $request): ReturnRequest
    {
        $this->assertSupervisor($user);

        return DB::transaction(function () use ($user, $request) {
            $locked = ReturnRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            $now = Carbon::now('UTC');
            $expiredApproval = $locked->status === self::STATUS_APPROVED
                && $locked->approval_expires_at
                && $now->greaterThanOrEqualTo($locked->approval_expires_at);
            if ($locked->status !== self::STATUS_REQUESTED && !$expiredApproval) {
                throw new DomainException(
                    'Hanya pengajuan baru atau persetujuan kedaluwarsa yang dapat disetujui.',
                    'RETURN_NOT_PENDING',
                    409
                );
            }

            if ($expiredApproval && $locked->approval_hash !== $this->contentHash($locked)) {
                throw new DomainException('Isi pengajuan berubah; batalkan dan buat pengajuan baru.', 'RETURN_APPROVAL_STALE', 409);
            }
            if ($locked->items()->count() === 0) {
                throw new DomainException(
                    'Pengajuan retur tanpa item tidak dapat disetujui.',
                    'RETURN_EMPTY',
                    422
                );
            }

            $now = Carbon::now('UTC');
            $locked->update([
                'status' => self::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => $now,
                'approval_hash' => $this->contentHash($locked),
                'approval_expires_at' => $now->copy()->addHours(self::APPROVAL_TTL_HOURS),
            ]);

            activity('returns')
                ->performedOn($locked)
                ->causedBy($user)
                ->withProperties([
                    'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-ID'),
                    'return_request_id' => $locked->id,
                    'request_number' => $locked->request_number,
                    'self_approved' => (int) $locked->requested_by === (int) $user->id,
                    'approval_expires_at' => $locked->approval_expires_at->toIso8601String(),
                ])
                ->log('return.approved');

            return $locked;
        });
    }

    /**
     * Reject a pending request. No financial effect is possible.
     */
    public function reject(User $user, ReturnRequest $request, string $reason): ReturnRequest
    {
        $this->assertSupervisor($user);

        return DB::transaction(function () use ($user, $request, $reason) {
            $locked = ReturnRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== self::STATUS_REQUESTED) {
                throw new DomainException(
                    'Hanya pengajuan berstatus requested yang dapat ditolak.',
                    'RETURN_NOT_PENDING',
                    409
                );
            }

            $locked->update(['status' => self::STATUS_REJECTED]);

            activity('returns')
                ->performedOn($locked)
                ->causedBy($user)
                ->withProperties([
                    'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-ID'),
                    'return_request_id' => $locked->id,
                    'request_number' => $locked->request_number,
                    'rejection_reason' => $reason,
                ])
                ->log('return.rejected');

            return $locked;
        });
    }

    /**
     * Cancel a request. The requester may cancel their own pending request;
     * supervisors may cancel pending or approved requests. Completed returns
     * are immutable (PRD §6.2).
     */
    public function cancel(User $user, ReturnRequest $request, string $reason): ReturnRequest
    {
        return DB::transaction(function () use ($user, $request, $reason) {
            $locked = ReturnRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            $user = User::findOrFail($user->id);
            $isSupervisor = $user->is_active && $user->hasAnyRole(['owner', 'manager']);
            $isOwnCancellable = (int) $locked->requested_by === (int) $user->id
                && in_array($locked->status, [self::STATUS_REQUESTED, self::STATUS_APPROVED], true);

            if (!$user->is_active || (!$isSupervisor && !$isOwnCancellable)) {
                throw new DomainException(
                    'Anda tidak berwenang membatalkan pengajuan ini.',
                    'FORBIDDEN',
                    403
                );
            }

            if (!in_array($locked->status, [self::STATUS_REQUESTED, self::STATUS_APPROVED], true)) {
                throw new DomainException(
                    'Pengajuan retur yang sudah selesai/ditolak/dibatalkan tidak dapat diubah.',
                    'RETURN_NOT_CANCELLABLE',
                    409
                );
            }

            $locked->update(['status' => self::STATUS_CANCELLED]);

            activity('returns')
                ->performedOn($locked)
                ->causedBy($user)
                ->withProperties([
                    'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-ID'),
                    'return_request_id' => $locked->id,
                    'request_number' => $locked->request_number,
                    'cancellation_reason' => $reason,
                ])
                ->log('return.cancelled');

            return $locked;
        });
    }

    /**
     * Kunci baris kontrol inventaris singleton untuk serialisasi transaksi.
     * Berbeda dengan StocktakeService yang membuat baris saat stocktake
     * dimulai, refund dapat diproses sebelum stocktake pertama. Tanpa baris
     * kontrol, firstOrFail() gagal dengan 404 dan refund tidak dapat
     * diselesaikan. Pola firstOrCreate menjaga serialisasi transaksi dan
     * membuat baris singleton bila belum ada.
     */
    protected function lockOrCreateInventoryControl(): void
    {
        $control = InventoryControl::lockForUpdate()->first();
        if (!$control) {
            $control = InventoryControl::create(['active_stocktake_id' => null]);
            InventoryControl::lockForUpdate()->find($control->id);
        }
    }

    /**
     * Complete an approved return with its refund, atomically and once
     * (FR-RET-03). Idempotent by (user, 'refund-complete', key).
     *
     * @param array $canonicalPayload payload hashed for durable idempotency
     * @return array the idempotent response snapshot
     */
    public function complete(
        User $user,
        ReturnRequest $request,
        int $shiftId,
        ?int $confirmedCashAmount,
        ?string $refundReference,
        string $idempotencyKey,
        array $canonicalPayload
    ): array {
        $nonCashConfirmed = ($canonicalPayload['non_cash_confirmed'] ?? false) === true;
        $canonicalPayload = [
            'return_request_id' => (int) $request->id,
            'shift_id' => $shiftId,
            'confirmed_cash_amount' => $confirmedCashAmount,
            'refund_reference' => $refundReference,
            'non_cash_confirmed' => $nonCashConfirmed,
        ];
        return DB::transaction(function () use ($user, $request, $shiftId, $confirmedCashAmount, $refundReference, $idempotencyKey, $canonicalPayload) {
            $this->lockOrCreateInventoryControl();

            return $this->idempotencyService->execute(
                $user->id,
                'refund-complete',
                $idempotencyKey,
                $canonicalPayload,
                fn () => $this->postCompletion($user, $request, $shiftId, $confirmedCashAmount, $refundReference, $canonicalPayload['non_cash_confirmed'])
            );
        }, 3);
    }

    /**
     * Atomic transactional body of a return completion.
     *
     * Lock order: inventory guard, handling shift, request, original sale,
     * payment, then products in ascending ID order.
     */
    protected function postCompletion(
        User $user,
        ReturnRequest $request,
        int $shiftId,
        ?int $confirmedCashAmount,
        ?string $refundReference,
        bool $nonCashConfirmed
    ): array {
        // Retry on deadlock / lock wait timeout (MySQL 1213 / 1205).
        return DB::transaction(function () use ($user, $request, $shiftId, $confirmedCashAmount, $refundReference, $nonCashConfirmed) {
            $this->lockOrCreateInventoryControl();
            $this->stockService->checkInventoryGuard();
            $shift = Shift::whereKey($shiftId)->lockForUpdate()->first();
            $user = User::findOrFail($user->id);
            if (!$user->is_active || !$shift || (int) $shift->user_id !== (int) $user->id || $shift->status !== 'open') {
                throw new DomainException('Pengembalian dana harus diproses pada shift milik Anda yang sedang terbuka.', 'SHIFT_NOT_OPEN', 409);
            }
            // 1. Freeze the request and re-verify its state.
            $locked = ReturnRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== self::STATUS_APPROVED) {
                throw new DomainException(
                    'Hanya pengajuan retur yang disetujui dapat diselesaikan.',
                    'RETURN_NOT_APPROVED',
                    409
                );
            }

            $now = Carbon::now('UTC');
            if (!$locked->approval_expires_at || $now->greaterThanOrEqualTo($locked->approval_expires_at)) {
                throw new DomainException(
                    'Persetujuan retur telah kedaluwarsa (24 jam). Ajukan dan setujui ulang.',
                    'RETURN_APPROVAL_EXPIRED',
                    409
                );
            }

            if ($locked->approval_hash !== $this->contentHash($locked)) {
                throw new DomainException(
                    'Isi pengajuan berubah sejak disetujui. Persetujuan tidak berlaku.',
                    'RETURN_APPROVAL_STALE',
                    409
                );
            }

            $approver = User::find($locked->approved_by);
            if (!$approver || !$approver->is_active || !$approver->hasAnyRole(['owner', 'manager'])) {
                throw new DomainException('Pemberi persetujuan tidak lagi berwenang.', 'RETURN_APPROVER_UNAUTHORIZED', 409);
            }

            // 3. Lock the original sale + its payment.
            $sale = Sale::whereKey($locked->sale_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::where('sale_id', $sale->id)->lockForUpdate()->first();

            if (!$payment) {
                throw new DomainException(
                    'Pembayaran transaksi asal tidak ditemukan.',
                    'PAYMENT_NOT_FOUND',
                    404
                );
            }


            // 5. Recheck remaining quantities against all completed returns.
            $saleItems = SaleItem::where('sale_id', $sale->id)->get()->keyBy('id');
            $lines = $locked->items()->orderBy('id')->get();
            $returnedMap = $this->returnedQuantityMap((int) $sale->id, excludeRequestId: (int) $locked->id, completedOnly: true);

            $perSaleItemQty = [];
            foreach ($lines as $line) {
                if (!isset($saleItems[$line->sale_item_id])) {
                    throw new DomainException(
                        'Baris retur tidak cocok dengan transaksi asal.',
                        'RETURN_LINE_INVALID',
                        422
                    );
                }
                $perSaleItemQty[$line->sale_item_id] = ($perSaleItemQty[$line->sale_item_id] ?? 0) + (int) $line->quantity;
            }
            foreach ($perSaleItemQty as $saleItemId => $qty) {
                $remaining = (int) $saleItems[$saleItemId]->quantity - ($returnedMap[$saleItemId] ?? 0);
                if ($qty > $remaining) {
                    throw new DomainException(
                        "Kuantitas retur '{$saleItems[$saleItemId]->name_snapshot}' melebihi sisa yang dapat diretur (sisa: {$remaining}).",
                        'RETURN_QUANTITY_EXCEEDED',
                        409,
                        ['sale_item_id' => $saleItemId, 'remaining_returnable' => $remaining]
                    );
                }
            }

            // 6. Compute refund allocation (PRD §6.2 cumulative round_half_up).
            $refundNet = 0;
            $refundTax = 0;
            $restockCostTotal = '0';
            $lineAmounts = [];

            foreach ($lines as $line) {
                $item = $saleItems[$line->sale_item_id];
                $qty = (int) $line->quantity;
                $priorQty = $returnedMap[$line->sale_item_id] ?? 0;
                $newQty = $priorQty + $qty;
                $returnedMap[$line->sale_item_id] = $newQty;
                $Q = (int) $item->quantity;

                $cumNet = $this->cumulativeComponent((int) $item->net_amount, $newQty, $Q);
                $prevNet = $this->cumulativeComponent((int) $item->net_amount, $priorQty, $Q);
                $cumTax = $this->cumulativeComponent((int) $item->tax_amount, $newQty, $Q);
                $prevTax = $this->cumulativeComponent((int) $item->tax_amount, $priorQty, $Q);

                $lineNet = $cumNet - $prevNet;
                $lineTax = $cumTax - $prevTax;
                $lineAmounts[$line->id] = ['net' => $lineNet, 'tax' => $lineTax, 'total' => $lineNet + $lineTax];
                $refundNet += $lineNet;
                $refundTax += $lineTax;
            }

            $refundTotal = $refundNet + $refundTax;
            if ($refundTotal < 0) {
                throw new DomainException(
                    'Nilai pengembalian dana tidak boleh negatif.',
                    'REFUND_AMOUNT_INVALID',
                    422
                );
            }

            // 7. Payment-method rules and drawer sufficiency. The refunded-sum
            //    guard applies to every method: the original applied payment
            //    amount is immutable and caps the cumulative refunds.
            $method = $payment->method;
            $alreadyRefunded = (int) Refund::where('payment_id', $payment->id)->sum('amount');
            if ($alreadyRefunded + $refundTotal > (int) $payment->amount_due) {
                throw new DomainException(
                    'Total pengembalian dana melebihi nilai pembayaran asal.',
                    'REFUND_EXCEEDS_PAYMENT',
                    409
                );
            }
            if ($method === 'cash') {
                if ($confirmedCashAmount === null) {
                    throw new DomainException(
                        'Refund tunai wajib menyertakan jumlah uang yang sudah dihitung operator (confirmed_cash_amount).',
                        'CONFIRMED_CASH_REQUIRED',
                        422
                    );
                }
                if ($confirmedCashAmount !== $refundTotal) {
                    throw new DomainException(
                        'Jumlah kas yang dikonfirmasi harus sama persis dengan nilai pengembalian dana.',
                        'CONFIRMED_CASH_MISMATCH',
                        422,
                        ['expected' => $refundTotal, 'confirmed' => $confirmedCashAmount]
                    );
                }
                $xReport = $this->shiftService->calculateXReport($shift);
                if ($refundTotal > $xReport['expected_cash']) {
                    throw new DomainException(
                        'Kas laci tidak mencukupi untuk pengembalian dana. Catat kas masuk berizin terlebih dahulu.',
                        'REFUND_CASH_INSUFFICIENT',
                        409,
                        ['expected_cash' => $xReport['expected_cash'], 'refund_amount' => $refundTotal]
                    );
                }

            } else {
                if (!$nonCashConfirmed) {
                    throw new DomainException('Konfirmasi refund non-tunai selesai wajib diberikan.', 'NON_CASH_CONFIRMATION_REQUIRED', 422);
                }
                if (empty($refundReference)) {
                    throw new DomainException(
                        'Refund non-tunai wajib menyertakan nomor referensi pembatalan/retur dari penyedia.',
                        'REFUND_REFERENCE_REQUIRED',
                        422
                    );
                }
            }

            // Restockable returns need product locks, not existing sellable stock.
            $restockByProduct = [];
            foreach ($lines as $line) {
                if ($line->disposition === self::DISPOSITION_RESTOCK) {
                    $pid = (int) $saleItems[$line->sale_item_id]->product_id;
                    $restockByProduct[$pid] = ($restockByProduct[$pid] ?? 0) + (int) $line->quantity;
                }
            }
            $lockedProducts = $restockByProduct !== []
                ? Product::whereIn('id', array_keys($restockByProduct))->orderBy('id')->lockForUpdate()->get()->keyBy('id')
                : collect();
            if ($lockedProducts->count() !== count($restockByProduct)) {
                throw new DomainException('Produk retur tidak ditemukan.', 'RETURN_PRODUCT_NOT_FOUND', 409);
            }
            // 9. Persist allocation, stock restock, refund, derived statuses.
            foreach ($lines as $line) {
                $item = $saleItems[$line->sale_item_id];
                $amounts = $lineAmounts[$line->id];
                $line->update([
                    'refund_net_amount' => $amounts['net'],
                    'refund_tax_amount' => $amounts['tax'],
                    'refund_total_amount' => $amounts['total'],
                    'restored_cost_snapshot' => $line->disposition === self::DISPOSITION_RESTOCK
                        ? (string) $item->cost_price_snapshot
                        : null,
                ]);

                if ($line->disposition === self::DISPOSITION_RESTOCK) {
                    $product = $lockedProducts->get((int) $item->product_id);
                    $this->stockService->incrementStock(
                        product: $product,
                        quantity: (int) $line->quantity,
                        unitCost: (string) $item->cost_price_snapshot,
                        type: 'return_restock',
                        referenceType: 'return_request',
                        referenceId: (int) $locked->id,
                        referenceLineId: (int) $line->id,
                        userId: (int) $user->id,
                        reason: "Retur {$locked->request_number} (restock)",
                    );
                    $restockCostTotal = bcadd(
                        $restockCostTotal,
                        bcmul((string) $line->quantity, (string) $item->cost_price_snapshot, 6),
                        6
                    );
                }
            }

            $refund = Refund::create([
                'return_request_id' => $locked->id,
                'payment_id' => $payment->id,
                'handling_shift_id' => $shift->id,
                'method' => $method,
                'amount' => $refundTotal,
                'reference_number' => $method === 'cash' ? null : $refundReference,
                'processed_by' => $user->id,
                'completed_at' => $now,
            ]);

            // Derived statuses: payment refund status and sale return status.
            $totalRefundedAfter = $alreadyRefunded + $refundTotal;
            $payment->update([
                'refund_status' => $totalRefundedAfter >= (int) $payment->amount_due ? 'full' : 'partial',
            ]);

            $locked->update([
                'status' => self::STATUS_COMPLETED,
                'completed_by' => $user->id,
                'completed_at' => $now,
                'handling_shift_id' => $shift->id,
            ]);
            $sale->update(['return_status' => $this->deriveSaleReturnStatus($sale, $now)]);

            activity('returns')
                ->performedOn($locked)
                ->causedBy($user)
                ->withProperties([
                    'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-ID'),
                    'return_request_id' => $locked->id,
                    'request_number' => $locked->request_number,
                    'sale_id' => $sale->id,
                    'receipt_number' => $sale->receipt_number,
                    'refund_id' => $refund->id,
                    'refund_method' => $method,
                    'refund_amount' => $refundTotal,
                    'handling_shift_id' => $shift->id,
                    'refunded_net' => $refundNet,
                    'refunded_tax' => $refundTax,
                    'restock_cogs_reversed' => $restockCostTotal,
                ])
                ->log('return.completed');

            return [
                'resource_type' => 'return_request',
                'resource_id' => $locked->id,
                'response' => $this->buildReturnPayload($locked->fresh(['items', 'refund'])),
            ];
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function assertSupervisor(User $user): void
    {
        $current = User::find($user->id);
        if (!$current || !$current->is_active || !$current->hasAnyRole(['owner', 'manager'])) {
            throw new DomainException(
                'Persetujuan retur hanya dapat dilakukan owner/manager.',
                'FORBIDDEN',
                403
            );
        }
    }

    /**
     * Normalize raw request lines.
     */
    protected function normalizeLines(array $items): array
    {
        $normalized = [];
        foreach ($items as $line) {
            if (trim((string) ($line['reason'] ?? '')) === '') {
                throw new DomainException('Alasan setiap item retur wajib diisi.', 'RETURN_ITEM_REASON_REQUIRED', 422);
            }
            $normalized[] = [
                'sale_item_id' => (int) $line['sale_item_id'],
                'quantity' => (int) $line['quantity'],
                'disposition' => (string) ($line['disposition'] ?? self::DISPOSITION_RESTOCK),
                'reason' => (string) ($line['reason'] ?? ''),
            ];
        }
        return $normalized;
    }

    /**
     * Creation-time quantity check. Completion repeats the check under locks;
     * requests intentionally do not reserve quantity (PRD §6.2).
     */
    protected function assertQuantitiesWithinReturnable(Sale $sale, array $lines): void
    {
        if ($lines === []) {
            throw new DomainException(
                'Pengajuan retur wajib memiliki minimal satu baris item.',
                'RETURN_EMPTY',
                422
            );
        }

        $saleItems = SaleItem::where('sale_id', $sale->id)->get()->keyBy('id');
        $returnedMap = $this->returnedQuantityMap((int) $sale->id);

        $printStats = [];
        foreach ($lines as $line) {
            if (!isset($saleItems[$line['sale_item_id']])) {
                throw new DomainException(
                    'Baris retur merujuk item yang bukan bagian struk ini.',
                    'RETURN_LINE_INVALID',
                    422,
                    ['sale_item_id' => $line['sale_item_id']]
                );
            }
            if ($line['quantity'] < 1) {
                throw new DomainException(
                    'Kuantitas retur minimal 1 unit per baris.',
                    'RETURN_QUANTITY_INVALID',
                    422
                );
            }
            if (!in_array($line['disposition'], [self::DISPOSITION_RESTOCK, self::DISPOSITION_DAMAGED], true)) {
                throw new DomainException(
                    'Disposisi item retur tidak dikenali.',
                    'RETURN_DISPOSITION_INVALID',
                    422
                );
            }
            $printStats[$line['sale_item_id']] = ($printStats[$line['sale_item_id']] ?? 0) + $line['quantity'];
        }

        foreach ($printStats as $saleItemId => $qty) {
            $remaining = (int) $saleItems[$saleItemId]->quantity - ($returnedMap[$saleItemId] ?? 0);
            if ($qty > $remaining) {
                throw new DomainException(
                    "Kuantitas retur '{$saleItems[$saleItemId]->name_snapshot}' melebihi sisa yang dapat diretur (sisa: {$remaining}).",
                    'RETURN_QUANTITY_EXCEEDED',
                    422,
                    ['sale_item_id' => $saleItemId, 'remaining_returnable' => $remaining]
                );
            }
        }
    }

    /**
     * Map of sale_item_id => already-returned quantity. By default only
     * completed-counted quantities are summed (PRD §6.2: drafts/approved
     * requests do not reserve quantity).
     *
     * @return array<int,int>
     */
    protected function returnedQuantityMap(
        int $saleId,
        ?int $excludeRequestId = null,
        bool $completedOnly = true
    ): array {
        $query = ReturnItem::query()
            ->selectRaw('return_items.sale_item_id, SUM(return_items.quantity) as qty')
            ->join('return_requests', 'return_requests.id', '=', 'return_items.return_request_id')
            ->where('return_requests.sale_id', $saleId)
            ->groupBy('return_items.sale_item_id');

        if ($completedOnly) {
            $query->where('return_requests.status', self::STATUS_COMPLETED);
        } else {
            $query->whereIn('return_requests.status', [self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_COMPLETED]);
        }

        if ($excludeRequestId !== null) {
            $query->where('return_items.return_request_id', '!=', $excludeRequestId);
        }

        return $query->pluck('qty', 'sale_item_id')->map(fn ($q) => (int) $q)->all();
    }

    /**
     * Cumulative refund component for k of Q sold units against original
     * component M: round_half_up(M * k / Q) (PRD §6.2).
     */
    protected function cumulativeComponent(int $component, int $k, int $q): int
    {
        if ($k <= 0) {
            return 0;
        }
        if ($k >= $q) {
            return $component;
        }
        $numerator = bcadd(bcmul(bcmul((string) $component, (string) $k, 0), '2', 0), (string) $q, 0);
        return (int) bcdiv($numerator, bcmul((string) $q, '2', 0), 0);
    }

    /**
     * Deterministic hash of the approved line content. Any line edit or
     * reassignment changes the hash and invalidates approval (FR-RET-02).
     */
    protected function contentHash(ReturnRequest $request): string
    {
        $lines = ReturnItem::where('return_request_id', $request->id)
            ->orderBy('sale_item_id')
            ->orderBy('id')
            ->get()
            ->map(fn (ReturnItem $l) => [
                'sale_item_id' => (int) $l->sale_item_id,
                'id' => (int) $l->id,
                'reason' => $l->reason,
                'quantity' => (int) $l->quantity,
                'disposition' => $l->disposition,
            ])
            ->all();

        return IdempotencyService::hashPayload([
            'sale_id' => (int) $request->sale_id,
            'id' => (int) $request->id,
            'request_number' => $request->request_number,
            'requested_by' => (int) $request->requested_by,
            'reason' => $request->reason,
            'override_reason' => $request->override_reason,
            'created_at' => $request->created_at?->toIso8601String(),
            'lines' => $lines,
        ]);
    }

    /**
     * Derived sale return status: `full` when every unit of every line has a
     * completed return, otherwise `partial`.
     */
    protected function deriveSaleReturnStatus(Sale $sale, Carbon $now): string
    {
        $totalUnits = (int) SaleItem::where('sale_id', $sale->id)->sum('quantity');
        $returnedUnits = array_sum($this->returnedQuantityMap((int) $sale->id));

        return ($totalUnits > 0 && $returnedUnits >= $totalUnits) ? 'full' : 'partial';
    }

    protected function generateRequestNumber(): string
    {
        $today = Carbon::now('UTC')->format('Ymd');
        $prefix = "RTN-{$today}-";
        $latest = ReturnRequest::where('request_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('request_number');

        $next = 1;
        if ($latest && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $latest, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Full return payload. Cost/margin fields are included only for users
     * allowed to see costs (cashiers are not).
     */
    public function buildReturnPayload(ReturnRequest $request, bool $includeCosts = false): array
    {
        $request->loadMissing(['items', 'refund', 'requester', 'approver', 'completer', 'sale:id,receipt_number,grand_total,return_status']);

        $payload = [
            'id' => $request->id,
            'request_number' => $request->request_number,
            'status' => $request->status,
            'sale_id' => $request->sale_id,
            'receipt_number' => $request->sale?->receipt_number,
            'reason' => $request->reason,
            'override_reason' => $request->override_reason,
            'requested_by' => $request->requester?->name,
            'approved_by' => $request->approver?->name,
            'approved_at' => $request->approved_at?->toIso8601String(),
            'approval_expires_at' => $request->approval_expires_at?->toIso8601String(),
            'completed_by' => $request->completer?->name,
            'completed_at' => $request->completed_at?->toIso8601String(),
            'handling_shift_id' => $request->handling_shift_id,
            'items' => $request->items->map(function (ReturnItem $line) use ($includeCosts) {
                $row = [
                    'id' => $line->id,
                    'sale_item_id' => $line->sale_item_id,
                    'quantity' => $line->quantity,
                    'disposition' => $line->disposition,
                    'reason' => $line->reason,
                    'refund_net_amount' => $line->refund_net_amount === null ? null : (string) $line->refund_net_amount,
                    'refund_tax_amount' => $line->refund_tax_amount === null ? null : (string) $line->refund_tax_amount,
                    'refund_total_amount' => $line->refund_total_amount === null ? null : (string) $line->refund_total_amount,
                ];
                if ($includeCosts) {
                    $row['restored_cost_snapshot'] = $line->restored_cost_snapshot;
                }
                return $row;
            })->values()->all(),
            'refund' => $request->refund ? [
                'id' => $request->refund->id,
                'method' => $request->refund->method,
                'amount' => (string) $request->refund->amount,
                'reference_number' => $request->refund->reference_number,
                'processed_by' => $request->refund->processor?->name,
                'completed_at' => $request->refund->completed_at?->toIso8601String(),
            ] : null,
        ];

        return $payload;
    }
}
