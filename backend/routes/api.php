<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutQuoteController;
use App\Http\Controllers\Api\OperationRecoveryController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ShiftController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Health check
    Route::get('/health', function () {
        return ApiResponse::success([
            'status' => 'ok',
            'app' => config('app.name'),
            'env' => config('app.env'),
        ], 'DragonMart POS API is healthy');
    });

    // Public Authentication
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1');
    });

    // Authenticated Endpoints
    Route::middleware('auth:sanctum')->group(function () {
        // Current user & authentication management
        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::put('/profile', [AuthController::class, 'updateProfile']);
            Route::put('/password', [AuthController::class, 'updatePassword']);
        });

        // Shifts & Cash Reconciliation
        Route::prefix('shifts')->group(function () {
            Route::post('/open', [ShiftController::class, 'open']);
            Route::get('/current', [ShiftController::class, 'current']);
            Route::post('/close', [ShiftController::class, 'close']);
            Route::post('/{id}/cash-movements', [ShiftController::class, 'recordCashMovement']);
            Route::get('/{id}', [ShiftController::class, 'show']);
        });

        // Checkout Quotes
        Route::prefix('checkout')->group(function () {
            Route::post('/quotes', [CheckoutQuoteController::class, 'store']);
            Route::get('/recovery/{key}', [OperationRecoveryController::class, 'recoverCheckout']);
        });

        // Sales (Phase 3 — First Complete Sale)
        Route::prefix('sales')->group(function () {
            Route::post('/', [SaleController::class, 'store']);
            Route::get('/', [SaleController::class, 'index']);
            Route::get('/{sale}', [SaleController::class, 'show'])->whereNumber('sale');
            Route::get('/{sale}/receipt', [SaleController::class, 'receipt'])->whereNumber('sale');
        });

        // Operation Key Recovery
        Route::get('/operations/{operation}/{key}', [OperationRecoveryController::class, 'recoverOperation']);
    });
});
