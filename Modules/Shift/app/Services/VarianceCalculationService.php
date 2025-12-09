<?php

namespace Modules\Shift\Services;

use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftVarianceDetail;
use Modules\Shift\Models\ShiftVarianceAlert;
use Modules\Shift\Enums\VarianceType;
use Modules\Shift\Enums\ResponsibilityType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VarianceCalculationService
{
    public function __construct(
        private ShiftNotificationService $notificationService
    ) {}

    public function recordVariance(CashierShift $shift, array $varianceData): void
    {
        DB::beginTransaction();
        try {
            // Delete existing variance details to prevent duplicates
            ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->delete();

            $varianceAmount = abs($shift->calculateVariance());
            $varianceType = $shift->calculateVariance() > 0
                ? VarianceType::OVER
                : VarianceType::SHORT;

            // Update shift variance
            $shift->update([
                'variance' => $shift->calculateVariance(),
            ]);

            // Handle different responsibility types
            switch ($varianceData['responsibility_type']) {
                case ResponsibilityType::I_WAS_RESPONSIBLE->value:
                case 'self': // ✅ Support string values too
                    $this->recordSingleResponsibility($shift, $varianceAmount, $varianceType);
                    break;

                case ResponsibilityType::ME_AND_OTHER_FACTORS->value:
                case 'self_and_others': // ✅ Support string values too
                    $this->recordSharedResponsibility($shift, $varianceAmount, $varianceType, $varianceData);
                    break;

                case ResponsibilityType::OTHER_FACTORS->value:
                case 'other_factors': // ✅ Support string values too
                    $this->recordExternalFactors($shift, $varianceAmount, $varianceType, $varianceData);
                    break;

                case ResponsibilityType::MIXED_FACTORS->value:
                case 'mixed': // ✅ Support string values too
                    $this->recordMixedFactors($shift, $varianceAmount, $varianceType, $varianceData);
                    break;

                default:
                    Log::warning('Unknown responsibility type', [
                        'shift_id' => $shift->id,
                        'responsibility_type' => $varianceData['responsibility_type']
                    ]);
                    break;
            }

            // Create variance alert if threshold exceeded
            $this->checkVarianceThreshold($shift, $varianceAmount, $varianceType);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Variance recording failed', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
                'variance_data' => $varianceData
            ]);
            throw $e;
        }
    }

    private function recordSingleResponsibility(
        CashierShift $shift,
        float $amount,
        VarianceType $type
    ): void {
        ShiftVarianceDetail::create([
            'cashier_shift_id' => $shift->id,
            'variance_amount' => $amount,
            'variance_type' => $type,
            'responsibility_type' => ResponsibilityType::I_WAS_RESPONSIBLE,
            'responsible_cashier_id' => $shift->cashier_id,
            'assigned_amount' => $amount,
            'reason' => 'Cashier accepted full responsibility',
            'supporting_files' => null,
        ]);
    }

    private function recordSharedResponsibility(
        CashierShift $shift,
        float $amount,
        VarianceType $type,
        array $data
    ): void {
        // Record for current cashier
        $currentCashierAmount = $data['current_cashier_amount'] ?? 0;

        ShiftVarianceDetail::create([
            'cashier_shift_id' => $shift->id,
            'variance_amount' => $amount,
            'variance_type' => $type,
            'responsibility_type' => ResponsibilityType::ME_AND_OTHER_FACTORS,
            'responsible_cashier_id' => $shift->cashier_id,
            'assigned_amount' => $currentCashierAmount,
            'reason' => $data['notes'] ?? 'Shared responsibility',
            'supporting_files' => null,
        ]);

        // ✅ Support both 'other_cashiers' (from controller) and 'cashiers' (legacy)
        $otherCashiers = $data['other_cashiers'] ?? $data['cashiers'] ?? [];

        // Record for other cashiers
        if (!empty($otherCashiers)) {
            foreach ($otherCashiers as $otherCashier) {
                ShiftVarianceDetail::create([
                    'cashier_shift_id' => $shift->id,
                    'variance_amount' => $amount,
                    'variance_type' => $type,
                    'responsibility_type' => ResponsibilityType::ME_AND_OTHER_FACTORS,
                    'responsible_cashier_id' => $otherCashier['cashier_id'],
                    'assigned_amount' => $otherCashier['amount'],
                    'reason' => $otherCashier['notes'] ?? 'Shared responsibility',
                    'supporting_files' => null,
                ]);
            }
        }
    }

    private function recordExternalFactors(
        CashierShift $shift,
        float $amount,
        VarianceType $type,
        array $data
    ): void {
        $supportingFiles = null;
        if (!empty($data['supporting_files'])) {
            $supportingFiles = $this->uploadSupportingFiles($data['supporting_files'], $shift->id);
        }

        ShiftVarianceDetail::create([
            'cashier_shift_id' => $shift->id,
            'variance_amount' => $amount,
            'variance_type' => $type,
            'responsibility_type' => ResponsibilityType::OTHER_FACTORS,
            'responsible_cashier_id' => null,
            'assigned_amount' => $amount,
            'reason' => $data['reason'] ?? 'External factors',
            'supporting_files' => $supportingFiles,
        ]);
    }

    private function recordMixedFactors(
        CashierShift $shift,
        float $amount,
        VarianceType $type,
        array $data
    ): void {
        $supportingFiles = null;
        if (!empty($data['supporting_files'])) {
            $supportingFiles = $this->uploadSupportingFiles($data['supporting_files'], $shift->id);
        }

        // ✅ Support both 'other_cashiers' (from controller) and 'cashiers' (legacy)
        $cashiers = $data['cashiers'] ?? $data['other_cashiers'] ?? [];

        Log::info('Recording mixed factors variance', [
            'shift_id' => $shift->id,
            'cashiers_count' => count($cashiers),
            'has_other_cashiers' => isset($data['other_cashiers']),
            'has_cashiers' => isset($data['cashiers'])
        ]);

        // Record cashier responsibilities
        if (!empty($cashiers)) {
            foreach ($cashiers as $cashier) {
                ShiftVarianceDetail::create([
                    'cashier_shift_id' => $shift->id,
                    'variance_amount' => $amount,
                    'variance_type' => $type,
                    'responsibility_type' => ResponsibilityType::MIXED_FACTORS,
                    'responsible_cashier_id' => $cashier['cashier_id'],
                    'assigned_amount' => $cashier['amount'],
                    'reason' => $cashier['notes'] ?? 'Mixed factors',
                    'supporting_files' => null,
                ]);
            }
        }

        // Record external factors
        $totalCashierAmount = collect($cashiers)->sum('amount');
        $externalAmount = $amount - $totalCashierAmount;

        if ($externalAmount > 0) {
            ShiftVarianceDetail::create([
                'cashier_shift_id' => $shift->id,
                'variance_amount' => $amount,
                'variance_type' => $type,
                'responsibility_type' => ResponsibilityType::MIXED_FACTORS,
                'responsible_cashier_id' => null,
                'assigned_amount' => $externalAmount,
                'reason' => $data['external_reason'] ?? $data['reason'] ?? 'External factors',
                'supporting_files' => $supportingFiles,
            ]);
        }
    }

    private function checkVarianceThreshold(
        CashierShift $shift,
        float $amount,
        VarianceType $type
    ): void {
        $threshold = 100; // SAR - configurable
        $percentageThreshold = 5; // % - configurable

        $percentage = ($amount / max($shift->total_sales, 1)) * 100;

        if ($amount >= $threshold || $percentage >= $percentageThreshold) {
            // Determine alert type based on variance amount/percentage
            $alertType = \Modules\Shift\Enums\AlertType::fromVariance($amount, $percentage);

            ShiftVarianceAlert::create([
                'cashier_shift_id' => $shift->id,
                'variance_amount' => $amount,
                'variance_percentage' => $percentage,
                'alert_type' => $alertType,
                'is_acknowledged' => false,
                'is_acknowledged_by' => null,
                'acknowledged_at' => null,
                'notes' => "Variance exceeded threshold: {$amount} SAR ({$percentage}%) — Type: {$alertType->label()}",
            ]);

            // Send notification to manager
            // $this->notificationService->notifyVarianceAlert($shift, $amount, $percentage);
        }
    }

    private function uploadSupportingFiles(array $files, string $shiftId): string
    {
        $uploadedFiles = [];

        foreach ($files as $file) {
            $filename = 'variance_' . $shiftId . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('variance/supporting-files', $filename, 'public');
            $uploadedFiles[] = $path;
        }

        return json_encode($uploadedFiles);
    }

    public function getVarianceDetails(CashierShift $shift): array
    {
        $details = ShiftVarianceDetail::where('cashier_shift_id', $shift->id)
            ->with('responsibleCashier')
            ->get();

        return [
            'total_variance' => $shift->variance,
            'variance_type' => $shift->variance > 0 ? 'Over' : 'Short',
            'details' => $details->map(function ($detail) {
                return [
                    'responsibility_type' => $detail->responsibility_type->label(),
                    'cashier_name' => $detail->responsibleCashier?->name ?? 'External Factors',
                    'assigned_amount' => $detail->assigned_amount,
                    'reason' => $detail->reason,
                    'supporting_files' => $detail->supporting_files
                        ? json_decode($detail->supporting_files)
                        : [],
                ];
            }),
        ];
    }
}
