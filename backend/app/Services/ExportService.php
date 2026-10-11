<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\ExportJob;
use App\Models\User;
use App\Support\ReportDateRange;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orchestrates FR-REP-03 exports: create a tracked job, dispatch to the database
 * queue, expose status, and stream the artifact with a fresh permission check.
 *
 * PRD §8.2: artifacts are permission-checked, expire after 24 hours, and a
 * download must recheck current user permissions.
 */
class ExportService
{
    public const REPORT_TYPES = [
        'sales',
        'top-products',
        'stock',
        'receiving',
        'returns',
        'shift-differences',
    ];

    public const FORMATS = ['xlsx', 'pdf'];

    public const ARTIFACT_TTL_HOURS = 24;

    public function __construct(protected ReportService $reports) {}

    /**
     * Create a queued export job for the requesting user.
     */
    public function createJob(User $user, string $reportType, string $format, array $filters): ExportJob
    {
        if (!in_array($reportType, self::REPORT_TYPES, true)) {
            throw new DomainException("Jenis laporan '{$reportType}' tidak dikenal.", 'INVALID_REPORT_TYPE', 422, ['report_type' => $reportType]);
        }
        if (!in_array($format, self::FORMATS, true)) {
            throw new DomainException("Format ekspor '{$format}' tidak didukung.", 'INVALID_EXPORT_FORMAT', 422, ['format' => $format]);
        }

        // Resolve and validate the date range now, so the job snapshot is canonical.
        $range = $this->reports->resolveRange($filters['start_date'] ?? null, $filters['end_date'] ?? null, $filters['timezone'] ?? null);

        $snapshot = [
            'report_type' => $reportType,
            'format' => $format,
            'start_date' => $filters['start_date'] ?? null,
            'end_date' => $filters['end_date'] ?? null,
            'timezone' => $range->timezone,
            'period_start_utc' => $range->start,
            'period_end_utc' => $range->end,
        ];
        if (isset($filters['sort'])) {
            $snapshot['sort'] = $filters['sort'];
        }

        return ExportJob::create([
            'user_id' => $user->id,
            'report_type' => $reportType,
            'filters_snapshot' => $snapshot,
            'status' => 'pending',
            'expires_at' => now()->addHours(self::ARTIFACT_TTL_HOURS),
        ]);
    }

    /**
     * Build the tabular rows for a report type (header + data rows). Reused by
     * both the API totals and the artifact writer so outputs agree (AC-27).
     *
     * @return list<list<string|int|float|null>>
     */
    public function buildRows(string $reportType, array $snapshot): array
    {
        $range = new ReportDateRange();
        $range->start = $snapshot['period_start_utc'];
        $range->end = $snapshot['period_end_utc'];
        $range->timezone = $snapshot['timezone'] ?? 'Asia/Makassar';

        return match ($reportType) {
            'sales' => $this->salesRows($range),
            'top-products' => $this->topProductsRows($range, $snapshot['sort'] ?? 'units'),
            'stock' => $this->stockRows(),
            'receiving' => $this->receivingRows($range),
            'returns' => $this->returnsRows($range),
            'shift-differences' => $this->shiftDifferenceRows($range),
            default => throw new DomainException("Jenis laporan '{$reportType}' tidak dikenal.", 'INVALID_REPORT_TYPE', 422),
        };
    }

    private function salesRows(ReportDateRange $range): array
    {
        $s = $this->reports->salesSummary($range);
        return [
            ['Metrik', 'Nilai'],
            ['Periode mulai (UTC)', $s['period']['start']],
            ['Periode selesai (UTC, eksklusif)', $s['period']['end']],
            ['Zona waktu', $s['period']['timezone']],
            ['Net Sales (tanpa pajak)', $s['net_sales_excluding_tax']],
            ['PPN terkumpul (net)', $s['net_tax_collected']],
            ['PPN dipungut', $s['tax_collected']],
            ['PPN dikembalikan', $s['tax_refunded']],
            ['HPP (net COGS)', $s['net_cogs']],
            ['Estimasi laba kotor', $s['estimated_gross_profit']],
            ['Jumlah transaksi selesai', $s['completed_sale_count']],
            ['Nilai transaksi rata-rata', $s['average_transaction_value']],
            ['Total pembayaran terkumpul', $s['payments']['total_collected']],
            ['Total pengembalian dana', $s['payments']['total_refunded']],
            ['Pembayaran net', $s['payments']['total_net']],
        ];
    }

    private function topProductsRows(ReportDateRange $range, string $sort): array
    {
        $data = $this->reports->topProducts($range, $sort, 50);
        $rows = [['Peringkat', 'SKU', 'Nama', 'Unit terjual', 'Unit retur', 'Unit net', 'Nilai penjualan net']];
        foreach ($data['items'] as $item) {
            $rows[] = [
                $item['rank'],
                $item['sku'],
                $item['name'],
                $item['sold_units'],
                $item['returned_units'],
                $item['net_product_units'],
                $item['net_sales_value'],
            ];
        }
        return $rows;
    }

    private function stockRows(): array
    {
        $data = $this->reports->stockOnHand(200);
        $rows = [['Produk ID', 'SKU', 'Nama', 'Satuan', 'Stok', 'Harga pokok rata-rata', 'Nilai inventaris', 'Stok minimum', 'Stok rendah', 'Aktif']];
        foreach ($data['items'] as $item) {
            $rows[] = [
                $item['product_id'],
                $item['sku'],
                $item['name'],
                $item['unit'],
                $item['quantity_on_hand'],
                $item['average_cost'],
                $item['inventory_value'],
                $item['minimum_stock'],
                $item['is_low_stock'] ? 'ya' : 'tidak',
                $item['is_active'] ? 'ya' : 'tidak',
            ];
        }
        return $rows;
    }

