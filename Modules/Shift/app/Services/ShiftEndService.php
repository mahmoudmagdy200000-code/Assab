<?php

namespace Modules\Shift\Services;

use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Enums\ShiftStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ShiftEndService
{
    public function __construct(
        private HandoverService $handoverService,
        private VarianceCalculationService $varianceService
    ) {}

    public function endShiftOnly(CashierShift $shift, array $data): CashierShift
    {
        DB::beginTransaction();
        try {
            // Calculate VAT and Net Sales
            $totalSales = $data['total_sales'];
            $vatAmount = $totalSales * 0.15;
            $netSales = $totalSales - $vatAmount;

            // Handle POS Receipt Upload
            $posReceiptPath = null;
            if (isset($data['pos_receipt'])) {
                $posReceiptPath = $this->uploadPOSReceipt($data['pos_receipt'], $shift->id);
            }

            // Update Shift
            $shift->update([
                'status' => ShiftStatus::COMPLETED,
                'total_sales' => $totalSales,
                'net_sales' => $netSales,
                'vat_amount' => $vatAmount,
                'cash_collected' => $data['cash_collected'] ?? 0,
                'card_payments' => $data['card_payments'] ?? 0,
                'pos_receipt' => $posReceiptPath,
                'actual_end_time' => now(),
            ]);

            // Save Sales Breakdown (Aggregators)
            if (!empty($data['aggregators'])) {
                $this->saveSalesBreakdown($shift, $data['aggregators']);
            }

            // Record History
            $shift->recordHistory('ended_without_handover', [
                'status' => ShiftStatus::IN_PROGRESS->value,
            ], [
                'status' => ShiftStatus::COMPLETED->value,
                'total_sales' => $totalSales,
                'net_sales' => $netSales,
                'vat_amount' => $vatAmount,
            ]);

            DB::commit();
            return $shift->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function endShiftWithHandover(CashierShift $shift, array $data): CashierShift
    {
        DB::beginTransaction();
        try {
            // First, end the shift
            $shift = $this->endShiftOnly($shift, $data);

            // تحديد نوع الـ handover: للكاشير التالي أو للبرانش مانجر
            $handoverToType = $data['handover_to_type'] ?? 'cashier';
            $handoverToId = $data['handover_to_id'] ?? null;

            // إذا لم يتم تمرير handover_to_id، ابحث عنه بناءً على النوع
            if (!$handoverToId) {
                if ($handoverToType === 'branch_manager') {
                    // Check if branch_manager_id is provided directly
                    if (!empty($data['branch_manager_id'])) {
                        $handoverToId = $data['branch_manager_id'];
                    } else {
                        // إذا كان handover للبرانش مانجر، احصل على branch_manager_id من البرانش
                        $branchManager = \Modules\BranchManagers\Models\BranchManager::where('branch_id', $shift->shift->branch_id)
                            ->where('is_active', true)
                            ->first();
                        $handoverToId = $branchManager?->id;
                    }
                } else {
                    // handover للكاشير التالي
                    $handoverToId = $data['next_cashier_id'] ?? null;
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

            $this->handoverService->recordHandover($shift, $handoverData);

            // Check for variance
            if ($shift->hasVariance() && !empty($data['variance'])) {
                $this->varianceService->recordVariance($shift, $data['variance']);
            }

            DB::commit();
            return $shift->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function uploadPOSReceipt($file, string $shiftId): string
    {
        $filename = 'shift_' . $shiftId . '_' . time() . '.' . $file->getClientOriginalExtension();
        return $file->storeAs('receipts', $filename, 'public');
    }

    private function saveSalesBreakdown(CashierShift $shift, array $aggregators): void
    {
        foreach ($aggregators as $aggregator) {
            ShiftSalesBreakdown::create([
                'cashier_shift_id' => $shift->id,
                'aggregator_id' => $aggregator['aggregator_id'],
                'amount' => $aggregator['amount'],
                'notes' => $aggregator['notes'] ?? null,
            ]);
        }
    }

    public function calculateNetSales(float $totalSales): array
    {
        $vatAmount = $totalSales * 0.15;
        $netSales = $totalSales - $vatAmount;

        return [
            'total_sales' => $totalSales,
            'net_sales' => round($netSales, 2),
            'vat_amount' => round($vatAmount, 2),
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
