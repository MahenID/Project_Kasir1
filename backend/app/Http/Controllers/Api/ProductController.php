<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService,
    ) {}

    /**
     * GET /api/v1/products — search by SKU/barcode/name (FR-PROD-03).
     * Cashiers see price and stock only; costs are filtered out.
     */
    public function index(Request $request): JsonResponse
    {
        $includeCosts = $this->productService->userCanViewCosts($request->user());
        if (!$includeCosts && !$request->user()->can('view-products')) {
            abort(403);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = Product::query()->with(['category', 'preferredSupplier']);

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }
        if ($barcode = $request->query('barcode')) {
            $query->where('barcode', $barcode);
        }
        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', (int) $categoryId);
        }
        if ($request->has('active_only')) {
            $query->where('is_active', filter_var($request->query('active_only'), FILTER_VALIDATE_BOOLEAN));
        }
        if (filter_var($request->query('low_stock'), FILTER_VALIDATE_BOOLEAN)) {
            // FR-PROD-04: stock at or below the minimum threshold.
            $query->whereColumn('stock', '<=', 'min_stock');
        }

        $paginator = $query->orderBy('name')->paginate($perPage);

        return ApiResponse::success(
            collect($paginator->items())
                ->map(fn (Product $p) => $this->productService->buildProductPayload($p, $includeCosts))
                ->all(),
            'Daftar produk.',
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

    public function show(Request $request, Product $product): JsonResponse
    {
        $includeCosts = $this->productService->userCanViewCosts($request->user());

        return ApiResponse::success(
            $this->productService->buildProductPayload(
                $product->loadMissing(['category', 'preferredSupplier']),
                $includeCosts,
            ),
            'Detail produk.',
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create(
            $request->user(),
            $request->validated(),
            $request->file('image'),
        );

        return ApiResponse::success(
            $this->productService->buildProductPayload(
                $product->loadMissing(['category', 'preferredSupplier']),
                $this->productService->userCanViewCosts($request->user()),
            ),
            'Produk dibuat.',
            201,
        );
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->productService->update(
            $request->user(),
            $product,
            $request->validated(),
            $request->file('image'),
        );

        return ApiResponse::success(
            $this->productService->buildProductPayload(
                $product->loadMissing(['category', 'preferredSupplier']),
                $this->productService->userCanViewCosts($request->user()),
            ),
            'Produk diperbarui.',
        );
    }

    /**
     * POST /api/v1/products/{product}/archive — soft deactivate.
     */
    public function archive(Request $request, Product $product): JsonResponse
    {
        $product = $this->productService->archive($request->user(), $product);

        return ApiResponse::success(
            $this->productService->buildProductPayload(
                $product->loadMissing(['category', 'preferredSupplier']),
                $this->productService->userCanViewCosts($request->user()),
            ),
            'Produk diarsipkan.',
        );
    }
}
