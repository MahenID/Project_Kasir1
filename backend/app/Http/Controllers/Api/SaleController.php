<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutSaleRequest;
use App\Http\Requests\ReprintSaleRequest;
use App\Models\Sale;
use App\Models\SaleReprint;
use App\Services\PaymentService;
use App\Services\SaleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function __construct(
        protected SaleService $saleService,
        protected PaymentService $paymentService,
    ) {}

    /**
     * POST /api/v1/sales — finalize a checkout from a server quote.
     * Atomic, idempotent, and safe to retry with the same idempotency key.
     */
    public function store(CheckoutSaleRequest $request): JsonResponse
    {
        $user = $request->user();

        // SaleService::checkout delegates to IdempotencyService::execute, which
        // returns the finalized response snapshot directly.
        $salePayload = $this->saleService->checkout(
            $user,
            $request->input('quote_id'),
            $request->input('payment_method'),
            $request->input('tendered_amount') !== null ? (int) $request->input('tendered_amount') : null,
            $request->input('payment_reference'),
            $request->input('idempotency_key'),
            $request->canonicalPayload()
        );

        return ApiResponse::success(
            $salePayload,
            'Transaksi penjualan berhasil diselesaikan.',
            201
        );
    }

    /**
     * GET /api/v1/sales — paginated list scoped to the requesting user's sales
     * (cashiers see their own; owner/manager see all via a future scope).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        $query = Sale::query()
            ->with(['payment'])
            ->orderByDesc('completed_at')
            ->orderByDesc('id');

        // Cashiers are limited to their own sales.
        if ($request->user()->hasRole('cashier') && !$request->user()->hasAnyRole(['owner', 'manager'])) {
            $query->where('user_id', $request->user()->id);
        }

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(fn (Sale $sale) => [
            'id' => $sale->id,
            'receipt_number' => $sale->receipt_number,
            'status' => $sale->status,
            'return_status' => $sale->return_status,
            'completed_at' => $sale->completed_at?->toIso8601String(),
            'cashier_name' => $sale->cashier_name_snapshot,
            'grand_total' => $sale->grand_total,
            'payment_method' => $sale->payment?->method,
        ])->values()->all();

        return ApiResponse::success($data, 'Daftar transaksi penjualan.', 200, [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/sales/{sale} — full detail of one sale.
     */
    public function show(Request $request, Sale $sale): JsonResponse
    {
        $this->authorizeSaleAccess($request, $sale);

        return ApiResponse::success(
            $this->saleService->buildSalePayload($sale),
            'Detail transaksi penjualan.'
        );
    }

    /**
     * GET /api/v1/sales/{sale}/receipt — print-ready receipt rendered from snapshots.
     */
    public function receipt(Request $request, Sale $sale): JsonResponse
    {
        $this->authorizeSaleAccess($request, $sale);

        return ApiResponse::success(
            $this->paymentService->buildReceiptPayload($sale),
            'Data struk penjualan.'
        );
    }

    /**
     * POST /api/v1/sales/{sale}/reprints — log a reprint and return the receipt
     * payload flagged as `is_copy: true` ("SALINAN") for the browser print
     * dialog. Reprinting never mutates the parent sale: a printer failure
     * cannot duplicate or void the transaction. Every call inserts a row in
     * `sale_reprints` and emits an `activity_log` entry.
     */
    public function reprint(ReprintSaleRequest $request, Sale $sale): JsonResponse
    {
        $this->authorizeReprintAccess($request, $sale);

        $reason = $request->input('reason');
        $sourceIp = $request->ip();
        $user = $request->user();
        $now = now();

        $reprint = DB::transaction(function () use ($sale, $user, $reason, $sourceIp, $now) {
            return SaleReprint::create([
                'sale_id' => $sale->id,
                'reprinted_by_user_id' => $user->id,
                'reason' => $reason,
                'source_ip' => $sourceIp,
                'reprinted_at' => $now,
            ]);
        });

        activity('sales')
            ->performedOn($sale)
            ->causedBy($user)
            ->withProperties([
                'sale_id' => $sale->id,
                'receipt_number' => $sale->receipt_number,
                'reprint_id' => $reprint->id,
                'reason' => $reason,
                'source_ip' => $sourceIp,
            ])
            ->log('sale.reprint');

        $payload = $this->paymentService->buildReceiptPayload($sale->fresh('items', 'payment'));
        $payload['is_copy'] = true;
        $payload['copy_label'] = 'SALINAN';
        $payload['reprint'] = [
            'id' => $reprint->id,
            'reprinted_by_user_id' => $user->id,
            'reprinted_by_name' => $user->name,
            'reason' => $reason,
            'reprinted_at' => $reprint->reprinted_at->toIso8601String(),
        ];

        return ApiResponse::success(
            $payload,
            'Cetak ulang struk dicatat. Tandai sebagai salinan pada cetakan.',
            201
        );
    }

    /**
     * Cashiers may only view their own sales; owner/manager may view all.
     *
     * @throws DomainException
     */
    protected function authorizeSaleAccess(Request $request, Sale $sale): void
    {
        $user = $request->user();

        if ($user->hasAnyRole(['owner', 'manager'])) {
            return;
        }

        if ((int) $sale->user_id !== (int) $user->id) {
            throw new DomainException(
                'Anda tidak memiliki akses ke transaksi ini.',
                'SALE_FORBIDDEN',
                403
            );
        }
    }

    /**
     * Reprint access requires either view-all-sales (owner/manager) or
     * view-own-sales (cashier, restricted to their own sales).
     *
     * @throws DomainException
     */
    protected function authorizeReprintAccess(Request $request, Sale $sale): void
    {
        $user = $request->user();

        if ($user->can('view-all-sales') || $user->hasAnyRole(['owner', 'manager'])) {
            return;
        }

        if (! $user->can('view-own-sales')) {
            throw new DomainException(
                'Anda tidak memiliki izin untuk mencetak ulang struk.',
                'REPRINT_FORBIDDEN',
                403
            );
        }

        if ((int) $sale->user_id !== (int) $user->id) {
            throw new DomainException(
                'Anda hanya dapat mencetak ulang struk milik sendiri.',
                'REPRINT_FORBIDDEN',
                403
            );
        }
    }
}
