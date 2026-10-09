<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartStocktakeRequest;
use App\Http\Requests\StocktakeCountRequest;
use App\Models\Stocktake;
use App\Services\StocktakeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StocktakeController extends Controller
{
    public function __construct(
        protected StocktakeService $stocktakeService,
    ) {}

    /**
     * GET /api/v1/stocktakes — paginated list of stocktake documents.
     * Inventory managers see all; cashiers cannot list stocktakes.
     */
    public function index(Request $request): JsonResponse
    {
        $this->assertCanManage($request);

        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = Stocktake::query()
            ->with(['starter', 'poster'])
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(fn (Stocktake $s) => [
            'id' => $s->id,
            'stocktake_number' => $s->stocktake_number,
            'status' => $s->status,
            'started_by' => $s->starter?->name,
            'started_at' => $s->started_at?->toIso8601String(),
            'posted_by' => $s->poster?->name,
            'posted_at' => $s->posted_at?->toIso8601String(),
            'cancelled_at' => $s->cancelled_at?->toIso8601String(),
        ])->values()->all();

        return ApiResponse::success($data, 'Daftar stocktake.', 200, [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/stocktakes/active — returns the currently active stocktake
     * (if any) without forcing the caller to know its id. Useful for the
     * cashier UI to detect that the store is frozen.
     */
    public function active(Request $request): JsonResponse
    {
        $control = \App\Models\InventoryControl::first();
        if (!$control || !$control->active_stocktake_id) {
            return ApiResponse::success(null, 'Tidak ada stocktake aktif.');
        }

        $stocktake = Stocktake::with(['starter', 'poster', 'items.product'])
            ->find($control->active_stocktake_id);

        if (!$stocktake) {
            return ApiResponse::success(null, 'Tidak ada stocktake aktif.');
        }

        return ApiResponse::success(
            $this->stocktakeService->buildStocktakePayload($stocktake),
            'Stocktake aktif ditemukan.',
        );
    }

    /**
     * POST /api/v1/stocktakes — start a new stocktake and freeze the store.
     */
    public function start(StartStocktakeRequest $request): JsonResponse
    {
        $stocktake = $this->stocktakeService->start(
            $request->user(),
            $request->input('notes'),
        );

        return ApiResponse::success(
            $this->stocktakeService->buildStocktakePayload($stocktake),
            'Stocktake dimulai. Toko dalam jeda audit stok fisik.',
            201,
        );
    }

    /**
     * GET /api/v1/stocktakes/{stocktake} — full detail.
     */
    public function show(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->assertCanManage($request);

        return ApiResponse::success(
            $this->stocktakeService->buildStocktakePayload($stocktake),
            'Detail stocktake.',
        );
    }

    /**
     * POST /api/v1/stocktakes/{stocktake}/count — record a single physical
     * count. Body: { counted_stock: int }.
     */
    public function count(
        Request $request,
        Stocktake $stocktake
    ): JsonResponse {
        $this->assertCanManage($request);

        $validated = $request->validate([
            'counted_stock' => ['required', 'integer', 'min:0'],
        ]);

        $item = $this->stocktakeService->count(
            $request->user(),
            $stocktake,
            (int) $request->route('product'),
            (int) $validated['counted_stock'],
        );

        return ApiResponse::success([
            'id' => $item->id,
            'product_id' => $item->product_id,
            'counted_stock' => (int) $item->counted_stock,
            'difference' => (int) $item->difference,
        ], 'Hitungan fisik disimpan.');
    }

    /**
     * POST /api/v1/stocktakes/{stocktake}/counts — bulk record counts.
     * Body: { counts: [{ product_id, counted_stock }, ...] }.
     */
    public function bulkCount(
        StocktakeCountRequest $request,
        Stocktake $stocktake
    ): JsonResponse {
        $this->assertCanManage($request);

        $stocktake = $this->stocktakeService->bulkCount(
            $request->user(),
            $stocktake,
            $request->input('counts'),
        );

        return ApiResponse::success(
            $this->stocktakeService->buildStocktakePayload($stocktake),
            'Hitungan fisik disimpan.',
        );
    }

    /**
     * POST /api/v1/stocktakes/{stocktake}/post — apply all counts and
     * lift the store freeze.
     */
    public function post(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->stocktakeService->post($request->user(), $stocktake);

        return ApiResponse::success(
            $this->stocktakeService->buildStocktakePayload(
                $stocktake->fresh(['items.product', 'poster', 'starter'])
            ),
            'Stocktake selesai diposting. Jeda toko telah dibuka.',
        );
    }

    /**
     * POST /api/v1/stocktakes/{stocktake}/cancel — discard counts and
     * lift the store freeze. No stock change.
     */
    public function cancel(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->stocktakeService->cancel($request->user(), $stocktake);

        return ApiResponse::success(
            $this->stocktakeService->buildStocktakePayload($stocktake->fresh(['items.product'])),
            'Stocktake dibatalkan. Jeda toko telah dibuka.',
        );
    }

    /**
     * @throws \App\Exceptions\DomainException
     */
    protected function assertCanManage(Request $request): void
    {
        $user = $request->user();
        if ($user->can('manage-inventory') || $user->hasAnyRole(['owner', 'manager'])) {
            return;
        }
        throw new \App\Exceptions\DomainException(
            'Anda tidak memiliki izin untuk mengelola stocktake.',
            'INVENTORY_FORBIDDEN',
            403,
        );
    }
}
