<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Models\CashierShiftHandover;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShiftCashAvailabilityService
{
    public const MAX_MONETARY_HALALAS = 999999999999;

    public static function minor(string $amount): int
    {
        if (! preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', $amount, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Invalid monetary value.']);
        }
        $whole = ltrim($matches[1], '0') ?: '0';
        if (strlen($whole) > 10 || (strlen($whole) === 10 && strcmp($whole, '9999999999') > 0)) {
            throw ValidationException::withMessages(['amount' => 'Monetary column limit exceeded.']);
        }

        return (int) $whole * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public static function add(int $total, int $delta): int
    {
        if (($delta > 0 && $total > PHP_INT_MAX - $delta) || ($delta < 0 && $total < PHP_INT_MIN - $delta)) {
            throw new ConflictHttpException('MONETARY_LIMIT_EXCEEDED');
        }

        return $total + $delta;
    }

    public function lockCashierOwners(array $ids): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Cash owner locks require a transaction.');
        }
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            // MEM-03/04/06: an archived (soft-deleted) employee still owns cash and open
            // operations; the owner lock must find his row or his pending handover can
            // never be received, rejected or settled by the authorised person.
            Cashier::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
        }
    }

    /** Current physical observations reconcile sales cash; later movements and reservations remain additive. */
    public function availableCashierMinor(string $cashierId, ?string $excludedReservation = null): int
    {
        $rows = CashierCustodyTransaction::where('cashier_id', $cashierId)
            ->where(fn ($query) => $query->whereIn('transaction_type', ['Total Sales', 'Handover Received', 'Handover Sent'])
                ->orWhere('category', 'handover_settlement')
                ->orWhere(fn ($out) => $out->where('is_cash_in', false)->where('transaction_type', '!=', 'Variance')->whereNull('posting_id')))
            ->orderBy('id')->lockForUpdate()->get();
        $cash = 0;
        foreach ($rows as $row) {
            $cash = self::add($cash, ($row->is_cash_in ? 1 : -1) * self::minor((string) $row->amount));
        }
        $reports = DB::table('shift_report_aggregates as report')
            ->join('cashier_shifts as source', 'source.id', '=', 'report.source_id')
            ->where('report.source_type', 'cashier_shift')->where('source.cashier_id', $cashierId)
            ->orderBy('report.source_id')->lockForUpdate()->get(['report.*']);
        foreach ($reports as $report) {
            if ($report->fresh_count_required) {
                throw new ConflictHttpException('PHYSICAL_RECOUNT_REQUIRED');
            }
            $count = app(ShiftCashCountService::class)->currentFor((string) $report->source_id);
            if (! $count) {
                continue;
            }
            $declared = 0;
            foreach ($rows as $row) {
                if ($row->transaction_type === 'Total Sales' && (string) $row->related_shift_id === (string) $report->source_id) {
                    $declared = self::add($declared, ($row->is_cash_in ? 1 : -1) * self::minor((string) $row->amount));
                }
            }
            // Opening receipts are already cash movements; pending incoming is still another owner's cash.
            // Use only the current count, including a carried observation, once for each source report.
            $ownObserved = self::add(self::add((int) $count->counted_halalas, -(int) $count->pending_incoming_counted_halalas), -(int) $count->confirmed_opening_halalas);
            $cash = self::add($cash, self::add($ownObserved, -$declared));
        }
        $requests = CashierShiftHandover::whereHas('cashierShift', fn ($query) => $query->where('cashier_id', $cashierId))
            ->whereIn('status', ['pending', 'rejected', 'rejected_final'])
            ->whereNull('cancelled_at')->whereNull('superseded_at')
            ->when($excludedReservation, fn ($query) => $query->where('id', '!=', $excludedReservation))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($requests as $request) {
            $cash = self::add($cash, -self::minor((string) $request->handover_amount));
        }

        return max(0, $cash);
    }

    public function assertCashierReservation(string $cashierId, string $amount, ?string $excludedReservation = null): void
    {
        if (self::minor($amount) > $this->availableCashierMinor($cashierId, $excludedReservation)) {
            throw new ConflictHttpException('INSUFFICIENT_CASHIER_CUSTODY_CASH');
        }
    }
}
