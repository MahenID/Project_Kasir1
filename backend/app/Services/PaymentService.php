<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Models\Sale;

class PaymentService
{
    /**
     * Manual non-cash methods accepted by the till (no gateway integration in Phase 3).
     */
    public const NON_CASH_METHODS = ['debit', 'credit', 'qris', 'transfer'];

    /**
     * Validate a payment instruction against a computed grand total and return
     * normalized payment figures.
     *
     * Business rules (PRD §5.3):
     * - Cash: tendered >= grand_total; applied = grand_total; change = tendered - grand_total.
     * - Manual non-cash: applied = grand_total; tendered equals total; change is zero.
     *
     * @param string $method 'cash' or one of NON_CASH_METHODS
     * @param int $grandTotal Integer rupiah due
     * @param int|null $tenderedAmount Integer rupiah handed over (required for cash)
     * @param string|null $referenceNumber Manual reference for non-cash
     * @return array{method: string, amount_due: int, amount_paid: int, change_amount: int, reference_number: ?string}
     * @throws DomainException
     */
    public function validatePayment(
        string $method,
        int $grandTotal,
        ?int $tenderedAmount = null,
        ?string $referenceNumber = null
    ): array {
        if ($grandTotal < 0) {
            throw new DomainException('Total transaksi tidak valid.', 'INVALID_TOTAL', 422);
        }

        if ($method === 'cash') {
            if ($tenderedAmount === null) {
                throw new DomainException(
                    'Jumlah uang tunai yang diterima (tendered) wajib diisi untuk pembayaran tunai.',
                    'TENDERED_REQUIRED',
                    422,
                    ['payment' => ['tendered_amount wajib untuk metode cash']]
                );
            }

            if ($tenderedAmount < 0) {
                throw new DomainException(
                    'Jumlah uang tunai tidak boleh negatif.',
                    'INVALID_TENDERED',
                    422
                );
            }

            // A zero-total cash sale is explicitly permitted when configured total is 0.
            if ($tenderedAmount < $grandTotal) {
                throw new DomainException(
                    'Uang tunai yang diterima kurang dari total belanja. Kekurangan: Rp ' .
                        number_format($grandTotal - $tenderedAmount, 0, ',', '.') . '.',
                    'INSUFFICIENT_PAYMENT',
                    422,
                    ['payment' => ['Uang tunai kurang dari total tagihan']]
                );
            }

            return [
                'method' => 'cash',
                'amount_due' => $grandTotal,
                'amount_paid' => $tenderedAmount,
                'change_amount' => $tenderedAmount - $grandTotal,
                'reference_number' => null,
            ];
        }

        if (in_array($method, self::NON_CASH_METHODS, true)) {
            // Manual confirmation: the cashier asserts the exact total was settled externally.
            return [
                'method' => $method,
                'amount_due' => $grandTotal,
                'amount_paid' => $grandTotal,
                'change_amount' => 0,
                'reference_number' => $referenceNumber,
            ];
        }

        throw new DomainException(
            "Metode pembayaran '{$method}' tidak didukung.",
            'INVALID_PAYMENT_METHOD',
            422,
            ['payment' => ['Metode pembayaran tidak dikenal']]
        );
    }

    /**
     * Build an immutable, print-ready receipt payload rendered exclusively from
     * stored sale snapshots. Safe to serve long after product/store data changes.
     */
    public function buildReceiptPayload(Sale $sale): array
    {
        $sale->loadMissing(['items', 'payment']);

        $payment = $sale->payment;

        return [
            'receipt_number' => $sale->receipt_number,
            'completed_at' => $sale->completed_at?->toIso8601String(),
            'store' => $sale->store_snapshot,
            'cashier_name' => $sale->cashier_name_snapshot,
            'terminal_code' => $sale->terminal_code_snapshot,
            'customer' => $sale->customer_snapshot,
            'items' => $sale->items->map(fn ($item) => [
                'name' => $item->name_snapshot,
                'sku' => $item->sku_snapshot,
                'unit' => $item->unit_snapshot,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'gross_amount' => $item->gross_amount,
                'item_discount_amount' => $item->item_discount_amount,
                'sale_discount_allocation' => $item->sale_discount_allocation,
                'net_amount' => $item->net_amount,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->subtotal,
            ])->values()->all(),
            'totals' => [
                'subtotal' => $sale->subtotal,
                'item_discount_total' => $sale->item_discount_total,
                'sale_discount_type' => $sale->sale_discount_type,
                'sale_discount_input' => $sale->sale_discount_input,
                'sale_discount_amount' => $sale->sale_discount_amount,
                'net_total' => $sale->net_total,
                'tax_rate_percent' => $sale->tax_rate_percent_snapshot !== null
                    ? (float) $sale->tax_rate_percent_snapshot
                    : null,
                'tax_total' => $sale->tax_amount,
                'grand_total' => $sale->grand_total,
            ],
            'payment' => $payment ? [
                'method' => $payment->method,
                'amount_due' => $payment->amount_due,
                'amount_paid' => $payment->amount_paid,
                'change_amount' => $payment->change_amount,
                'reference_number' => $payment->reference_number,
            ] : null,
            'receipt_footer' => $sale->store_snapshot['receipt_footer'] ?? null,
        ];
    }
}
