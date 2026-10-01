<?php

namespace App\Services;

class CalculationService
{
    /**
     * Round half-up to specified decimal places using string/bc arithmetic.
     */
    public static function roundHalfUp(string|float|int $value, int $decimals = 0): string
    {
        $val = (float) $value;
        $multiplier = 10 ** $decimals;
        $rounded = round($val * $multiplier, 0, PHP_ROUND_HALF_UP) / $multiplier;

        return number_format($rounded, $decimals, '.', '');
    }

    /**
     * Calculate line item gross, item discount, and base amount.
     *
     * @param int $quantity Whole-unit quantity (>= 1)
     * @param int $unitPrice Integer rupiah selling price
     * @param string|null $discountType 'percent' or 'fixed' or null
     * @param int|null $discountValue Basis points (100 bps = 1%) or integer rupiah
     */
    public static function calculateLineItem(
        int $quantity,
        int $unitPrice,
        ?string $discountType = null,
        ?int $discountValue = null
    ): array {
        $gross = $quantity * $unitPrice;
        $itemDiscount = 0;

        if ($discountType === 'percent' && $discountValue > 0) {
            // discountValue in basis points (e.g. 500 = 5.00%, 10000 = 100.00%)
            $itemDiscount = (int) self::roundHalfUp(($gross * $discountValue) / 10000);
            $itemDiscount = min($gross, $itemDiscount);
        } elseif ($discountType === 'fixed' && $discountValue > 0) {
            $itemDiscount = min($gross, $discountValue);
        }

        $base = $gross - $itemDiscount;

        return [
            'gross' => $gross,
            'discount_type' => $discountType,
            'discount_input' => $discountValue,
            'item_discount' => $itemDiscount,
            'base' => $base,
        ];
    }

