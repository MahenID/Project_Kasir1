<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelReceivingRequest;
use App\Http\Requests\CreateReceivingRequest;
use App\Http\Requests\UpdateReceivingLinesRequest;
use App\Models\Receiving;
use App\Services\ReceivingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceivingController extends Controller
{
    public function __construct(
        protected ReceivingService $receivingService,
    ) {}

    /**
     * GET /api/v1/receivings — paginated list of receiving documents.
     * Cashiers with `manage-inventory` (manager/owner) see all; non-inventory
     * users are limited to documents they themselves created.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        $query = Receiving::query()
            ->with(['supplier', 'creator'])
            ->orderByDesc('id');

        $user = $request->user();
        $canManageInventory = $user->can('manage-inventory')
            || $user->hasAnyRole(['owner', 'manager']);

        if (! $canManageInventory) {
            $query->where('created_by', $user->id);
        }

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(fn (Receiving $r) => [
            'id' => $r->id,
            'receiving_number' => $r->receiving_number,
            'type' => $r->type,
            'status' => $r->status,
            'supplier_id' => $r->supplier_id,
            'supplier_name' => $r->supplier?->name,
            'external_reference' => $r->external_reference,
            'received_at' => $r->received_at?->toIso8601String(),
            'created_by' => $r->creator?->name,
            'created_at' => $r->created_at?->toIso8601String(),
        ])->values()->all();

        return ApiResponse::success($data, 'Daftar dokumen penerimaan.', 200, [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/receivings — create a new draft receiving.
     */
    public function store(CreateReceivingRequest $request): JsonResponse
    {
        $user = $request->user();

        $receiving = $this->receivingService->createDraft(
            user: $user,
            type: $request->input('type'),
            supplierId: $request->input('supplier_id') !== null
                ? (int) $request->input('supplier_id')
                : null,
            externalReference: $request->input('external_reference'),
            receivedAt: $request->input('received_at'),
            items: $request->input('items', []),
        );

        return ApiResponse::success(
            $this->receivingService->buildReceivingPayload($receiving),
            'Dokumen penerimaan berhasil dibuat dalam status draft.',
            201,
        );
    }

    /**
     * GET /api/v1/receivings/{receiving} — full detail with line items.
     */
    public function show(Request $request, Receiving $receiving): JsonResponse
    {
        $this->authorizeAccess($request, $receiving);

        return ApiResponse::success(
            $this->receivingService->buildReceivingPayload($receiving),
            'Detail dokumen penerimaan.',
        );
    }

    /**
     * PUT /api/v1/receivings/{receiving}/lines — replace the entire line set
     * on a draft receiving. Only allowed while status === 'draft'.
     */
    public function updateLines(
        UpdateReceivingLinesRequest $request,
        Receiving $receiving
    ): JsonResponse {
        $this->receivingService->updateLines(
            $receiving,
            $request->input('items'),
        );

        return ApiResponse::success(
            $this->receivingService->buildReceivingPayload($receiving->fresh(['items.product'])),
            'Baris item dokumen penerimaan berhasil diperbarui.',
        );
    }

    /**
     * POST /api/v1/receivings/{receiving}/approve — freeze the line set.
     */
    public function approve(Request $request, Receiving $receiving): JsonResponse
    {
        $this->receivingService->approve($request->user(), $receiving);

        return ApiResponse::success(
            $this->receivingService->buildReceivingPayload($receiving->fresh(['items.product'])),
            'Dokumen penerimaan berhasil disetujui.',
        );
    }

    /**
     * POST /api/v1/receivings/{receiving}/post — execute the receiving:
     * increment stock, recalculate moving-average cost, write the
     * immutable stock_movements ledger. Atomic and stocktake-aware.
     */
    public function post(Request $request, Receiving $receiving): JsonResponse
    {
        $this->receivingService->post($request->user(), $receiving);

        return ApiResponse::success(
            $this->receivingService->buildReceivingPayload($receiving->fresh(['items.product', 'poster'])),
            'Dokumen penerimaan berhasil diposting. Stok dan harga modal telah diperbarui.',
        );
    }

    /**
     * POST /api/v1/receivings/{receiving}/cancel — cancel a draft or
     * approved document. Posted documents cannot be cancelled; use
     * inventory_adjustments for corrections instead.
     */
    public function cancel(
        CancelReceivingRequest $request,
        Receiving $receiving
    ): JsonResponse {
        $this->receivingService->cancel(
            $request->user(),
            $receiving,
            $request->input('notes'),
        );

        return ApiResponse::success(
            $this->receivingService->buildReceivingPayload($receiving->fresh(['items.product'])),
            'Dokumen penerimaan berhasil dibatalkan.',
        );
    }

    /**
     * Non-inventory users may only view their own draft documents.
     *
     * @throws \App\Exceptions\DomainException
     */
    protected function authorizeAccess(Request $request, Receiving $receiving): void
    {
        $user = $request->user();

        if ($user->can('manage-inventory') || $user->hasAnyRole(['owner', 'manager'])) {
            return;
        }

        if ((int) $receiving->created_by !== (int) $user->id) {
            throw new \App\Exceptions\DomainException(
                'Anda tidak memiliki akses ke dokumen ini.',
                'RECEIVING_FORBIDDEN',
                403,
            );
        }
    }
}
