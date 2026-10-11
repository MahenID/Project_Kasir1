<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateExportJob;
use App\Models\ExportJob;
use App\Services\ExportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tracked XLSX/PDF exports (FR-REP-03). Create a job, poll its status, and
 * download with a fresh permission check (AC-28).
 */
class ExportController extends Controller
{
    public function __construct(protected ExportService $exports) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanExport($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'processing', 'completed', 'failed'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = ExportJob::query();
        if (!$user->can('view-all-reports')) {
            $query->where('user_id', $user->id);
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        $page = $query->orderByDesc('created_at')->paginate($data['per_page'] ?? 20);
        $includeArtifact = $user->can('view-all-reports');
        $page->getCollection()->transform(fn (ExportJob $job) => $this->exports->buildJobPayload($job, $includeArtifact));

        return ApiResponse::success($page->items(), 'Daftar ekspor.', 200, [
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanExport($request);
        $data = $request->validate([
            'report_type' => ['required', Rule::in(ExportService::REPORT_TYPES)],
            'format' => ['required', Rule::in(ExportService::FORMATS)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'sort' => ['nullable', Rule::in(['units', 'value'])],
        ]);

        $job = $this->exports->createJob($request->user(), $data['report_type'], $data['format'], $data);
        GenerateExportJob::dispatch($job->id);
        $job->refresh();

        return ApiResponse::success($this->exports->buildJobPayload($job, true), 'Ekspor sedang diproses.', 202);
    }

    public function show(Request $request, ExportJob $export): JsonResponse
    {
        $this->assertCanExport($request);
        $user = $request->user();
        if ($export->user_id !== $user->id && !$user->can('view-all-reports')) {
            throw new DomainException('Anda tidak berhak melihat ekspor ini.', 'FORBIDDEN', 403);
        }
        return ApiResponse::success($this->exports->buildJobPayload($export, $user->can('view-all-reports') || $export->user_id === $user->id));
    }

    public function download(Request $request, ExportJob $export)
    {
        $this->assertCanExport($request);
        $user = $request->user();
        $path = $this->exports->assertDownloadable($user, $export, $user->can('view-all-reports'));

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';
        $mime = $extension === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $downloadName = "laporan-{$export->report_type}-{$export->id}.{$extension}";

        return $disk->download($path, $downloadName, ['Content-Type' => $mime]);
    }

    protected function assertCanExport(Request $request): void
    {
        $user = $request->user();
        if (!$user || (!$user->can('view-all-reports') && !$user->can('view-own-reports'))) {
            throw new DomainException('Anda tidak berhak mengakses ekspor.', 'FORBIDDEN', 403);
        }
    }
}