    private function receivingRows(ReportDateRange $range): array
    {
        $s = $this->reports->receivingSummary($range);
        return [
            ['Metrik', 'Nilai'],
            ['Periode mulai (UTC)', $s['period']['start']],
            ['Periode selesai (UTC, eksklusif)', $s['period']['end']],
            ['Jumlah dokumen posted', $s['posted_document_count']],
            ['Total kuantitas diterima', $s['total_quantity']],
            ['Total biaya', $s['total_cost']],
        ];
    }

    private function returnsRows(ReportDateRange $range): array
    {
        $s = $this->reports->returnsSummary($range);
        return [
            ['Metrik', 'Nilai'],
            ['Periode mulai (UTC)', $s['period']['start']],
            ['Periode selesai (UTC, eksklusif)', $s['period']['end']],
            ['Jumlah retur selesai', $s['completed_return_count']],
            ['Total unit diretur', $s['total_returned_units']],
            ['Unit restockable', $s['restockable_units']],
            ['Unit rusak (damaged)', $s['damaged_units']],
            ['Total refund', $s['total_refund_amount']],
            ['Refund tunai', $s['refunds_by_method']['cash'] ?? 0],
            ['Refund qris', $s['refunds_by_method']['qris'] ?? 0],
            ['Refund edc', $s['refunds_by_method']['edc'] ?? 0],
            ['Refund transfer', $s['refunds_by_method']['transfer'] ?? 0],
        ];
    }

    private function shiftDifferenceRows(ReportDateRange $range): array
    {
        $s = $this->reports->shiftDifferences($range);
        $rows = [['Shift', 'Kasir', 'Terminal', 'Ditutup pada', 'Modal awal', 'Kas diharapkan', 'Kas fisik', 'Selisih', 'Catatan', 'Review']];
        foreach ($s['items'] as $item) {
            $rows[] = [
                $item['shift_number'],
                $item['cashier'],
                $item['terminal'],
                $item['closed_at'],
                $item['starting_cash'],
                $item['expected_cash'],
                $item['actual_cash'],
                $item['difference'],
                $item['has_explanation'] ? 'ada' : 'tidak',
                $item['review_status'],
            ];
        }
        return $rows;
    }

    /**
     * Compose artifact metadata (period, timezone, generation time) that must
     * appear on every export per FR-REP-03.
     */
    public function artifactMeta(array $snapshot): array
    {
        return [
            'report_type' => $snapshot['report_type'],
            'format' => $snapshot['format'] ?? 'xlsx',
            'period' => ($snapshot['start_date'] ?? '-') . ' s/d ' . ($snapshot['end_date'] ?? '-') . ' (' . ($snapshot['timezone'] ?? '') . ')',
            'timezone' => $snapshot['timezone'] ?? '',
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    /**
     * Persist artifact bytes to the private exports disk and return its path.
     */
    public function storeArtifact(string $jobId, string $format, array $rows, array $snapshot): string
    {
        $disk = Storage::disk('local');
        $directory = 'exports';
        $disk->makeDirectory($directory);

        $filename = 'exports/' . Str::uuid() . '.' . $format;
        $absolute = $disk->path($filename);

        $meta = $this->artifactMeta($snapshot);
        if ($format === 'xlsx') {
            (new \App\Support\Export\XlsxWriter($rows, [
                'sheet_name' => Str::limit($meta['report_type'], 28, ''),
            ]))->write($absolute);
        } else {
            (new \App\Support\Export\PdfWriter($rows, [
                'title' => 'DragonMart POS — ' . $meta['report_type'],
                'generated_at' => $meta['generated_at'],
                'period' => $meta['period'],
            ]))->write($absolute);
        }

        return $filename;
    }

    /**
     * Download guard: must be owner, job completed, not expired, and the caller
     * must still hold the report permission (AC-28).
     */
    public function assertDownloadable(User $user, ExportJob $job, bool $canViewAll): string
    {
        if ($job->user_id !== $user->id && !$canViewAll) {
            throw new DomainException('Anda tidak berhak mengunduh ekspor ini.', 'FORBIDDEN', 403);
        }
        if ($job->status !== 'completed' || $job->file_path === null) {
            throw new DomainException('Berkas ekspor belum siap.', 'EXPORT_NOT_READY', 409);
        }
        if ($job->expires_at !== null && $job->expires_at->isPast()) {
            throw new DomainException('Berkas ekspor telah kedaluwarsa.', 'EXPORT_EXPIRED', 410);
        }
        if (!Storage::disk('local')->exists($job->file_path)) {
            throw new DomainException('Berkas ekspor tidak ditemukan.', 'EXPORT_MISSING', 410);
        }
        return $job->file_path;
    }

    public function buildJobPayload(ExportJob $job, bool $includeArtifact): array
    {
        $payload = [
            'id' => $job->id,
            'report_type' => $job->report_type,
            'filters' => $job->filters_snapshot,
            'status' => $job->status,
            'row_count' => $job->row_count,
            'failure_code' => $job->failure_code,
            'error_message' => $job->error_message,
            'expires_at' => $job->expires_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
        if ($includeArtifact && $job->status === 'completed') {
            $payload['download_url'] = "/api/v1/exports/{$job->id}/download";
        }
        return $payload;
    }
}
