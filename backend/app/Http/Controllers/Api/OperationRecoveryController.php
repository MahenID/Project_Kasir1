<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IdempotencyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationRecoveryController extends Controller
{
    public function __construct(
        protected IdempotencyService $idempotencyService
    ) {}

    /**
     * Recovery endpoint for checkout key.
     */
    public function recoverCheckout(Request $request, string $key): JsonResponse
    {
        $user = $request->user();
        $lookup = $this->idempotencyService->lookup($user->id, 'checkout', $key);

        return ApiResponse::success($lookup, 'Hasil pengecekan pemulihan checkout.');
    }

    /**
     * Recovery endpoint for any permitted operation key.
     */
    public function recoverOperation(Request $request, string $operation, string $key): JsonResponse
    {
        validator(['operation' => $operation, 'key' => $key], [
            'operation' => ['required', Rule::in(['checkout', 'refund-complete'])],
            'key' => ['required', 'uuid'],
        ])->validate();
        $user = $request->user();
        $lookup = $this->idempotencyService->lookup($user->id, $operation, $key);

        return ApiResponse::success($lookup, 'Hasil pengecekan pemulihan operasi.');
    }
}
