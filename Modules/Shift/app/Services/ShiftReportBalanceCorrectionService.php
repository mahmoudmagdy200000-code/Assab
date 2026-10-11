<?php

namespace Modules\Shift\Services;

use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Models\CashierShift;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShiftReportBalanceCorrectionService
{
    public function reconcileCashCollected(
        CashierShift $shift,
        int $oldCashCollectedHalalas,
        int $newCashCollectedHalalas,
        string $operationId
    ): ?CashierCustodyTransaction {
        $existing = CashierCustodyTransaction::query()
            ->where('related_shift_id', $shift->id)
            ->where('transaction_type', 'Total Sales')
            ->lockForUpdate()
            ->get();

        foreach ($existing as $entry) {
            if ((string) $entry->cashier_id !== (string) $shift->cashier_id) {
                throw new ConflictHttpException('BALANCE_EVIDENCE_UNAVAILABLE');
            }
        }

        $postedHalalas = 0;
        foreach ($existing as $entry) {
            $amountH = (int) round(((float) $entry->amount) * 100);
            $postedHalalas += $entry->is_cash_in ? $amountH : -$amountH;
        }

        $deltaHalalas = $newCashCollectedHalalas - $postedHalalas;

        if ($deltaHalalas > 0) {
            return CashierCustodyTransaction::create([
                'cashier_id' => $shift->cashier_id,
                'transaction_type' => 'Total Sales',
                'amount' => number_format($deltaHalalas / 100, 2, '.', ''),
                'is_cash_in' => true,
                'related_shift_id' => $shift->id,
                'transaction_date' => now(),
            ]);
        }

        if ($deltaHalalas < 0) {
            return CashierCustodyTransaction::create([
                'cashier_id' => $shift->cashier_id,
                'transaction_type' => 'Total Sales',
                'amount' => number_format(abs($deltaHalalas) / 100, 2, '.', ''),
                'is_cash_in' => false,
                'related_shift_id' => $shift->id,
                'transaction_date' => now(),
            ]);
        }

        return null;
    }
}
