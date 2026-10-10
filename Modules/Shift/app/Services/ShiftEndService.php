<?php

namespace Modules\Shift\Services;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Liability\ShiftLiabilityService;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Models\ShiftSalesBreakdown;

class ShiftEndService
{
    public function __construct(
        private HandoverService $handoverService,
        private VarianceCalculationService $varianceService,
        private ShiftReportRevisionService $revisions,
        private ShiftCashCountService $cashCounts,
        private ShiftLiabilityService $liability,
        private CountedReassignmentGuard $reassignmentGuard,
        private ShiftReportRevisionSnapshotService $snapshots
    ) {}

    public function endShiftOnly(CashierShift $shift, array $data, Model $actor): CashierShift
    {
        $this->reassignmentGuard->assertCanContinue($shift, $actor);
        $countedHalalas = $this->parseCountedCash($data);
        $data = $this->stageExternalFiles($shift, $data);

        DB::beginTransaction();
        try {
            $shift = CashierShift::withoutEagerLoads()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $this->reassignmentGuard->assertCanContinue($shift, $actor);
            if ($shift->status !== ShiftStatus::IN_PROGRESS) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('SHIFT_NO_LONGER_OPEN');
            }

            // Calculate VAT and Net Sales
            $totalSales = $data['total_sales'];
            $salesCalculation = ShiftFinancialCalculator::calculateVatInclusiveSales($totalSales);
            $vatAmount = $salesCalculation['vat'];
            $netSales = $salesCalculation['net'];

            $previousRevision = $this->revisions->currentCashierRevision($shift);
            if ($previousRevision) {
                $this->snapshots->preserveVarianceReviews($shift, $previousRevision);
                $this->snapshots->createSnapshotIfMissing($previousRevision, $shift);
            }

            // Update Shift
            $shift->update([
                'status' => ShiftStatus::COMPLETED,
                'total_sales' => $totalSales,
                'net_sales' => $netSales,
                'vat_amount' => $vatAmount,
                'cash_collected' => $data['cash_collected'] ?? 0,
                'card_payments' => $data['card_payments'] ?? 0,
                'pos_receipt' => $data['pos_receipt'] ?? null,
                'actual_end_time' => now(),
            ]);

            // Replace the mutable channel projection in the same report transaction.
            $this->saveSalesBreakdown($shift, $data['aggregators'] ?? []);

            $revision = $this->revisions->recordCashierRevision(
                $shift,
                $actor instanceof Cashier ? 'cashier' : 'branch_manager',
                (string) $actor->getKey()
            );

            // S1-10: the independent physical count, the server calculation and (for a shortage) the
            // complete in-branch allocation commit with the report and its revision, or not at all.
            $this->recordReportCount($shift, $revision, $countedHalalas, $data, $actor);

            // Record History
            $shift->recordHistory('ended_without_handover', [
                'status' => ShiftStatus::IN_PROGRESS->value,
            ], [
                'status' => ShiftStatus::COMPLETED->value,
                'total_sales' => $totalSales,
                'net_sales' => $netSales,
                'vat_amount' => $vatAmount,
                'report_revision_id' => $revision->id,
                'report_revision' => $revision->revision_number,
            ]);

            // This report declaration is not a transfer receipt, but it is part of
            // the report's financial effects and therefore shares the transaction.
            // Under BR-17 / S1-11, delta-only is recorded on re-ending / correction to avoid double-counting.
            $targetCashCollected = round((float) ($shift->cash_collected ?? '0.00'), 2);
            if (Cashier::whereKey($shift->cashier_id)->exists()) {
                $existingPosted = (float) (CashierCustodyTransaction::query()
                    ->where('related_shift_id', $shift->id)
                    ->where('cashier_id', $shift->cashier_id)
                    ->where('transaction_type', 'Total Sales')
                    ->selectRaw('SUM(CASE WHEN is_cash_in = 1 THEN amount ELSE -amount END) as net_sales')
                    ->value('net_sales') ?? 0.0);

                $salesDelta = round($targetCashCollected - $existingPosted, 2);

                if ($salesDelta > 0.0) {
                    CashierCustodyTransaction::create([
                        'cashier_id' => $shift->cashier_id,
                        'transaction_type' => 'Total Sales',
                        'amount' => (string) $salesDelta,
                        'is_cash_in' => true,
                        'related_shift_id' => $shift->id,
                        'transaction_date' => now(),
                    ]);
                } elseif ($salesDelta < 0.0) {
                    CashierCustodyTransaction::create([
                        'cashier_id' => $shift->cashier_id,
                        'transaction_type' => 'Total Sales',
                        'amount' => (string) abs($salesDelta),
                        'is_cash_in' => false,
                        'related_shift_id' => $shift->id,
                        'transaction_date' => now(),
                    ]);
                }
            }

            if (! empty($data['variance']) && $shift->hasVariance()) {
                $this->varianceService->recordVariance($shift, $data['variance']);
            } else {
                \Modules\Shift\Models\ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->delete();
            }

            CashierShiftHandover::query()
                ->active()
                ->where('cashier_shift_id', $shift->id)
                ->where('status', 'pending')
                ->update(['report_revision_id' => $revision->id]);

            $this->snapshots->createSnapshotIfMissing($revision, $shift);

            DB::commit();

            return $shift->fresh(['cashier', 'shift', 'nextCashier']);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function endShiftWithHandover(CashierShift $shift, array $data, Model $actor): CashierShift
    {
        $this->reassignmentGuard->assertCanContinue($shift, $actor);
        // Stage uploads before the outer report + handover transaction begins.
        if (($data['pos_receipt'] ?? null) instanceof UploadedFile) {
            $data['pos_receipt'] = $this->uploadPOSReceipt($data['pos_receipt'], $shift->id);
        }
        if (! empty($data['variance']['supporting_files']) && is_array($data['variance']['supporting_files'])) {
            $data['variance']['supporting_files'] = $this->varianceService->stageSupportingFiles($data['variance']['supporting_files'], $shift->id);
            $data['variance_files'] = $this->handoverService->stageVarianceFiles($data['variance']['supporting_files'], $shift->id);
        }

        DB::beginTransaction();
        try {
            // First, end the shift
            $shift = $this->endShiftOnly($shift, $data, $actor);

            // تحديد نوع الـ handover: للكاشير التالي أو للبرانش مانجر
            $handoverToType = $data['handover_to_type'] ?? 'cashier';
            $handoverToId = $data['handover_to_id'] ?? null;

            // إذا لم يتم تمرير handover_to_id، ابحث عنه بناءً على النوع
            if (! $handoverToId) {
                if ($handoverToType === 'branch_manager') {
                    $manager = app(\Modules\BranchManagers\Services\BranchManagerService::class)
                        ->assignedActiveManager($shift->shift->branch_id);
                    $handoverToId = $manager->id;
                } else {
                    // handover للكاشير التالي
                    $handoverToId = $data['next_cashier_id'] ?? null;
                }
            }

            if ($handoverToType === 'branch_manager') {
                app(\Modules\BranchManagers\Services\BranchManagerService::class)
                    ->assertAssignedActiveManager($shift->shift->branch_id, (string) $handoverToId);
                if (! empty($data['branch_manager_id']) && (string) $data['branch_manager_id'] !== (string) $handoverToId) {
                    throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('ONLY_ASSIGNED_BRANCH_MANAGER_RECIPIENT');
                }
            }

            // Then, record handover
            $handoverData = [
                'handover_to_type' => $handoverToType,
                'handover_to_id' => $handoverToId,
                'next_cashier_id' => $handoverToType === 'cashier' ? $handoverToId : null,
                'handover_amount' => $data['handover_amount'],
                'handover_notes' => $data['handover_notes'] ?? null,
                'variance_reason' => $data['variance']['reason'] ?? null,
                'variance_files' => $data['variance']['supporting_files'] ?? null,
            ];

            \Illuminate\Support\Facades\Log::info('ShiftEndService: Recording handover', [
                'handover_data' => $handoverData,
                'shift_id' => $shift->id,
            ]);

            $this->handoverService->recordHandover($shift, $handoverData, $actor);

            DB::commit();

            return $shift->fresh();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /** counted_cash is an independent required input; absent is never an implied 0 (an explicit 0 is a count of 0). */
    public function parseCountedCash(array $data): int
    {
        if (! array_key_exists('counted_cash', $data) || $data['counted_cash'] === null || $data['counted_cash'] === '') {
            throw ValidationException::withMessages(['counted_cash' => 'The counted cash is required.']);
        }

        try {
            return ShiftFinancialCalculator::sarToHalalas($data['counted_cash']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['counted_cash' => 'The counted cash must be a non-negative SAR amount with at most two decimals.']);
        }
    }

    /**
     * The shared report-count rule: persist the calculated count for `$revision` and, for a shortage,
     * the complete allocation. Must run inside the caller's report transaction.
     */
    public function recordReportCount(CashierShift $shift, ShiftReportRevision $revision, int $countedHalalas, array $data, Model $actor): void
    {
        $apps = 0;
        foreach (ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->pluck('amount') as $amount) {
            $apps += ShiftFinancialCalculator::storedSarToHalalas($amount);
        }

        $count = $this->cashCounts->record(
            $shift,
            $revision,
            ShiftFinancialCalculator::storedSarToHalalas($shift->total_sales),
            ShiftFinancialCalculator::storedSarToHalalas($shift->card_payments),
            $apps,
            $countedHalalas,
        );

        $allocations = $data['shortage_allocations'] ?? [];
        if ($count->variance_halalas >= 0) {
            // Balanced or surplus: the branch owns it; no employee liability is created.
            if ($allocations !== []) {
                throw ValidationException::withMessages(['shortage_allocations' => 'Allocations are only accepted for a shortage.']);
            }

            return;
        }

        $shares = [];
        foreach ($allocations as $index => $allocation) {
            try {
                $amount = ShiftFinancialCalculator::sarToHalalas($allocation['amount'] ?? '');
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(["shortage_allocations.$index.amount" => 'Invalid SAR amount.']);
            }
            $shares[] = [
                'type' => (string) ($allocation['responsible_type'] ?? ''),
                'id' => (string) ($allocation['responsible_id'] ?? ''),
                'amount' => $amount,
            ];
        }

        // The cashier's own submission is their confirmation; a manager submitting for the cashier
        // needs a reason and leaves the cashier confirmation pending. No ledger entry (S1-11).
        // The shift row is locked by the caller; supersede any earlier version (re-end after a rejection).
        $latestVersion = (int) ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)->max('version');
        try {
            $this->liability->allocate(
                $shift->id,
                $actor,
                $shares,
                $latestVersion,
                $actor instanceof Cashier,
                isset($data['allocation_reason']) ? (string) $data['allocation_reason'] : null,
            );
        } catch (ValidationException $e) {
            // The end-of-shift request names this field `shortage_allocations`; keep the error on that key.
            $errors = $e->errors();
            if (isset($errors['allocations'])) {
                $errors['shortage_allocations'] = $errors['allocations'];
                unset($errors['allocations']);
                throw ValidationException::withMessages($errors);
            }
            throw $e;
        }
    }

    private function uploadPOSReceipt($file, string $shiftId): string
    {
        $filename = 'shift_'.$shiftId.'_'.time().'.'.$file->getClientOriginalExtension();

        return $file->storeAs('receipts', $filename, 'public');
    }

    private function stageExternalFiles(CashierShift $shift, array $data): array
    {
        if (($data['pos_receipt'] ?? null) instanceof UploadedFile) {
            $data['pos_receipt'] = $this->uploadPOSReceipt($data['pos_receipt'], $shift->id);
        }
        if (! empty($data['variance']['supporting_files']) && is_array($data['variance']['supporting_files'])) {
            $data['variance']['supporting_files'] = $this->varianceService->stageSupportingFiles($data['variance']['supporting_files'], $shift->id);
        }

        return $data;
    }

    private function saveSalesBreakdown(CashierShift $shift, array $aggregators): void
    {
        ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->delete();

        foreach ($aggregators as $aggregator) {
            ShiftSalesBreakdown::create([
                'cashier_shift_id' => $shift->id,
                'aggregator_id' => $aggregator['aggregator_id'],
                'amount' => $aggregator['amount'],
                'notes' => $aggregator['notes'] ?? null,
            ]);
        }
    }

    public function calculateNetSales(string|int|float $totalSales): array
    {
        $salesCalculation = ShiftFinancialCalculator::calculateVatInclusiveSales($totalSales);

        return [
            'total_sales' => $totalSales,
            'net_sales' => (float) $salesCalculation['net'],
            'vat_amount' => (float) $salesCalculation['vat'],
        ];
    }

    public function validatePaymentBreakdown(array $data): bool
    {
        $totalSales = $data['total_sales'];
        $cash = $data['cash_collected'] ?? 0;
        $card = $data['card_payments'] ?? 0;
        $aggregators = collect($data['aggregators'] ?? [])->sum('amount');

        $calculatedTotal = $cash + $card + $aggregators;

        // Allow 0.01 difference for rounding
        return abs($totalSales - $calculatedTotal) <= 0.01;
    }
}
