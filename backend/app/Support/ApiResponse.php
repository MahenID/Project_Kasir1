<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Sukses',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        $requestId = request()->attributes->get('request_id')
            ?? request()->header('X-Request-ID')
            ?? (string) str()->uuid();

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => array_merge(['request_id' => $requestId], $meta),
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        array $fields = [],
        int $status = 400,
        array $meta = []
    ): JsonResponse {
        $requestId = request()->attributes->get('request_id')
            ?? request()->header('X-Request-ID')
            ?? (string) str()->uuid();

        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => (object) $fields,
            ],
            'meta' => array_merge(['request_id' => $requestId], $meta),
        ], $status);
    }
}
