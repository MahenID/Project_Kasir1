<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\ReportDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reporting per PRD §4.6 (FR-REP-01, FR-REP-02), §4.3 (FR-INV-05),
 * §8.1 metric definitions, and §8.2 (persisted records only).
 *
 * All monetary figures are integer rupiah (BIGINT). COGS uses DECIMAL(20,6)
 * snapshots converted through bcmath, never binary float.
 */
class ReportService
{
    public function __construct(protected ShiftService $shifts) {}

    /**
     * Resolve a date range using the store timezone unless the caller overrides it.
     */
    public function resolveRange(?string $startDate, ?string $endDate, ?string $timezone = null): ReportDateRange
    {
        $tz = $timezone ?: (string) (StoreSetting::query()->value('timezone') ?: 'Asia/Makassar');
        return ReportDateRange::fromInputs($startDate, $endDate, $tz);
    }

    /**
     * FR-REP-01: net sales, tax, collected payments, COGS, gross profit, count, ATV.
     */
    public function salesSummary(ReportDateRange $range): array
    {
        // --- Original completed sales in the interval (event = completed_at) ---
        $saleAgg = Sale::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', $range->start)
            ->where('completed_at', '<', $range->end)
            ->selectRaw('COUNT(*) as sale_count')
            ->selectRaw('COALESCE(SUM(net_total), 0) as net_total')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as tax_amount')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as grand_total')
            ->selectRaw('COALESCE(SUM(total_cost), 0) as total_cost')
            ->first();

        $saleCount = (int) $saleAgg->sale_count;
        $saleNet = (int) $saleAgg->net_total;
        $saleTax = (int) $saleAgg->tax_amount;
        $saleGrand = (int) $saleAgg->grand_total;
        $saleCostCents = $this->toMicros((string) $saleAgg->total_cost);

        // --- Completed returns in the interval (event = completed_at) ---
        $completedReturns = ReturnRequest::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', $range->start)
            ->where('completed_at', '<', $range->end)
            ->pluck('id');

        $returnLines = ReturnItem::query()
            ->whereIn('return_request_id', $completedReturns)
            ->get(['quantity', 'refund_net_amount', 'refund_tax_amount', 'disposition', 'restored_cost_snapshot']);

        $returnNet = (int) $returnLines->sum('refund_net_amount');
        $returnTax = (int) $returnLines->sum('refund_tax_amount');

        // Damaged returns do NOT reverse COGS under this baseline (PRD §8.1).
        $restockableCostMicros = 0;
        foreach ($returnLines as $line) {
            if ($line->disposition === 'restockable') {
                $restockableCostMicros += $this->toMicros((string) $line->restored_cost_snapshot);
            }
        }

        $netSalesExTax = $saleNet - $returnNet;
        $netTax = $saleTax - $returnTax;
        $netCogsMicros = $saleCostCents - $restockableCostMicros;
        $netCogs = $this->fromMicrosToInt($netCogsMicros);
        $grossProfit = $netSalesExTax - $netCogs;

        $atv = $saleCount === 0 ? 0 : intdiv($saleGrand, $saleCount);

        // --- Net collected payments grouped by method (event = payment time) ---
        $paymentRows = Payment::query()
            ->where('confirmed_at', '>=', $range->start)
            ->where('confirmed_at', '<', $range->end)
            ->selectRaw('method, COALESCE(SUM(amount_due), 0) as total, COUNT(*) as cnt')
            ->groupBy('method')
            ->get();

        $refundRows = Refund::query()
            ->where('completed_at', '>=', $range->start)
            ->where('completed_at', '<', $range->end)
            ->selectRaw('method, COALESCE(SUM(amount), 0) as total')
            ->groupBy('method')
            ->get();

        $methods = ['cash', 'qris', 'edc', 'transfer'];
        $collected = [];
        $refunded = [];
        $netCollected = [];
        $totalCollected = 0;
        $totalRefunded = 0;
        foreach ($methods as $method) {
            $c = (int) ($paymentRows->firstWhere('method', $method)->total ?? 0);
            $r = (int) ($refundRows->firstWhere('method', $method)->total ?? 0);
            $collected[$method] = $c;
            $refunded[$method] = $r;
            $netCollected[$method] = $c - $r;
            $totalCollected += $c;
            $totalRefunded += $r;
        }

