<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Models\OperationKey;
use Illuminate\Support\Facades\DB;
use Throwable;

class IdempotencyService
{
    /**
     * Compute a deterministic SHA-256 hash of a payload array.
     */
    public static function hashPayload(array $payload): string
    {
        $sorted = self::sortRecursive($payload);
        return hash('sha256', json_encode($sorted));
    }

    private static function sortRecursive(mixed $item): mixed
    {
        if (!is_array($item)) {
            return $item;
        }

        // Check if associative array
        if (array_keys($item) !== range(0, count($item) - 1)) {
            ksort($item);
        }

        foreach ($item as $key => $value) {
            $item[$key] = self::sortRecursive($value);
        }

        return $item;
    }

    /**
     * Execute an operation with durable idempotency.
     *
     * @param int $userId Current user ID
     * @param string $operation Operation family ('checkout', 'refund', 'receiving', etc.)
     * @param string $key Client-supplied idempotency key
     * @param array $payload Canonical request payload
     * @param callable $callback Operation callback returning ['resource_type' => ..., 'resource_id' => ..., 'response' => ...]
     * @return array The response snapshot
     * @throws IdempotencyConflictException|Throwable
     */
    public function execute(
        int $userId,
        string $operation,
        string $key,
        array $payload,
        callable $callback
    ): array {
        $payloadHash = self::hashPayload($payload);
        $requestId = request()->attributes->get('request_id')
            ?? request()->header('X-Request-ID')
            ?? (string) str()->uuid();

        // 1. Check existing record
        $existing = OperationKey::where('user_id', $userId)
            ->where('operation', $operation)
            ->where('key', $key)
            ->first();

        if ($existing) {
            if ($existing->payload_hash !== $payloadHash) {
                throw new IdempotencyConflictException(
                    'Kunci idempotensi telah digunakan dengan muatan data (payload) yang berbeda.',
                    'IDEMPOTENCY_CONFLICT',
                    ['key' => $key]
                );
            }

            if ($existing->status === 'succeeded') {
                return $existing->response_snapshot ?? [];
            }

            if ($existing->status === 'pending') {
                throw new IdempotencyConflictException(
                    'Operasi dengan kunci ini sedang diproses. Silakan coba kembali sesaat lagi.',
                    'OPERATION_IN_PROGRESS',
                    ['key' => $key]
                );
            }
        }

        // 2. Create pending record
        $operationKey = OperationKey::create([
            'user_id' => $userId,
            'operation' => $operation,
            'key' => $key,
            'payload_hash' => $payloadHash,
            'status' => 'pending',
            'request_id' => $requestId,
        ]);

        try {
            $result = $callback();

            $resourceType = $result['resource_type'] ?? null;
            $resourceId = $result['resource_id'] ?? null;
            $responseSnapshot = $result['response'] ?? $result;

            $operationKey->update([
                'status' => 'succeeded',
                'result_resource_type' => $resourceType,
                'result_resource_id' => $resourceId,
                'response_snapshot' => $responseSnapshot,
            ]);

            return $responseSnapshot;
        } catch (Throwable $e) {
            $operationKey->update([
                'status' => 'failed',
            ]);
            throw $e;
        }
    }

    /**
     * Look up the outcome of an operation key for recovery.
     */
    public function lookup(int $userId, string $operation, string $key): array
    {
        $existing = OperationKey::where('user_id', $userId)
            ->where('operation', $operation)
            ->where('key', $key)
            ->first();

        if (!$existing) {
            return [
                'status' => 'absent',
                'operation' => $operation,
                'key' => $key,
                'result' => null,
            ];
        }

        return [
            'status' => $existing->status,
            'operation' => $existing->operation,
            'key' => $existing->key,
            'resource_type' => $existing->result_resource_type,
            'resource_id' => $existing->result_resource_id,
            'result' => $existing->response_snapshot,
            'request_id' => $existing->request_id,
            'created_at' => $existing->created_at?->toIso8601String(),
        ];
    }
}
