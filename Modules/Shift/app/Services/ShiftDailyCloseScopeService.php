<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Service defining authoritative server-derived workday membership and transfer evidence scope.
 */
class ShiftDailyCloseScopeService
{
    /**
     * Resolves scoped cashier shift IDs, handover IDs, and manager cash transfer IDs for a manager workday.
     *
     * @return array{cashier_shift_ids: list<string>, handover_ids: list<string>, manager_transfer_ids: list<string>}
     */
    public function resolve(BranchManagerShift $day, bool $lock = false): array
    {
        $through = $day->shift_date->toDateString();
        $consumedElsewhere = DB::table('shift_liability_daily_locks')
            ->where('branch_manager_shift_id', '!=', $day->id)
            ->whereNull('released_at')->whereNull('superseded_at')->select('cashier_shift_id');

        $cashierShiftQuery = CashierShift::query()
            ->whereNotIn('id', $consumedElsewhere)
            ->whereDate('shift_date', '<=', $through)
            ->where(function ($inputs) use ($through, $day) {
                $inputs->where(function ($sameDay) use ($through) {
                    $sameDay->whereDate('shift_date', $through);
                })->orWhereHas('handover', function ($carried) use ($day, $through) {
                    $carried->where('handover_to_type', 'branch_manager')
                        ->where(function ($historical) use ($day) {
                            $historical->where('handover_to_id', $day->branch_manager_id)
                                ->orWhereHas('receipt', fn ($receipt) => $receipt->where('receiving_branch_manager_shift_id', $day->id));
                        })
                        ->where(fn ($state) => $state->where('status', 'approved')
                            ->orWhere(fn ($presented) => $presented->whereDate('handover_date', $through)
                                ->whereNull('cancelled_at')->whereNull('superseded_at')))
                        ->whereDate('handover_date', '<=', $through)
                        ->whereNull('daily_closed_at');
                });
            })
            ->whereHas('shift', fn ($query) => $query->where('branch_id', $day->branch_id))
            ->where(function ($active) {
                $active->where('status', '!=', 'not_started')
                    ->orWhereHas('notWorkedHistory')
                    ->orWhereHas('handover')
                    ->orWhereIn('id', DB::table('shift_report_aggregates')->where('source_type', 'cashier_shift')->select('source_id'));
            });

        if ($lock) {
            $cashierShiftQuery->lockForUpdate();
        }

        $cashierShiftIds = $cashierShiftQuery->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
        // Keep the existing scope predicate for ordinary canceled/financial rows.
        // Explicit no-work facts are excluded only after integrity/evidence checks.
        $marked = CashierShift::withoutEagerLoads()->whereIn('id', $cashierShiftIds)
            ->whereHas('notWorkedHistory')->orderBy('id');
        if ($lock) {
            $marked->lockForUpdate();
        }
        foreach ($marked->get() as $noWork) {
            app(ShiftNotWorkedGuard::class)->assertExcluded($noWork);
            $cashierShiftIds = array_values(array_diff($cashierShiftIds, [(string) $noWork->id]));
        }
        sort($cashierShiftIds);

        $handoverIds = [];
        if (! empty($cashierShiftIds)) {
            $handoverQuery = CashierShiftHandover::query()
                ->whereIn('cashier_shift_id', $cashierShiftIds)
                // An overnight end belongs to its source workday even when its
                // presentation clock crossed midnight.
                ->where(function ($date) use ($through) {
                    $date->whereDate('handover_date', '<=', $through)
                        ->orWhereHas('cashierShift', fn ($source) => $source->whereDate('shift_date', $through));
                })
                ->where(fn ($active) => $active->whereNull('cancelled_at')->orWhereNotNull('replacement_request_id'))
                ->where(function ($q) use ($day) {
                    $q->where(function ($m) use ($day) {
                        $m->where('handover_to_type', 'branch_manager')
                            ->where('handover_to_id', $day->branch_manager_id);
                    })->orWhere('handover_to_type', 'cashier');
                });

            if ($lock) {
                $handoverQuery->lockForUpdate();
            }

            $handoverIds = $handoverQuery->pluck('id')->map(fn ($id) => (string) $id)->all();
        }

        // Also include unclosed approved carry-overs directed to this manager
        $carryoverQuery = CashierShiftHandover::query()
            ->whereNotIn('cashier_shift_id', $consumedElsewhere)
            ->where('handover_to_type', 'branch_manager')
            ->where(fn ($historical) => $historical->where('handover_to_id', $day->branch_manager_id)
                ->orWhereHas('receipt', fn ($receipt) => $receipt->where('receiving_branch_manager_shift_id', $day->id)))
            ->where('status', 'approved')
            ->whereDate('handover_date', '<=', $through)
            ->whereNull('daily_closed_at')
            ->whereHas('cashierShift', fn ($query) => $query->whereDate('shift_date', '<=', $through))
            ->whereHas('cashierShift.shift', fn ($query) => $query->where('branch_id', $day->branch_id));

        if ($lock) {
            $carryoverQuery->lockForUpdate();
        }

        $carryoverIds = $carryoverQuery->pluck('id')->map(fn ($id) => (string) $id)->all();
        $allHandoverIds = array_values(array_unique(array_merge($handoverIds, $carryoverIds)));
        sort($allHandoverIds);

        $managerTransferQuery = BranchManagerCashTransfer::query()
            ->where('branch_manager_shift_id', $day->id);

        if ($lock) {
            $managerTransferQuery->lockForUpdate();
        }

        $managerTransferIds = $managerTransferQuery->pluck('id')->map(fn ($id) => (string) $id)->all();
        sort($managerTransferIds);

        return [
            'cashier_shift_ids' => array_values(array_unique($cashierShiftIds)),
            'handover_ids' => $allHandoverIds,
            'manager_transfer_ids' => array_values(array_unique($managerTransferIds)),
        ];
    }

