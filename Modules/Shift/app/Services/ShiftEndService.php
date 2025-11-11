<?php

namespace Modules\Shift\Services;

use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftSalesBreakdown;
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
                'total_sales' => $totalSales,
                'net_sales' => $netSales,
                'vat_amount' => $vatAmount,
                'cash_collected' => $data['cash_collected'],
                'card_payments' => $data['card_payments'],
                'pos_receipt' => $posReceiptPath,
                'actual_end_time' => now(),
            ]);

            // Save Sales Breakdown (Aggregators)
            if (!empty($data['aggregators'])) {
                $this->saveSalesBreakdown($shift, $data['aggregators']);
            }

            // Record History
            $shift->recordHistory('ended_without_handover', null, [
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

            // Then, record handover
            $handoverData = [
                'next_cashier_id' => $data['next_cashier_id'],
                'handover_amount' => $data['handover_amount'],
                'handover_notes' => $data['handover_notes'] ?? null,
            ];

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