        return [
            'period' => $range->toArray(),
            'currency' => 'IDR',
            'gross_profit_is_estimate' => true,
            'net_sales_excluding_tax' => $netSalesExTax,
            'gross_sales_net_amount' => $saleNet,
            'return_net_amount' => $returnNet,
            'net_tax_collected' => $netTax,
            'tax_collected' => $saleTax,
            'tax_refunded' => $returnTax,
            'net_cogs' => $netCogs,
            'estimated_gross_profit' => $grossProfit,
            'completed_sale_count' => $saleCount,
            'average_transaction_value' => $atv,
            'gross_transaction_total' => $saleGrand,
            'payments' => [
                'collected' => $collected,
                'refunded' => $refunded,
                'net' => $netCollected,
                'total_collected' => $totalCollected,
                'total_refunded' => $totalRefunded,
                'total_net' => $totalCollected - $totalRefunded,
            ],
        ];
    }

    /**
     * FR-REP-02: top products by net units or net sales value.
     *
     * @param string $sort units|value
     */
    public function topProducts(ReportDateRange $range, string $sort = 'units', int $limit = 10): array
    {
        if (!in_array($sort, ['units', 'value'], true)) {
            throw new DomainException("Urutan '{$sort}' tidak dikenal.", 'INVALID_SORT', 422, ['sort' => $sort]);
        }
        $limit = max(1, min($limit, 50));

        // Original sold units and net amount, per product snapshot (persisted records).
        $sold = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sales.completed_at', '>=', $range->start)
            ->where('sales.completed_at', '<', $range->end)
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id as product_id')
            ->selectRaw('MAX(sale_items.sku_snapshot) as sku')
            ->selectRaw('MAX(sale_items.name_snapshot) as name')
            ->selectRaw('SUM(sale_items.quantity) as sold_units')
            ->selectRaw('SUM(sale_items.net_amount) as sold_net')
            ->get()
            ->keyBy('product_id');

        $returned = ReturnItem::query()
            ->join('return_requests', 'return_requests.id', '=', 'return_items.return_request_id')
            ->join('sale_items', 'sale_items.id', '=', 'return_items.sale_item_id')
            ->where('return_requests.status', 'completed')
            ->where('return_requests.completed_at', '>=', $range->start)
            ->where('return_requests.completed_at', '<', $range->end)
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id as product_id')
            ->selectRaw('SUM(return_items.quantity) as returned_units')
            ->selectRaw('SUM(return_items.refund_net_amount) as returned_net')
            ->get()
            ->keyBy('product_id');

        $rows = [];
        foreach ($sold as $productId => $row) {
            $ret = $returned->get($productId);
            $netUnits = (int) $row->sold_units - (int) ($ret->returned_units ?? 0);
            $netValue = (int) $row->sold_net - (int) ($ret->returned_net ?? 0);
            $rows[] = [
                'product_id' => (int) $productId,
                'sku' => $row->sku,
                'name' => $row->name,
                'sold_units' => (int) $row->sold_units,
                'returned_units' => (int) ($ret->returned_units ?? 0),
                'net_product_units' => $netUnits,
                'net_sales_value' => $netValue,
            ];
        }

        // Include products returned but sold outside the window (negative net units).
        foreach ($returned as $productId => $ret) {
            if ($sold->has($productId)) {
                continue;
            }
            $product = Product::find($productId);
            $rows[] = [
                'product_id' => (int) $productId,
                'sku' => $product?->sku,
                'name' => $product?->name,
                'sold_units' => 0,
                'returned_units' => (int) $ret->returned_units,
                'net_product_units' => -(int) $ret->returned_units,
                'net_sales_value' => -(int) $ret->returned_net,
            ];
        }

        $key = $sort === 'units' ? 'net_product_units' : 'net_sales_value';
        usort($rows, fn ($a, $b) => $b[$key] <=> $a[$key]);
        $rows = array_slice($rows, 0, $limit);

        return [
            'period' => $range->toArray(),
            'sort' => $sort,
            'items' => array_map(fn ($r) => ['rank' => array_search($r, $rows, true) + 1] + $r, $rows),
        ];
    }

    /**
     * FR-REP-02 / FR-INV-05: current stock on hand and valuation (sellable qty × current average cost).
     */
    public function stockOnHand(int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        $products = Product::query()
            ->orderBy('stock')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'sku', 'name', 'unit', 'stock', 'average_cost', 'min_stock', 'is_active']);

        $items = [];
        $totalValueMicros = 0;
        foreach ($products as $product) {
            $qty = (int) $product->stock;
            $costMicros = $this->toMicros((string) $product->average_cost);
            $valueMicros = $qty * $costMicros;
            $totalValueMicros += $valueMicros;
            $items[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'unit' => $product->unit,
                'quantity_on_hand' => $qty,
                'average_cost' => $this->microsToDecimalString($costMicros),
                'inventory_value' => $this->fromMicrosToInt($valueMicros),
                'minimum_stock' => (int) $product->min_stock,
                'is_low_stock' => $qty <= (int) $product->min_stock,
                'is_active' => (bool) $product->is_active,
            ];
        }

        return [
            'total_products' => Product::count(),
            'returned_rows' => count($items),
            'total_inventory_value' => $this->fromMicrosToInt($totalValueMicros),
            'valuation_basis' => 'sellable_quantity_x_current_average_cost',
            'items' => $items,
        ];
    }

    /**
     * FR-REP-02: shift cash differences (actual - expected at immutable closure).
     */
    public function shiftDifferences(ReportDateRange $range): array
    {
        $shifts = Shift::query()
            ->with(['user:id,name', 'terminal:id,code,name'])
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $range->start)
            ->where('closed_at', '<', $range->end)
            ->orderBy('closed_at')
            ->get();

        $rows = [];
        $totalDifference = 0;
        $overCount = 0;
        $shortCount = 0;
        foreach ($shifts as $shift) {
            $difference = (int) $shift->difference;
            $totalDifference += $difference;
            if ($difference > 0) {
                $overCount++;
            } elseif ($difference < 0) {
                $shortCount++;
            }
            $rows[] = [
                'shift_id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'cashier' => $shift->user?->name,
                'terminal' => $shift->terminal?->code,
                'opened_at' => $shift->opened_at?->toIso8601String(),
                'closed_at' => $shift->closed_at?->toIso8601String(),
                'starting_cash' => (int) $shift->starting_cash,
                'expected_cash' => (int) $shift->expected_cash,
                'actual_cash' => (int) $shift->actual_cash,
                'difference' => $difference,
                'has_explanation' => trim((string) $shift->explanation) !== '',
                'review_status' => $shift->review_status,
            ];
        }

        return [
            'period' => $range->toArray(),
            'closed_shift_count' => count($rows),
            'total_difference' => $totalDifference,
            'over_count' => $overCount,
            'short_count' => $shortCount,
            'balanced_count' => count($rows) - $overCount - $shortCount,
            'items' => $rows,
        ];
    }

    /**
     * FR-REP-02: receiving documents summary in the interval.
     */
    public function receivingSummary(ReportDateRange $range): array
    {
        $postedIds = DB::table('receivings')
            ->where('status', 'posted')
            ->where('received_at', '>=', $range->start)
            ->where('received_at', '<', $range->end)
            ->pluck('id');

        $agg = DB::table('receiving_items')
            ->whereIn('receiving_id', $postedIds)
            ->selectRaw('COALESCE(SUM(quantity), 0) as total_quantity')
            ->selectRaw('COALESCE(SUM(extended_cost), 0) as total_cost')
            ->first();

        return [
            'period' => $range->toArray(),
            'posted_document_count' => $postedIds->count(),
            'total_quantity' => (int) ($agg->total_quantity ?? 0),
            'total_cost' => $this->fromMicrosToInt($this->toMicros((string) ($agg->total_cost ?? '0'))),
        ];
    }

    /**
     * FR-REP-02: returns summary in the interval.
     */
    public function returnsSummary(ReportDateRange $range): array
    {
        $requests = ReturnRequest::query()
            ->where('completed_at', '>=', $range->start)
            ->where('completed_at', '<', $range->end)
            ->where('status', 'completed')
            ->get(['id']);

        $ids = $requests->pluck('id');
        $items = ReturnItem::query()->whereIn('return_request_id', $ids)->get(['quantity', 'disposition', 'refund_total_amount']);
        $refunds = Refund::query()
            ->whereIn('return_request_id', $ids)
            ->selectRaw('method, COALESCE(SUM(amount), 0) as total')
            ->groupBy('method')
            ->get();

        $byMethod = ['cash' => 0, 'qris' => 0, 'edc' => 0, 'transfer' => 0];
        foreach ($refunds as $row) {
            $byMethod[$row->method] = (int) $row->total;
        }

        return [
            'period' => $range->toArray(),
            'completed_return_count' => $ids->count(),
            'total_returned_units' => (int) $items->sum('quantity'),
            'restockable_units' => (int) $items->where('disposition', 'restockable')->sum('quantity'),
            'damaged_units' => (int) $items->where('disposition', 'damaged')->sum('quantity'),
            'total_refund_amount' => (int) $items->sum('refund_total_amount'),
            'refunds_by_method' => $byMethod,
        ];
    }

    // --- fixed-point helpers (bcmath) ---------------------------------------

    private function toMicros(string $decimal): int
    {
        // Convert a DECIMAL(20,6) string into an integer amount scaled by 1e6.
        if ($decimal === '' || $decimal === null) {
            return 0;
        }
        $scaled = bcmul($decimal, '1000000', 0);
        return (int) $scaled;
    }

    private function fromMicrosToInt(int $micros): int
    {
        // Round half-up to the nearest whole rupiah from micros.
        if ($micros >= 0) {
            return (int) bcdiv(bcadd((string) $micros, '500000', 0), '1000000', 0);
        }
        return -(int) bcdiv(bcadd((string) (-$micros), '500000', 0), '1000000', 0);
    }

    private function microsToDecimalString(int $micros): string
    {
        return bcdiv((string) $micros, '1000000', 6);
    }
}
