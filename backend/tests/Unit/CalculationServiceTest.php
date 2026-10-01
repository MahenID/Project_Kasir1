<?php

namespace Tests\Unit;

use App\Services\CalculationService;
use PHPUnit\Framework\TestCase;

class CalculationServiceTest extends TestCase
{
    public function test_line_item_calculation_with_percent_discount(): void
    {
        // 3 items @ Rp 5,000 = Rp 15,000 gross. Discount 10% (1,000 bps) = Rp 1,500. Base = Rp 13,500.
        $calc = CalculationService::calculateLineItem(3, 5000, 'percent', 1000);

        $this->assertEquals(15000, $calc['gross']);
        $this->assertEquals(1500, $calc['item_discount']);
        $this->assertEquals(13500, $calc['base']);
    }

    public function test_line_item_calculation_with_fixed_discount_capped_at_gross(): void
    {
        // 2 items @ Rp 1,000 = Rp 2,000. Discount fixed Rp 5,000 -> capped at Rp 2,000. Base = Rp 0.
        $calc = CalculationService::calculateLineItem(2, 1000, 'fixed', 5000);

        $this->assertEquals(2000, $calc['gross']);
        $this->assertEquals(2000, $calc['item_discount']);
        $this->assertEquals(0, $calc['base']);
    }

    public function test_sale_quote_largest_remainder_discount_allocation_and_tax(): void
    {
        // Lines:
        // Item 1: Q=1, P=10,000, Base=10,000
        // Item 2: Q=1, P=10,000, Base=10,000
        // Item 3: Q=1, P=10,000, Base=10,000
        // Total Base = 30,000.
        // Whole-cart discount fixed = Rp 1,000.
        // Exact allocation: 1000 / 3 = 333.3333... per item.
        // Floored allocations: 333, 333, 333 (sum = 999, leftover = 1).
        // Item 1 (smallest product_id among equal remainders) gets the +1 rupiah -> 334, 333, 333.
        // Total allocated MUST equal 1,000!

        $lines = [
            ['product_id' => 1, 'quantity' => 1, 'unit_price' => 10000],
            ['product_id' => 2, 'quantity' => 1, 'unit_price' => 10000],
            ['product_id' => 3, 'quantity' => 1, 'unit_price' => 10000],
        ];

        $quote = CalculationService::calculateSaleQuote($lines, 'fixed', 1000, true, 1100);

        $this->assertEquals(30000, $quote['gross_total']);
        $this->assertEquals(30000, $quote['base_total']);
        $this->assertEquals(1000, $quote['sale_discount_total']);

        $items = $quote['items'];
        $this->assertEquals(334, $items[0]['allocated_sale_discount']);
        $this->assertEquals(333, $items[1]['allocated_sale_discount']);
        $this->assertEquals(333, $items[2]['allocated_sale_discount']);

        // Verify net amounts:
        // Item 1: 10,000 - 334 = 9,666. Tax @ 11%: round_half_up(9666 * 0.11) = round(1063.26) = 1,063.
        // Item 2: 10,000 - 333 = 9,667. Tax @ 11%: round_half_up(9667 * 0.11) = round(1063.37) = 1,063.
        // Item 3: 10,000 - 333 = 9,667. Tax @ 11%: 1,063.
        $this->assertEquals(9666, $items[0]['net']);
        $this->assertEquals(1063, $items[0]['tax']);
        $this->assertEquals(10729, $items[0]['line_total']);

        $this->assertEquals(9667, $items[1]['net']);
        $this->assertEquals(1063, $items[1]['tax']);
        $this->assertEquals(10730, $items[1]['line_total']);

        $this->assertEquals(29000, $quote['net_total']);
        $this->assertEquals(3189, $quote['tax_total']);
        $this->assertEquals(32189, $quote['grand_total']);
    }

    public function test_moving_weighted_average_cost_calculation(): void
    {
        // Existing stock: 100 units @ Rp 2,000.000000 = Rp 200,000
        // Incoming receipt: 50 units @ Rp 2,500.000000 = Rp 125,000
        // Total value = Rp 325,000 for 150 units -> 325,000 / 150 = 2166.666667
        $newCost = CalculationService::calculateMovingAverageCost(100, '2000.000000', 50, '2500.000000');

        $this->assertEquals('2166.666667', $newCost);
    }

    public function test_moving_weighted_average_cost_when_current_stock_is_zero(): void
    {
        // Current stock 0 -> new cost is incoming acquisition cost
        $newCost = CalculationService::calculateMovingAverageCost(0, '0.000000', 25, '1500.000000');

        $this->assertEquals('1500.000000', $newCost);
    }
}
