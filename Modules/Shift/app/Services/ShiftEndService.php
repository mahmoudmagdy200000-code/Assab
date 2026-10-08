<?php

namespace Modules\Shift\Services;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftSalesBreakdown;

class ShiftEndService
{
    public function __construct(
        private HandoverService $handoverService,
        private VarianceCalculationService $varianceService,
        private ShiftReportRevisionService $revisions
    ) {}

    public function endShiftOnly(CashierShift $shift, array $data, Model $actor): CashierShift
    {
        $data = $this->stageExternalFiles($shift, $data);

        DB::beginTransaction();
        try {
            $shift = CashierShift::withoutEagerLoads()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($shift->status !== ShiftStatus::IN_PROGRESS) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('SHIFT_NO_LONGER_OPEN');
            }

            // Calculate VAT and Net Sales
            $totalSales = $data['total_sales'];
            $salesCalculation = ShiftFinancialCalculator::calculateVatInclusiveSales($totalSales);
            $vatAmount = $salesCalculation['vat'];
            $netSales = $salesCalculation['net'];

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
            $cashAmount = (string) ($shift->cash_collected ?? '0.00');
            if (Cashier::whereKey($shift->cashier_id)->exists() && (float) $cashAmount > 0) {
                CashierCustodyTransaction::create([
                    'cashier_id' => $shift->cashier_id,
                    'transaction_type' => 'Total Sales',
                    'amount' => $cashAmount,
                    'is_cash_in' => true,
                    'related_shift_id' => $shift->id,
                    'transaction_date' => now(),
                ]);
            }

            if (! empty($data['variance']) && $shift->hasVariance()) {
                $this->varianceService->recordVariance($shift, $data['variance']);
            }

            DB::commit();

            return $shift->fresh(['cashier', 'shift', 'nextCashier']);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function endShiftWithHandover(CashierShift $shift, array $data, Model $actor): CashierShift
    {
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