    /**
     * Traverses replacement chains to find the leaf confirmed receipt ID.
     * Detects cycles and throws ConflictHttpException('REPLACEMENT_CHAIN_CYCLE_DETECTED').
     */
    public function resolveHandoverReceiptId(CashierShiftHandover $handover): ?string
    {
        return $this->resolveReplacementReceipt($handover, false);
    }

    public function resolveManagerTransferReceiptId(BranchManagerCashTransfer $transfer): ?string
    {
        return $this->resolveReplacementReceipt($transfer, true);
    }

    private function resolveReplacementReceipt(CashierShiftHandover|BranchManagerCashTransfer $source, bool $managerTransfer): ?string
    {
        $class = $managerTransfer ? BranchManagerCashTransfer::class : CashierShiftHandover::class;
        $sourceField = $managerTransfer ? 'branch_manager_shift_id' : 'cashier_shift_id';
        $receiptField = $managerTransfer ? 'branch_manager_cash_transfer_id' : 'cashier_shift_handover_id';
        $visited = [];
        $current = $source;
        while ($current !== null) {
            if (isset($visited[$current->id])) {
                throw new ConflictHttpException('REPLACEMENT_CHAIN_CYCLE_DETECTED');
            }
            $visited[$current->id] = true;
            if ((string) $current->$sourceField !== (string) $source->$sourceField) {
                throw new ConflictHttpException('REPLACEMENT_CHAIN_LINEAGE_MISMATCH');
            }
            if ($current->replacement_request_id !== null) {
                if (isset($visited[$current->replacement_request_id])) {
                    throw new ConflictHttpException('REPLACEMENT_CHAIN_CYCLE_DETECTED');
                }
                $next = $class::whereKey($current->replacement_request_id)
                    ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
                if ($next === null) {
                    throw new ConflictHttpException('REPLACEMENT_CHAIN_LEAF_MISSING');
                }
                if ((string) $next->supersedes_id !== (string) $current->id
                    || (string) $next->$sourceField !== (string) $source->$sourceField
                    || $current->cancelled_at === null || $current->superseded_at === null) {
                    throw new ConflictHttpException('REPLACEMENT_CHAIN_LINEAGE_MISMATCH');
                }
                $current = $next;

                continue;
            }
            $receipt = CashierShiftHandoverReceipt::where($receiptField, $current->id)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
            if ($receipt === null) {
                return null;
            }
            if ($current->cancelled_at !== null || $current->superseded_at !== null
                || ! in_array($current->status, ['approved', 'confirmed'], true)) {
                throw new ConflictHttpException('TRANSFER_RECEIPT_STATE_MISMATCH');
            }
            app(ShiftReceiptProvenanceService::class)->resolve($receipt);

            return (string) $receipt->id;
        }

        return null;
    }
}
