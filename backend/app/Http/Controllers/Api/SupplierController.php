<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\Supplier;
use App\Services\SupplierService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(
        protected SupplierService $supplierService,
    ) {}

    /**
     * GET /api/v1/suppliers — inventory managers browse suppliers.
     */
    public function index(Request $request): JsonResponse
    {
        $this->supplierService->authorizeManage($request->user());

        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = Supplier::query()->orderBy('name');

        if ($request->has('active_only')) {
            $query->where('is_active', filter_var($request->query('active_only'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        $paginator = $query->paginate($perPage);

        return ApiResponse::success(
            collect($paginator->items())
                ->map(fn (Supplier $s) => $this->supplierService->buildSupplierPayload($s))
                ->all(),
            'Daftar supplier.',
            200,
            [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        );
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        $this->supplierService->authorizeManage($request->user());

        return ApiResponse::success(
            $this->supplierService->buildSupplierPayload($supplier),
            'Detail supplier.',
        );
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = $this->supplierService->create($request->user(), $request->validated());

        return ApiResponse::success(
            $this->supplierService->buildSupplierPayload($supplier),
            'Supplier dibuat.',
            201,
        );
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier = $this->supplierService->update($request->user(), $supplier, $request->validated());

        return ApiResponse::success(
            $this->supplierService->buildSupplierPayload($supplier),
            'Supplier diperbarui.',
        );
    }

    /**
     * POST /api/v1/suppliers/{supplier}/archive — soft deactivate.
     */
    public function archive(Request $request, Supplier $supplier): JsonResponse
    {
        $supplier = $this->supplierService->archive($request->user(), $supplier);

        return ApiResponse::success(
            $this->supplierService->buildSupplierPayload($supplier),
            'Supplier diarsipkan.',
        );
    }
}
