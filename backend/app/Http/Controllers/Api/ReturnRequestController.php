<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteReturnRequest;
use App\Http\Requests\CreateReturnRequest;
use App\Models\ReturnRequest;
use App\Services\ReturnService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReturnRequestController extends Controller
{
    public function __construct(protected ReturnService $returns) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertAccess($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['requested', 'approved', 'rejected', 'cancelled', 'completed'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ReturnRequest::query()->with(['items', 'refund', 'requester', 'approver', 'completer', 'sale']);
        if (!$request->user()->hasAnyRole(['owner', 'manager'])) {
            $query->where('requested_by', $request->user()->id);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 20);
        $page->getCollection()->transform(fn (ReturnRequest $return) => $this->payload($request, $return));
        return ApiResponse::success($page->items(), 'Daftar permintaan retur.', 200, [
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->assertAccess($request);
        $data = $request->validate(['receipt_number' => ['required', 'string', 'max:100']]);
        return ApiResponse::success($this->returns->lookupByReceipt($request->user(), $data['receipt_number']));
    }

    public function show(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->assertAccess($request, $returnRequest);
        return ApiResponse::success($this->payload($request, $returnRequest));
    }

    public function store(CreateReturnRequest $request): JsonResponse
    {
        $data = $request->validated();
        $return = $this->returns->createRequest($request->user(), (int) $data['sale_id'], $data['reason'], $data['lines'], $data['override_reason'] ?? null);
        return ApiResponse::success($this->payload($request, $return), 'Permintaan retur dibuat.', 201);
    }

    public function approve(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->assertAccess($request, $returnRequest, true);
        return ApiResponse::success($this->payload($request, $this->returns->approve($request->user(), $returnRequest)));
    }

    public function reject(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->assertAccess($request, $returnRequest, true);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return ApiResponse::success($this->payload($request, $this->returns->reject($request->user(), $returnRequest, $data['reason'])));
    }

    public function cancel(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->assertAccess($request, $returnRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return ApiResponse::success($this->payload($request, $this->returns->cancel($request->user(), $returnRequest, $data['reason'])));
    }

    public function complete(CompleteReturnRequest $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->assertAccess($request, $returnRequest);
        $payload = $request->canonicalPayload((int) $returnRequest->id);
        $result = $this->returns->complete($request->user(), $returnRequest, $payload['shift_id'], $payload['confirmed_cash_amount'], $payload['refund_reference'], $request->validated('idempotency_key'), $payload);
        return ApiResponse::success($result);
    }

    protected function payload(Request $request, ReturnRequest $return): array
    {
        return $this->returns->buildReturnPayload($return, $request->user()->hasAnyRole(['owner', 'manager']));
    }

    protected function assertAccess(Request $request, ?ReturnRequest $return = null, bool $supervisor = false): void
    {
        $user = $request->user();
        $isSupervisor = $user->hasAnyRole(['owner', 'manager']);
        if (!$user->is_active || !$user->hasAnyRole(['owner', 'manager', 'cashier']) || !$user->can($supervisor ? 'approve-return-request' : 'create-return-request') || ($supervisor && !$isSupervisor) || ($return && !$isSupervisor && (int) $return->requested_by !== (int) $user->id)) {
            throw new DomainException('Anda tidak memiliki akses ke permintaan retur ini.', 'FORBIDDEN', 403);
        }
    }
}