    /**
     * Calculate a full sale quote with largest-remainder discount allocation and exclusive tax.
     *
     * @param array $lines Array of line definitions: [['product_id' => 1, 'quantity' => 2, 'unit_price' => 5000, 'discount_type' => null, 'discount_value' => null, ...]]
     * @param string|null $saleDiscountType 'percent' or 'fixed' or null
     * @param int|null $saleDiscountValue Basis points or integer rupiah
     * @param bool $taxEnabled Whether tax is applied
     * @param int $taxRateBps Tax rate in basis points (e.g. 1100 = 11.00%)
     */
    public static function calculateSaleQuote(
        array $lines,
        ?string $saleDiscountType = null,
        ?int $saleDiscountValue = null,
        bool $taxEnabled = false,
        int $taxRateBps = 0
    ): array {
        $calculatedLines = [];
        $totalGross = 0;
        $totalItemDiscount = 0;
        $totalBase = 0;

        // 1. Calculate individual line items
        foreach ($lines as $line) {
            $productId = $line['product_id'];
            $qty = (int) $line['quantity'];
            $price = (int) $line['unit_price'];
            $dType = $line['discount_type'] ?? null;
            $dVal = isset($line['discount_value']) ? (int) $line['discount_value'] : null;

            $itemCalc = self::calculateLineItem($qty, $price, $dType, $dVal);

            $calculatedLines[] = array_merge($line, [
                'gross' => $itemCalc['gross'],
                'item_discount_type' => $itemCalc['discount_type'],
                'item_discount_input' => $itemCalc['discount_input'],
                'item_discount' => $itemCalc['item_discount'],
                'base' => $itemCalc['base'],
            ]);

            $totalGross += $itemCalc['gross'];
            $totalItemDiscount += $itemCalc['item_discount'];
            $totalBase += $itemCalc['base'];
        }

        // 2. Calculate whole-cart / sale discount
        $totalSaleDiscount = 0;
        if ($saleDiscountType === 'percent' && $saleDiscountValue > 0) {
            $totalSaleDiscount = (int) self::roundHalfUp(($totalBase * $saleDiscountValue) / 10000);
            $totalSaleDiscount = min($totalBase, $totalSaleDiscount);
        } elseif ($saleDiscountType === 'fixed' && $saleDiscountValue > 0) {
            $totalSaleDiscount = min($totalBase, $saleDiscountValue);
        }

        // 3. Allocate sale discount proportionally using Largest-Remainder Method (Hamilton-Hare)
        $allocatedDiscounts = array_fill(0, count($calculatedLines), 0);

        if ($totalBase > 0 && $totalSaleDiscount > 0) {
            $remainders = [];
            $sumFloored = 0;

            foreach ($calculatedLines as $idx => $line) {
                if ($line['base'] <= 0) {
                    $allocatedDiscounts[$idx] = 0;
                    continue;
                }

                $exact = ($line['base'] * $totalSaleDiscount) / $totalBase;
                $floor = (int) floor($exact);
                $allocatedDiscounts[$idx] = $floor;
                $sumFloored += $floor;

                $remainders[] = [
                    'index' => $idx,
                    'product_id' => $line['product_id'],
                    'remainder' => $exact - $floor,
                ];
            }

            $leftover = $totalSaleDiscount - $sumFloored;

            if ($leftover > 0 && count($remainders) > 0) {
                // Sort by remainder descending, tie-break by product_id ascending
                usort($remainders, function ($a, $b) {
                    if ($b['remainder'] == $a['remainder']) {
                        return $a['product_id'] <=> $b['product_id'];
                    }
                    return $b['remainder'] <=> $a['remainder'];
                });

                for ($i = 0; $i < $leftover && $i < count($remainders); $i++) {
                    $targetIdx = $remainders[$i]['index'];
                    $allocatedDiscounts[$targetIdx] += 1;
                }
            }
        }

        // 4. Net, tax, and line total
        $finalLines = [];
        $totalNet = 0;
        $totalTax = 0;
        $grandTotal = 0;

        foreach ($calculatedLines as $idx => $line) {
            $allocatedDiscount = $allocatedDiscounts[$idx];
            $net = $line['base'] - $allocatedDiscount;

            $tax = 0;
            if ($taxEnabled && $taxRateBps > 0) {
                $tax = (int) self::roundHalfUp(($net * $taxRateBps) / 10000);
            }

            $lineTotal = $net + $tax;

            $finalLines[] = array_merge($line, [
                'allocated_sale_discount' => $allocatedDiscount,
                'net' => $net,
                'tax' => $tax,
                'line_total' => $lineTotal,
            ]);

            $totalNet += $net;
            $totalTax += $tax;
            $grandTotal += $lineTotal;
        }

        return [
            'gross_total' => $totalGross,
            'item_discount_total' => $totalItemDiscount,
            'base_total' => $totalBase,
            'sale_discount_type' => $saleDiscountType,
            'sale_discount_input' => $saleDiscountValue,
            'sale_discount_total' => $totalSaleDiscount,
            'net_total' => $totalNet,
            'tax_enabled' => $taxEnabled,
            'tax_rate_bps' => $taxRateBps,
            'tax_total' => $totalTax,
            'grand_total' => $grandTotal,
            'items' => $finalLines,
        ];
    }

    /**
     * Moving weighted-average cost calculation:
     * ((current_stock * current_cost) + (incoming_qty * incoming_cost)) / (current_stock + incoming_qty)
     * Rounded half-up to 6 decimal places.
     */
    public static function calculateMovingAverageCost(
        int $currentStock,
        string|float $currentCost,
        int $incomingQty,
        string|float $incomingCost
    ): string {
        if ($incomingQty <= 0) {
            return self::roundHalfUp($currentCost, 6);
        }

        if ($currentStock <= 0) {
            return self::roundHalfUp($incomingCost, 6);
        }

        $currentStockStr = (string) $currentStock;
        $incomingQtyStr = (string) $incomingQty;
        $currentCostStr = (string) $currentCost;
        $incomingCostStr = (string) $incomingCost;

        $currentTotalVal = bcmul($currentStockStr, $currentCostStr, 8);
        $incomingTotalVal = bcmul($incomingQtyStr, $incomingCostStr, 8);
        $combinedVal = bcadd($currentTotalVal, $incomingTotalVal, 8);
        $totalQty = bcadd($currentStockStr, $incomingQtyStr, 0);

        $newAverageRaw = bcdiv($combinedVal, $totalQty, 8);

        return self::roundHalfUp($newAverageRaw, 6);
    }
}
