<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutSaleRequest;
use App\Models\Sale;
use App\Services\PaymentService;
use App\Services\SaleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $result = $this->saleService->checkout(
            $user,
            $request->input('quote_id'),
            $request->input('payment_method'),
            $request->input('tendered_amount') !== null ? (int) $request->input('tendered_amount') : null,
            $request->input('payment_reference'),
            $request->input('idempotency_key'),
            $request->canonicalPayload()
        );

        return ApiResponse::success(
            $result['response'],
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
}
