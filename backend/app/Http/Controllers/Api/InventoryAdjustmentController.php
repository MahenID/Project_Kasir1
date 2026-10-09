<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInventoryAdjustmentRequest;
use App\Models\InventoryAdjustment;
use App\Services\InventoryAdjustmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryAdjustmentController extends Controller
{
    public function __construct(
        protected InventoryAdjustmentService $adjustmentService,
    ) {}

    /**
     * GET /api/v1/inventory-adjustments — paginated list of adjustment documents.
     * Inventory managers see all; non-inventory users are limited to their own drafts.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        $query = InventoryAdjustment::query()
            ->with(['creator'])
            ->orderByDesc('id');

        $user = $request->user();
        $canManage = $user->can('manage-inventory')
            || $user->hasAnyRole(['owner', 'manager']);
        $canRevalue = $user->can('revalue-inventory-cost')
            || $user->hasRole('owner');

        if (!($canManage || $canRevalue)) {
            $query->where('created_by', $user->id);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(fn (InventoryAdjustment $a) => [
            'id' => $a->id,
            'adjustment_number' => $a->adjustment_number,
            'type' => $a->type,
            'status' => $a->status,
            'reason' => $a->reason,
            'evidence_reference' => $a->evidence_reference,
            'created_by' => $a->creator?->name,
            'created_at' => $a->created_at?->toIso8601String(),
            'posted_at' => $a->posted_at?->toIso8601String(),
        ])->values()->all();

        return ApiResponse::success($data, 'Daftar dokumen penyesuaian.', 200, [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/inventory-adjustments — create a draft.
     */
    public function store(CreateInventoryAdjustmentRequest $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->input('type');

        $this->adjustmentService->authorizeCreate($user, $type);

        $adjustment = $this->adjustmentService->createDraft(
            user: $user,
            type: $type,
            reason: $request->input('reason'),
            evidenceReference: $request->input('evidence_reference'),
            items: $request->input('items', []),
        );

        return ApiResponse::success(
            $this->adjustmentService->buildAdjustmentPayload($adjustment),
            'Dokumen penyesuaian berhasil dibuat dalam status draft.',
            201,
        );
    }

    /**
     * GET /api/v1/inventory-adjustments/{adjustment} — full detail.
     */
    public function show(Request $request, InventoryAdjustment $adjustment): JsonResponse
    {
        $this->authorizeAccess($request, $adjustment);

        return ApiResponse::success(
            $this->adjustmentService->buildAdjustmentPayload($adjustment),
            'Detail dokumen penyesuaian.',
        );
    }

    /**
     * POST /api/v1/inventory-adjustments/{adjustment}/post — atomic posting.
     */
    public function post(Request $request, InventoryAdjustment $adjustment): JsonResponse
    {
        $this->adjustmentService->authorizePost($request->user(), $adjustment);

        $this->adjustmentService->post($request->user(), $adjustment);

        return ApiResponse::success(
            $this->adjustmentService->buildAdjustmentPayload(
                $adjustment->fresh(['items.product', 'poster'])
            ),
            'Dokumen penyesuaian berhasil diposting.',
        );
    }

    /**
     * POST /api/v1/inventory-adjustments/{adjustment}/cancel — cancel a draft.
     * Posted adjustments are immutable; create a correction instead.
     */
    public function cancel(Request $request, InventoryAdjustment $adjustment): JsonResponse
    {
        $this->adjustmentService->authorizeCancel($request->user(), $adjustment);

        $this->adjustmentService->cancel($request->user(), $adjustment);

        return ApiResponse::success(
            $this->adjustmentService->buildAdjustmentPayload($adjustment->fresh(['items.product'])),
            'Dokumen penyesuaian berhasil dibatalkan.',
        );
    }

    /**
     * Cashiers (no inventory permission) may only see their own drafts.
     *
     * @throws \App\Exceptions\DomainException
     */
    protected function authorizeAccess(Request $request, InventoryAdjustment $adjustment): void
    {
        $user = $request->user();

        $canManage = $user->can('manage-inventory') || $user->hasAnyRole(['owner', 'manager']);
        $canRevalue = $user->can('revalue-inventory-cost') || $user->hasRole('owner');

        if ($canManage || $canRevalue) {
            return;
        }

        if ((int) $adjustment->created_by !== (int) $user->id) {
            throw new \App\Exceptions\DomainException(
                'Anda tidak memiliki akses ke dokumen ini.',
                'ADJUSTMENT_FORBIDDEN',
                403,
            );
        }
    }
}
