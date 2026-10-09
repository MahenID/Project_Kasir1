<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerService $customerService,
    ) {}

    /**
     * GET /api/v1/customers — cashier-facing search (FR-CUST-01).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = Customer::query()->where('is_active', true);

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }

        $paginator = $query->orderBy('name')->paginate($perPage);

        return ApiResponse::success(
            collect($paginator->items())
                ->map(fn (Customer $c) => $this->customerService->buildCustomerPayload($c))
                ->all(),
            'Daftar pelanggan.',
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

    public function show(Request $request, Customer $customer): JsonResponse
    {
        return ApiResponse::success(
            $this->customerService->buildCustomerPayload($customer),
            'Detail pelanggan.',
        );
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customerService->create($request->user(), $request->validated());

        return ApiResponse::success(
            $this->customerService->buildCustomerPayload($customer),
            'Pelanggan dibuat.',
            201,
        );
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->customerService->update($request->user(), $customer, $request->validated());

        return ApiResponse::success(
            $this->customerService->buildCustomerPayload($customer),
            'Pelanggan diperbarui.',
        );
    }

    /**
     * POST /api/v1/customers/{customer}/archive — owner/manager only.
     */
    public function archive(Request $request, Customer $customer): JsonResponse
    {
        $customer = $this->customerService->archive($request->user(), $customer);

        return ApiResponse::success(
            $this->customerService->buildCustomerPayload($customer),
            'Pelanggan diarsipkan.',
        );
    }
}
