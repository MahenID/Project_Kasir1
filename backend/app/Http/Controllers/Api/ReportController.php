<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Permission-filtered reports (FR-REP-01, FR-REP-02).
 *
 * Cashiers only hold `view-own-reports`; per PRD §3 they see their own shift
 * summary only, so every action below requires `view-all-reports` (owner,
 * manager) and cashiers are rejected with FORBIDDEN.
 */
class ReportController extends Controller
{
    public function __construct(protected ReportService $reports) {}

    public function sales(Request $request): JsonResponse
    {
        $this->assertCanViewAll($request);
        $range = $this->reports->resolveRange(
            $request->query('start_date'),
            $request->query('end_date'),
            $request->query('timezone'),
        );
        return ApiResponse::success($this->reports->salesSummary($range), 'Laporan penjualan.');
    }

    public function topProducts(Request $request): JsonResponse
    {
        $this->assertCanViewAll($request);
        $data = $request->validate([
            'sort' => ['nullable', Rule::in(['units', 'value'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $range = $this->reports->resolveRange($request->query('start_date'), $request->query('end_date'), $request->query('timezone'));
        return ApiResponse::success(
            $this->reports->topProducts($range, $data['sort'] ?? 'units', (int) ($data['limit'] ?? 10)),
            'Laporan produk terlaris.',
        );
    }

    public function stock(Request $request): JsonResponse
    {
        $this->assertCanViewAll($request);
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:200']]);
        return ApiResponse::success($this->reports->stockOnHand((int) ($data['limit'] ?? 50)), 'Laporan sisa stok & valuasi.');
    }

    public function shiftDifferences(Request $request): JsonResponse
    {
        $this->assertCanViewAll($request);
        $range = $this->reports->resolveRange($request->query('start_date'), $request->query('end_date'), $request->query('timezone'));
        return ApiResponse::success($this->reports->shiftDifferences($range), 'Laporan selisih kas shift.');
    }

    public function receiving(Request $request): JsonResponse
    {
        $this->assertCanViewAll($request);
        $range = $this->reports->resolveRange($request->query('start_date'), $request->query('end_date'), $request->query('timezone'));
        return ApiResponse::success($this->reports->receivingSummary($range), 'Laporan penerimaan barang.');
    }

    public function returns(Request $request): JsonResponse
    {
        $this->assertCanViewAll($request);
        $range = $this->reports->resolveRange($request->query('start_date'), $request->query('end_date'), $request->query('timezone'));
        return ApiResponse::success($this->reports->returnsSummary($range), 'Laporan retur.');
    }

    protected function assertCanViewAll(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->can('view-all-reports')) {
            throw new DomainException('Anda tidak berhak melihat laporan ini.', 'FORBIDDEN', 403);
        }
    }
}
