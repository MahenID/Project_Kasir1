<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only audit event browser (FR-AUDIT-01, PRD §10.2). No write or delete
 * endpoints exist anywhere for this resource.
 *
 * Permissions:
 * - owner (`view-all-audit-logs`): every log entry.
 * - manager (`view-operational-audit-logs`): operational logs only
 *   (sales, shifts, cash, returns, inventory) — sensitive settings/users
 *   activity is excluded.
 * - cashier: FORBIDDEN.
 */
class AuditEventController extends Controller
{
    /** Log names considered operational (safe for manager review). */
    protected const OPERATIONAL_LOGS = ['sales', 'shifts', 'cash', 'returns', 'inventory', 'receiving', 'stocktake', 'inventory_adjustments'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertAuditAccess($user);

        $data = $request->validate([
            'log_name' => ['nullable', 'string', 'max:50'],
            'event' => ['nullable', 'string', 'max:100'],
            'subject_type' => ['nullable', 'string', 'max:150'],
            'subject_id' => ['nullable', 'integer'],
            'causer_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Activity::query()->orderByDesc('id');

        $isOwner = $user->can('view-all-audit-logs');
        if (!$isOwner) {
            $query->whereIn('log_name', self::OPERATIONAL_LOGS);
        }
        if (isset($data['log_name'])) {
            $query->where('log_name', $data['log_name']);
        }
        if (isset($data['event'])) {
            $query->where('description', 'like', '%' . $data['event'] . '%');
        }
        if (isset($data['subject_type'])) {
            $query->where('subject_type', $data['subject_type']);
        }
        if (isset($data['subject_id'])) {
            $query->where('subject_id', (int) $data['subject_id']);
        }
        if (isset($data['causer_id'])) {
            $query->where('causer_id', (int) $data['causer_id']);
        }

        $page = $query->paginate($data['per_page'] ?? 25);
        $page->getCollection()->transform(fn (Activity $entry) => $this->buildPayload($entry, $isOwner));

        return ApiResponse::success($page->items(), 'Log aktivitas.', 200, [
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Activity $auditEvent): JsonResponse
    {
        $user = $request->user();
        $this->assertAuditAccess($user);
        $isOwner = $user->can('view-all-audit-logs');
        if (!$isOwner && !in_array($auditEvent->log_name, self::OPERATIONAL_LOGS, true)) {
            throw new DomainException('Log ini tidak termasuk cakupan operasional.', 'FORBIDDEN', 403);
        }
        return ApiResponse::success($this->buildPayload($auditEvent, $isOwner));
    }

    protected function assertAuditAccess($user): void
    {
        if (!$user || (!$user->can('view-all-audit-logs') && !$user->can('view-operational-audit-logs'))) {
            throw new DomainException('Anda tidak berhak melihat log aktivitas.', 'FORBIDDEN', 403);
        }
    }

    protected function buildPayload(Activity $entry, bool $isOwner): array
    {
        $payload = [
            'id' => $entry->id,
            'log_name' => $entry->log_name,
            'description' => $entry->description,
            'event' => $entry->event,
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id,
            'causer' => $entry->causer ? [
                'id' => $entry->causer->id,
                'name' => $entry->causer->name,
            ] : null,
            'properties' => $entry->properties,
            'request_id' => $entry->properties['request_id'] ?? null,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];

        if ($isOwner && isset($entry->properties['before'], $entry->properties['after'])) {
            $payload['before'] = $entry->properties['before'];
            $payload['after'] = $entry->properties['after'];
        }

        return $payload;
    }
}
