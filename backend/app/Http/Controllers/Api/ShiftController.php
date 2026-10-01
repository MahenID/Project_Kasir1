<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\IdempotencyService;
use App\Services\ShiftService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(
        protected ShiftService $shiftService,
        protected IdempotencyService $idempotencyService
    ) {}

    /**
     * Open a new shift for the authenticated user.
     */
    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'terminal_id' => 'required|integer|exists:terminals,id',
            'starting_cash' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $shift = $this->shiftService->openShift(
            $request->user(),
            (int) $validated['terminal_id'],
            (int) $validated['starting_cash'],
            $validated['notes'] ?? null
        );

        return ApiResponse::success([
            'shift' => [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'user_id' => $shift->user_id,
                'terminal_id' => $shift->terminal_id,
                'terminal_code' => $shift->terminal->code,
                'status' => $shift->status,
                'opened_at' => $shift->opened_at?->toIso8601String(),
                'starting_cash' => $shift->starting_cash,
                'expected_cash' => $shift->expected_cash,
            ],
        ], 'Shift kasir berhasil dibuka.', 201);
    }

    /**
     * Get the authenticated user's current open shift and live X-report preview.
     */
    public function current(Request $request): JsonResponse
    {
        $shift = $request->user()->currentOpenShift;

        if (!$shift) {
            return ApiResponse::success(null, 'Tidak ada shift yang sedang terbuka untuk kasir ini.');
        }

        $xReport = $this->shiftService->calculateXReport($shift);

        return ApiResponse::success([
            'shift' => [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'user_id' => $shift->user_id,
                'terminal_id' => $shift->terminal_id,
                'terminal_code' => $shift->terminal->code,
                'status' => $shift->status,
                'opened_at' => $shift->opened_at?->toIso8601String(),
                'starting_cash' => $shift->starting_cash,
            ],
            'x_report' => $xReport,
        ]);
    }

    /**
     * Close the user's current shift and generate immutable Z-Report.
     */
    public function close(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'actual_cash' => 'required|integer|min:0',
            'explanation' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $shift = $user->currentOpenShift;

        if (!$shift) {
            return ApiResponse::error('SHIFT_NOT_FOUND', 'Tidak ada shift terbuka yang dapat ditutup.', [], 404);
        }

        $closedShift = $this->shiftService->closeShift(
            $shift,
            $user,
            (int) $validated['actual_cash'],
            $validated['explanation'] ?? null
        );

        return ApiResponse::success([
            'shift' => [
                'id' => $closedShift->id,
                'shift_number' => $closedShift->shift_number,
                'status' => $closedShift->status,
                'opened_at' => $closedShift->opened_at?->toIso8601String(),
                'closed_at' => $closedShift->closed_at?->toIso8601String(),
                'starting_cash' => $closedShift->starting_cash,
                'expected_cash' => $closedShift->expected_cash,
                'actual_cash' => $closedShift->actual_cash,
                'difference' => $closedShift->difference,
                'explanation' => $closedShift->explanation,
            ],
            'z_report' => $closedShift->z_report_snapshot,
        ], 'Shift berhasil ditutup dan laporan Z telah dibukukan.');
    }

    /**
     * Record cash in / cash out for a shift with idempotency support.
     */
    public function recordCashMovement(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user->can('record-cash-movement')) {
            return ApiResponse::error('FORBIDDEN', 'Hanya manajer atau pemilik yang berhak mencatat pergerakan kas laci.', [], 403);
        }

        $validated = $request->validate([
            'type' => 'required|in:cash_in,cash_out',
            'amount' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
        ]);

        $shift = Shift::findOrFail($id);
        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey) {
            $response = $this->idempotencyService->execute(
                $user->id,
                'cash_movement',
                $idempotencyKey,
                array_merge($validated, ['shift_id' => $id]),
                function () use ($shift, $user, $validated, $idempotencyKey) {
                    $movement = $this->shiftService->recordCashMovement(
                        $shift,
                        $user,
                        $validated['type'],
                        (int) $validated['amount'],
                        $validated['reason'],
                        $idempotencyKey
                    );

                    return [
                        'resource_type' => 'cash_movement',
                        'resource_id' => $movement->id,
                        'response' => [
                            'movement' => [
                                'id' => $movement->id,
                                'shift_id' => $movement->shift_id,
                                'type' => $movement->type,
                                'amount' => $movement->amount,
                                'reason' => $movement->reason,
                                'posted_at' => $movement->posted_at?->toIso8601String(),
                            ],
                        ],
                    ];
                }
            );

            return ApiResponse::success($response, 'Pergerakan kas berhasil dicatat.');
        }

        $movement = $this->shiftService->recordCashMovement(
            $shift,
            $user,
            $validated['type'],
            (int) $validated['amount'],
            $validated['reason']
        );

        return ApiResponse::success([
            'movement' => [
                'id' => $movement->id,
                'shift_id' => $movement->shift_id,
                'type' => $movement->type,
                'amount' => $movement->amount,
                'reason' => $movement->reason,
                'posted_at' => $movement->posted_at?->toIso8601String(),
            ],
        ], 'Pergerakan kas berhasil dicatat.', 201);
    }

    /**
     * Show shift details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $shift = Shift::with(['user', 'terminal'])->findOrFail($id);

        if ($shift->user_id !== $user->id && !$user->can('review-all-shifts')) {
            return ApiResponse::error('FORBIDDEN', 'Anda tidak berhak melihat data shift kasir lain.', [], 403);
        }

        $report = $shift->status === 'closed'
            ? $shift->z_report_snapshot
            : $this->shiftService->calculateXReport($shift);

        return ApiResponse::success([
            'shift' => [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'user' => [
                    'id' => $shift->user->id,
                    'name' => $shift->user->name,
                ],
                'terminal' => [
                    'id' => $shift->terminal->id,
                    'code' => $shift->terminal->code,
                    'name' => $shift->terminal->name,
                ],
                'status' => $shift->status,
                'opened_at' => $shift->opened_at?->toIso8601String(),
                'closed_at' => $shift->closed_at?->toIso8601String(),
                'starting_cash' => $shift->starting_cash,
                'expected_cash' => $shift->expected_cash,
                'actual_cash' => $shift->actual_cash,
                'difference' => $shift->difference,
                'explanation' => $shift->explanation,
            ],
            'report' => $report,
        ]);
    }
}
