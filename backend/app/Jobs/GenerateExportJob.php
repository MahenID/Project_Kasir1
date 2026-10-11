<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ExportJob;
use App\Services\ExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Generates a report artifact off the request cycle (FR-REP-03: large exports
 * run as tracked jobs). Failures are recorded on the job row with a stable
 * failure code so the client can poll GET /exports/{id}.
 */
class GenerateExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public string $exportJobId) {}

    public function handle(ExportService $exports): void
    {
        $job = ExportJob::find($this->exportJobId);
        if (!$job || $job->status === 'completed') {
            return;
        }

        $job->update(['status' => 'processing', 'failure_code' => null, 'error_message' => null]);

        try {
            $snapshot = $job->filters_snapshot ?? [];
            $rows = $exports->buildRows($job->report_type, $snapshot);
            $path = $exports->storeArtifact($job->id, $snapshot['format'] ?? 'xlsx', $rows, $snapshot);

            $job->update([
                'status' => 'completed',
                'file_path' => $path,
                'row_count' => max(0, count($rows) - 1),
            ]);
        } catch (Throwable $e) {
            $job->update([
                'status' => 'failed',
                'failure_code' => 'EXPORT_GENERATION_FAILED',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        ExportJob::whereKey($this->exportJobId)->update([
            'status' => 'failed',
            'failure_code' => 'EXPORT_GENERATION_FAILED',
            'error_message' => mb_substr($e->getMessage(), 0, 500),
        ]);
    }
}
