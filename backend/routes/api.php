<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'ok',
                'app' => config('app.name'),
                'env' => config('app.env'),
            ],
            'message' => 'DragonMart POS API is healthy',
            'meta' => [
                'request_id' => (string) str()->uuid(),
            ],
        ]);
    });
});
