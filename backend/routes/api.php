<?php

use App\Http\Controllers\Api\AuditEventController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReturnRequestController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutQuoteController;
use App\Http\Controllers\Api\InventoryAdjustmentController;
use App\Http\Controllers\Api\OperationRecoveryController;
use App\Http\Controllers\Api\ReceivingController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\StocktakeController;
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
            Route::post('/{sale}/reprints', [SaleController::class, 'reprint'])->whereNumber('sale');
        });

        // Operation Key Recovery
        Route::get('/operations/{operation}/{key}', [OperationRecoveryController::class, 'recoverOperation']);

        Route::prefix('return-requests')->group(function () {
            Route::get('/', [ReturnRequestController::class, 'index']);
            Route::get('/lookup', [ReturnRequestController::class, 'lookup']);
            Route::post('/', [ReturnRequestController::class, 'store']);
            Route::get('/{returnRequest}', [ReturnRequestController::class, 'show'])->whereNumber('returnRequest');
            Route::post('/{returnRequest}/approve', [ReturnRequestController::class, 'approve'])->whereNumber('returnRequest');
            Route::post('/{returnRequest}/reject', [ReturnRequestController::class, 'reject'])->whereNumber('returnRequest');
            Route::post('/{returnRequest}/cancel', [ReturnRequestController::class, 'cancel'])->whereNumber('returnRequest');
            Route::post('/{returnRequest}/complete', [ReturnRequestController::class, 'complete'])->whereNumber('returnRequest');
        });

        // Receivings (Phase 4 — Operational Completeness: Inventory)
        Route::prefix('receivings')->group(function () {
            Route::get('/', [ReceivingController::class, 'index']);
            Route::post('/', [ReceivingController::class, 'store']);
            Route::get('/{receiving}', [ReceivingController::class, 'show'])->whereNumber('receiving');
            Route::put('/{receiving}/lines', [ReceivingController::class, 'updateLines'])->whereNumber('receiving');
            Route::post('/{receiving}/approve', [ReceivingController::class, 'approve'])->whereNumber('receiving');
            Route::post('/{receiving}/post', [ReceivingController::class, 'post'])->whereNumber('receiving');
            Route::post('/{receiving}/cancel', [ReceivingController::class, 'cancel'])->whereNumber('receiving');
        });

        // Inventory Adjustments (Phase 4 — Operational Completeness: Inventory)
        Route::prefix('inventory-adjustments')->group(function () {
            Route::get('/', [InventoryAdjustmentController::class, 'index']);
            Route::post('/', [InventoryAdjustmentController::class, 'store']);
            Route::get('/{adjustment}', [InventoryAdjustmentController::class, 'show'])->whereNumber('adjustment');
            Route::post('/{adjustment}/post', [InventoryAdjustmentController::class, 'post'])->whereNumber('adjustment');
            Route::post('/{adjustment}/cancel', [InventoryAdjustmentController::class, 'cancel'])->whereNumber('adjustment');
        });

        // Stocktakes (Phase 4 — Operational Completeness: Inventory)
        Route::prefix('stocktakes')->group(function () {
            Route::get('/', [StocktakeController::class, 'index']);
            Route::get('/active', [StocktakeController::class, 'active']);
            Route::post('/', [StocktakeController::class, 'start']);
            Route::get('/{stocktake}', [StocktakeController::class, 'show'])->whereNumber('stocktake');
            Route::post('/{stocktake}/count/{product}', [StocktakeController::class, 'count'])->whereNumber('stocktake')->whereNumber('product');
            Route::post('/{stocktake}/counts', [StocktakeController::class, 'bulkCount'])->whereNumber('stocktake');
            Route::post('/{stocktake}/post', [StocktakeController::class, 'post'])->whereNumber('stocktake');
            Route::post('/{stocktake}/cancel', [StocktakeController::class, 'cancel'])->whereNumber('stocktake');
        });

        // Master Data — Categories (Phase 4 — Operational Completeness)
        Route::prefix('categories')->group(function () {
            Route::get('/', [CategoryController::class, 'index']);
            Route::get('/{category}', [CategoryController::class, 'show'])->whereNumber('category');
            Route::post('/', [CategoryController::class, 'store']);
            Route::put('/{category}', [CategoryController::class, 'update'])->whereNumber('category');
            Route::post('/{category}/archive', [CategoryController::class, 'archive'])->whereNumber('category');
        });

        // Master Data — Products
        Route::prefix('products')->group(function () {
            Route::get('/', [ProductController::class, 'index']);
            Route::get('/{product}', [ProductController::class, 'show'])->whereNumber('product');
            Route::post('/', [ProductController::class, 'store']);
            Route::put('/{product}', [ProductController::class, 'update'])->whereNumber('product');
            Route::post('/{product}/archive', [ProductController::class, 'archive'])->whereNumber('product');
        });

        // Master Data — Suppliers
        Route::prefix('suppliers')->group(function () {
            Route::get('/', [SupplierController::class, 'index']);
            Route::get('/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier');
            Route::post('/', [SupplierController::class, 'store']);
            Route::put('/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier');
            Route::post('/{supplier}/archive', [SupplierController::class, 'archive'])->whereNumber('supplier');
        });

        // Master Data — Customers
        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerController::class, 'index']);
            Route::get('/{customer}', [CustomerController::class, 'show'])->whereNumber('customer');
            Route::post('/', [CustomerController::class, 'store']);
            Route::put('/{customer}', [CustomerController::class, 'update'])->whereNumber('customer');
            Route::post('/{customer}/archive', [CustomerController::class, 'archive'])->whereNumber('customer');
        });

        // Reports, Exports & Audit (Phase 4 — Operational Completeness)
        Route::prefix('reports')->group(function () {
            Route::get('/sales', [ReportController::class, 'sales']);
            Route::get('/top-products', [ReportController::class, 'topProducts']);
            Route::get('/stock', [ReportController::class, 'stock']);
            Route::get('/shift-differences', [ReportController::class, 'shiftDifferences']);
            Route::get('/receiving', [ReportController::class, 'receiving']);
            Route::get('/returns', [ReportController::class, 'returns']);
        });

        Route::prefix('exports')->group(function () {
            Route::get('/', [ExportController::class, 'index']);
            Route::post('/', [ExportController::class, 'store']);
            Route::get('/{export}', [ExportController::class, 'show']);
            Route::get('/{export}/download', [ExportController::class, 'download']);
        });

        Route::prefix('audit-events')->group(function () {
            Route::get('/', [AuditEventController::class, 'index']);
            Route::get('/{auditEvent}', [AuditEventController::class, 'show'])->whereNumber('auditEvent');
        });
    });
});
