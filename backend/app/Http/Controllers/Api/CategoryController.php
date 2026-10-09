<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categoryService,
    ) {}

    /**
     * GET /api/v1/categories — all roles may browse the catalog list.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()->withCount('products')->orderBy('name');

        if ($request->has('active_only')) {
            $query->where('is_active', filter_var($request->query('active_only'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%"));
        }

        $categories = $query->get();

        return ApiResponse::success(
            $categories->map(fn (Category $c) => $this->categoryPayload($c))->all(),
            'Daftar kategori.',
        );
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        return ApiResponse::success($this->categoryPayload($category), 'Detail kategori.');
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create($request->user(), $request->validated());

        return ApiResponse::success($this->categoryPayload($category), 'Kategori dibuat.', 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category = $this->categoryService->update($request->user(), $category, $request->validated());

        return ApiResponse::success($this->categoryPayload($category), 'Kategori diperbarui.');
    }

    /**
     * POST /api/v1/categories/{category}/archive — soft deactivate (AC-36).
     */
    public function archive(Request $request, Category $category): JsonResponse
    {
        $category = $this->categoryService->archive($request->user(), $category);

        return ApiResponse::success($this->categoryPayload($category), 'Kategori diarsipkan.');
    }

    protected function categoryPayload(Category $category): array
    {
        return $this->categoryService->buildCategoryPayload($category);
    }
}
