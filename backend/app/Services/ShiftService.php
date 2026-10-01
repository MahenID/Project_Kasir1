<?php

namespace App\Services;

use App\Exceptions\ShiftException;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShiftService
{
    /**
     * Open a new shift for the user on the specified terminal.
     */
    public function openShift(User $user, int $terminalId, int $startingCash, ?string $notes = null): Shift
    {
        if ($startingCash < 0) {
            throw new ShiftException('Modal awal kas (starting cash) tidak boleh bernilai negatif.', 'INVALID_STARTING_CASH', 422);
        }

        $terminal = Terminal::find($terminalId);
        if (!$terminal || !$terminal->is_active) {
            throw new ShiftException('Terminal kasir tidak valid atau tidak aktif.', 'INVALID_TERMINAL', 422);
        }

        // Check if user already has an active open shift
        $existingUserShift = Shift::where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if ($existingUserShift) {
            throw new ShiftException(
                "Anda masih memiliki shift yang belum ditutup (Shift #{$existingUserShift->shift_number}).",
                'SHIFT_ALREADY_OPEN'
            );
        }

        // Check if terminal is already in use by another open shift
        $existingTerminalShift = Shift::where('terminal_id', $terminalId)
            ->where('status', 'open')
            ->first();

        if ($existingTerminalShift) {
            throw new ShiftException(
                "Terminal '{$terminal->name}' sedang digunakan oleh kasir lain (Shift #{$existingTerminalShift->shift_number}).",
                'TERMINAL_IN_USE'
            );
        }

        $now = Carbon::now('UTC');
        $datePrefix = $now->format('Ymd');
        $countToday = Shift::whereDate('created_at', $now->toDateString())->count() + 1;
        $shiftNumber = sprintf('SHF-%s-%04d', $datePrefix, $countToday);

        return Shift::create([
            'shift_number' => $shiftNumber,
            'user_id' => $user->id,
            'terminal_id' => $terminalId,
            'status' => 'open',
            'opened_at' => $now,
            'starting_cash' => $startingCash,
            'expected_cash' => $startingCash,
        ]);
    }

    /**
     * Calculate current X-Report / Shift Preview figures.
     */
    public function calculateXReport(Shift $shift): array
    {
        // 1. Cash sales applied in this shift
        $cashSalesApplied = (int) Payment::where('shift_id', $shift->id)
            ->where('method', 'cash')
            ->sum('amount_due');

        // 2. Non-cash sales grouped by method
        $nonCashPayments = Payment::where('shift_id', $shift->id)
            ->where('method', '!=', 'cash')
            ->select('method', DB::raw('SUM(amount_due) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('method')
            ->get();

        $nonCashBreakdown = [
            'qris' => 0,
            'edc' => 0,
            'transfer' => 0,
        ];
        $totalNonCashSales = 0;
        foreach ($nonCashPayments as $pay) {
            $nonCashBreakdown[$pay->method] = (int) $pay->total;
            $totalNonCashSales += (int) $pay->total;
        }

        // 3. Cash refunds processed during this shift
        $cashRefunds = (int) Refund::where('handling_shift_id', $shift->id)
            ->where('method', 'cash')
            ->sum('amount');

        $nonCashRefunds = (int) Refund::where('handling_shift_id', $shift->id)
            ->where('method', '!=', 'cash')
            ->sum('amount');

        // 4. Cash In & Cash Out movements
        $cashIn = (int) CashMovement::where('shift_id', $shift->id)
            ->whereIn('type', ['in', 'cash_in'])
            ->sum('amount');

        $cashOut = (int) CashMovement::where('shift_id', $shift->id)
            ->whereIn('type', ['out', 'cash_out'])
            ->sum('amount');

        // 5. Expected Drawer Cash Formula per PRD Section 6.3:
        // Expected cash = starting cash + cash sales applied - cash refunds + cash in - cash out
        $startingCash = (int) $shift->starting_cash;
        $expectedCash = $startingCash + $cashSalesApplied - $cashRefunds + $cashIn - $cashOut;

        $salesCount = Payment::where('shift_id', $shift->id)->count();

        return [
            'shift_id' => $shift->id,
            'shift_number' => $shift->shift_number,
            'user' => [
                'id' => $shift->user->id,
                'name' => $shift->user->name,
            ],
            'terminal' => [
                'id' => $shift->terminal->id,
                'code' => $shift->terminal->code,
                'name' => $shift->terminal->name,
            ],
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'starting_cash' => $startingCash,
            'cash_sales_applied' => $cashSalesApplied,
            'non_cash_sales' => $nonCashBreakdown,
            'total_non_cash_sales' => $totalNonCashSales,
            'cash_refunds' => $cashRefunds,
            'non_cash_refunds' => $nonCashRefunds,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => $expectedCash,
            'total_sales_count' => $salesCount,
        ];
    }

    /**
     * Record cash in or cash out on an open shift.
     */
    public function recordCashMovement(
        Shift $shift,
        User $actor,
        string $type,
        int $amount,
        string $reason,
        ?string $operationKey = null
    ): CashMovement {
        if ($shift->status !== 'open') {
            throw new ShiftException('Pergerakan kas hanya dapat dicatat pada shift yang sedang aktif/terbuka.', 'SHIFT_NOT_OPEN');
        }

        // Normalize type to 'in' or 'out'
        $normalizedType = match ($type) {
            'in', 'cash_in' => 'in',
            'out', 'cash_out' => 'out',
            default => throw new ShiftException("Jenis pergerakan kas tidak valid: {$type}.", 'INVALID_MOVEMENT_TYPE', 422),
        };

        if ($amount <= 0) {
            throw new ShiftException('Nominal pergerakan kas harus lebih besar dari 0.', 'INVALID_AMOUNT', 422);
        }

        if ($normalizedType === 'out') {
            $xReport = $this->calculateXReport($shift);
            if ($amount > $xReport['expected_cash']) {
                throw new ShiftException(
                    "Nominal kas keluar (Rp " . number_format($amount, 0, ',', '.') . ") melebihi estimasi uang fisik di laci (Rp " . number_format($xReport['expected_cash'], 0, ',', '.') . ").",
                    'CASH_OUT_EXCEEDS_DRAWER',
                    422
                );
            }
        }

        $opKey = !empty($operationKey) ? $operationKey : (string) Str::uuid();

        return CashMovement::create([
            'shift_id' => $shift->id,
            'type' => $normalizedType,
            'amount' => $amount,
            'reason' => $reason,
            'user_id' => $actor->id,
            'posted_at' => Carbon::now('UTC'),
            'operation_key' => $opKey,
        ]);
    }

    /**
     * Close a shift and generate an immutable Z-Report.
     */
    public function closeShift(
        Shift $shift,
        User $actor,
        int $actualCash,
        ?string $explanation = null
    ): Shift {
        return DB::transaction(function () use ($shift, $actor, $actualCash, $explanation) {
            // Row-level lock on shift
            $lockedShift = Shift::where('id', $shift->id)->lockForUpdate()->first();

            if ($lockedShift->status !== 'open') {
                throw new ShiftException('Shift ini sudah ditutup sebelumnya.', 'SHIFT_ALREADY_CLOSED');
            }

            if ($actualCash < 0) {
                throw new ShiftException('Hitungan kas fisik aktual tidak boleh bernilai negatif.', 'INVALID_ACTUAL_CASH', 422);
            }

            // Calculate final Z-Report snapshot
            $xReport = $this->calculateXReport($lockedShift);
            $expectedCash = $xReport['expected_cash'];
            $difference = $actualCash - $expectedCash;

            if ($difference !== 0 && empty(trim((string) $explanation))) {
                throw new ShiftException(
                    'Terdapat selisih kas fisik. Anda wajib mencantumkan penjelasan/alasan selisih kas.',
                    'EXPLANATION_REQUIRED',
                    422,
                    ['difference' => $difference]
                );
            }

            $now = Carbon::now('UTC');
            $zReport = array_merge($xReport, [
                'closed_at' => $now->toIso8601String(),
                'closed_by' => [
                    'id' => $actor->id,
                    'name' => $actor->name,
                ],
                'actual_cash' => $actualCash,
                'difference' => $difference,
                'explanation' => $explanation,
            ]);

            $lockedShift->update([
                'status' => 'closed',
                'closed_at' => $now,
                'closed_by' => $actor->id,
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'difference' => $difference,
                'explanation' => $explanation,
                'z_report_snapshot' => $zReport,
            ]);

            return $lockedShift;
        });
    }
}
