<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Shift\Enums\ResponsibilityType;
use Modules\Shift\Enums\VarianceType;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftVarianceAlert;
use Modules\Shift\Models\ShiftVarianceDetail;

class VarianceCalculationService
{
    public function __construct(
        private ShiftNotificationService $notificationService
    ) {}

    public function recordVariance(CashierShift $shift, array $varianceData): void
    {
        DB::beginTransaction();
        try {
            $varianceAmount = abs($shift->calculateVariance());
            $varianceType = $shift->calculateVariance() > 0
                ? VarianceType::OVER
                : VarianceType::SHORT;

            // Update shift variance
            $shift->update([
                'variance' => $shift->calculateVariance(),
            ]);

            // Purge old projection rows so removed assignees or responsibility types are excluded from current sum
            ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->delete();

            // Handle different responsibility types
            switch ($varianceData['responsibility_type']) {
                case ResponsibilityType::I_WAS_RESPONSIBLE->value:
                case 'self': // ✅ Support string values too
                    $this->recordSingleResponsibility($shift, $varianceAmount, $varianceType, $varianceData);
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
                        'responsibility_type' => $varianceData['responsibility_type'],
                    ]);
                    break;
            }

            // Create variance alert if threshold exceeded
            $this->checkVarianceThreshold($shift, $varianceAmount, $varianceType);

            DB::commit();

            // Custody ledger entries are created only after responsibility is approved (VarianceRecorded from controllers / handover approval).
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Variance recording failed', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
                'variance_data' => $varianceData,
            ]);
            throw $e;
        }
    }

    private function recordSingleResponsibility(
        CashierShift $shift,
        float $amount,
        VarianceType $type,
        array $data = []
    ): void {
        $reason = $data['reason'] ?? $data['notes'] ?? null;
        ShiftVarianceDetail::create([
            'cashier_shift_id' => $shift->id,
            'responsibility_type' => ResponsibilityType::I_WAS_RESPONSIBLE,
            'responsible_cashier_id' => $shift->cashier_id,
            'variance_amount' => $amount,
            'variance_type' => $type,
            'assigned_amount' => $amount,
            'reason' => $reason,
            'supporting_files' => null,
        ]);
    }

    private function recordSharedResponsibility(
        CashierShift $shift,
        float $amount,
        VarianceType $type,
        array $data
    ): void {
        // Other cashiers' amounts (user enters these)
        $otherCashiers = $data['other_cashiers'] ?? $data['cashiers'] ?? [];
        $otherCashiersSum = collect($otherCashiers)->sum('amount');

        // Current cashier's portion = total variance minus what was assigned to others
        // (user only enters other_cashiers amounts; remainder is automatically the current cashier's)
        $currentCashierAmount = ! empty($otherCashiers)
            ? $amount - $otherCashiersSum
            : (float) ($data['current_cashier_amount'] ?? 0);

        // Use reason if provided, otherwise null (no default string)
        $reason = $data['reason'] ?? $data['notes'] ?? null;

        // Handle supporting files if provided
        $supportingFiles = null;
        if (! empty($data['supporting_files'])) {
            $supportingFiles = $this->uploadSupportingFiles($data['supporting_files'], $shift->id);
        }

        ShiftVarianceDetail::create([
            'cashier_shift_id' => $shift->id,
            'responsibility_type' => ResponsibilityType::ME_AND_OTHER_FACTORS,
            'responsible_cashier_id' => $shift->cashier_id,
            'variance_amount' => $amount,
            'variance_type' => $type,
            'assigned_amount' => $currentCashierAmount,
            'reason' => $reason,
            'supporting_files' => $supportingFiles,
        ]);

        // Record for other cashiers
        if (! empty($otherCashiers)) {
            foreach ($otherCashiers as $otherCashier) {
                ShiftVarianceDetail::create([
                    'cashier_shift_id' => $shift->id,
                    'responsibility_type' => ResponsibilityType::ME_AND_OTHER_FACTORS,
                    'responsible_cashier_id' => $otherCashier['cashier_id'],
                    'variance_amount' => $amount,
                    'variance_type' => $type,
                    'assigned_amount' => $otherCashier['amount'],
                    'reason' => $otherCashier['notes'] ?? null,
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
        if (! empty($data['supporting_files'])) {
            $supportingFiles = $this->uploadSupportingFiles($data['supporting_files'], $shift->id);
        }

        ShiftVarianceDetail::create([
            'cashier_shift_id' => $shift->id,
            'responsibility_type' => ResponsibilityType::OTHER_FACTORS,
            'responsible_cashier_id' => null,
            'variance_amount' => $amount,
            'variance_type' => $type,
            'assigned_amount' => $amount,
            'reason' => $data['reason'] ?? null,
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
        if (! empty($data['supporting_files'])) {
            $supportingFiles = $this->uploadSupportingFiles($data['supporting_files'], $shift->id);
        }

        // ✅ Support both 'other_cashiers' (from controller) and 'cashiers' (legacy)
        $cashiers = $data['cashiers'] ?? $data['other_cashiers'] ?? [];

        Log::info('Recording mixed factors variance', [
            'shift_id' => $shift->id,
            'cashiers_count' => count($cashiers),
            'has_other_cashiers' => isset($data['other_cashiers']),
            'has_cashiers' => isset($data['cashiers']),
        ]);

        // Record cashier responsibilities
        if (! empty($cashiers)) {
            foreach ($cashiers as $cashier) {
                ShiftVarianceDetail::create([
                    'cashier_shift_id' => $shift->id,
                    'responsibility_type' => ResponsibilityType::MIXED_FACTORS,
                    'responsible_cashier_id' => $cashier['cashier_id'],
                    'variance_amount' => $amount,
                    'variance_type' => $type,
                    'assigned_amount' => $cashier['amount'],
                    'reason' => $cashier['notes'] ?? null,
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
                'responsibility_type' => ResponsibilityType::MIXED_FACTORS,
                'responsible_cashier_id' => null,
                'variance_amount' => $amount,
                'variance_type' => $type,
                'assigned_amount' => $externalAmount,
                'reason' => $data['external_reason'] ?? $data['reason'] ?? null,
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
        return json_encode($this->stageSupportingFiles($files, $shiftId));
    }

    /** Store supporting files before a caller acquires financial locks. */
    public function stageSupportingFiles(array $files, string $shiftId): array
    {
        $uploadedFiles = [];

        foreach ($files as $file) {
            if (is_string($file)) {
                $uploadedFiles[] = $file;

                continue;
            }

            $filename = 'variance_'.$shiftId.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
            $uploadedFiles[] = $file->storeAs('variance/supporting-files', $filename, 'public');
        }

        return $uploadedFiles;
    }

    /**
     * Get variance details in standardized format
     * Returns variance in the format:
     * {
     *   "responsibility_type": "self_and_others",
     *   "current_cashier_amount": 20,
     *   "other_cashiers": [...],
     *   "reason": "...",
     *   "supporting_files": []
     * }
     */
    public function getVarianceFormatted(CashierShift $shift): ?array
    {
        if (! $shift->hasVariance()) {
            return null;
        }

        $details = ShiftVarianceDetail::where('cashier_shift_id', $shift->id)
            ->with('responsibleCashier')
            ->get();

        if ($details->isEmpty()) {
            return null;
        }

        // Get the main responsibility type (should be same for all details)
        $mainDetail = $details->first();
        $responsibilityType = $mainDetail->responsibility_type?->value ?? $mainDetail->responsibility_type;

        // Normalize responsibility_type values
        $normalizedType = match ($responsibilityType) {
            'i_was_responsible', ResponsibilityType::I_WAS_RESPONSIBLE->value => 'self',
            'me_and_other_factors', ResponsibilityType::ME_AND_OTHER_FACTORS->value => 'self_and_others',
            'other_factors', ResponsibilityType::OTHER_FACTORS->value => 'other_factors',
            'mixed_factors', ResponsibilityType::MIXED_FACTORS->value => 'mixed',
            default => $responsibilityType,
        };

        $result = [
            'responsibility_type' => $normalizedType,
            'current_cashier_amount' => 0,
            'other_cashiers' => [],
            'reason' => null,
            'supporting_files' => [],
        ];

        // Get current cashier's amount
        $currentCashierDetail = $details->firstWhere('responsible_cashier_id', $shift->cashier_id);
        if ($currentCashierDetail) {
            $result['current_cashier_amount'] = (float) $currentCashierDetail->assigned_amount;
            // Use reason from current cashier detail (this should be the main reason)
            $result['reason'] = $currentCashierDetail->reason;
        }

        // Get other cashiers
        $otherCashiers = $details->filter(function ($detail) use ($shift) {
            return $detail->responsible_cashier_id &&
                $detail->responsible_cashier_id !== $shift->cashier_id;
        });

        foreach ($otherCashiers as $detail) {
            $result['other_cashiers'][] = [
                'cashier_id' => $detail->responsible_cashier_id,
                'cashier_name' => $detail->responsibleCashier?->name ?? 'Unknown Cashier',
                'amount' => (float) $detail->assigned_amount,
                'notes' => $detail->reason ?? '',
            ];
        }

        // Get supporting files from any detail (prefer current cashier, then external, then any other)
        $supportingFilesDetail = null;
        if ($currentCashierDetail && ! empty($currentCashierDetail->supporting_files)) {
            $supportingFilesDetail = $currentCashierDetail;
        } else {
            $externalDetail = $details->firstWhere('responsible_cashier_id', null);
            if ($externalDetail && ! empty($externalDetail->supporting_files)) {
                $supportingFilesDetail = $externalDetail;
            } else {
                // Try to find any detail with supporting files
                $supportingFilesDetail = $details->first(function ($detail) {
                    return ! empty($detail->supporting_files);
                });
            }
        }

        if ($supportingFilesDetail && ! empty($supportingFilesDetail->supporting_files)) {
            // supporting_files is cast to array in model, so it should already be an array
            $files = $supportingFilesDetail->supporting_files;

            // Handle both array (from cast) and JSON string (legacy)
            if (is_array($files) && ! empty($files)) {
                $result['supporting_files'] = array_map(
                    fn ($file) => asset('storage/'.$file),
                    $files
                );
            } elseif (is_string($files)) {
                // Fallback: if it's still a string (JSON), decode it
                $decoded = json_decode($files, true);
                if ($decoded && is_array($decoded) && ! empty($decoded)) {
                    $result['supporting_files'] = array_map(
                        fn ($file) => asset('storage/'.$file),
                        $decoded
                    );
                }
            }
        }

        // Get external factors (null cashier_id) - for other_factors and mixed
        $externalDetail = $details->firstWhere('responsible_cashier_id', null);
        if ($externalDetail) {
            // For other_factors and mixed, use external detail's reason as main reason
            if (in_array($normalizedType, ['other_factors', 'mixed'])) {
                $result['reason'] = $externalDetail->reason;
            } elseif (empty($result['reason'])) {
                $result['reason'] = $externalDetail->reason;
            }
        }

        // Only return actual user input; null when empty or a default placeholder
        $defaultReasons = ['Shared responsibility', 'Cashier accepted full responsibility', 'No reason provided', 'External factors', 'Mixed factors'];
        if (empty(trim((string) ($result['reason'] ?? ''))) || in_array($result['reason'], $defaultReasons, true)) {
            $result['reason'] = null;
        }

        // Calculate total variance amount
        $totalVarianceAmount = $result['current_cashier_amount'];

        // Add other cashiers amounts
        foreach ($result['other_cashiers'] as $otherCashier) {
            $totalVarianceAmount += $otherCashier['amount'];
        }

        // Add external factors amount (if exists)
        if ($externalDetail) {
            $totalVarianceAmount += (float) $externalDetail->assigned_amount;
        }

        $result['total_variance_amount'] = $totalVarianceAmount;
        $result['variance_type'] = $shift->variance > 0 ? 'Over' : 'Short';

        return $result;
    }

    /**
     * Get variance details (legacy method - kept for backward compatibility)
     */
    public function getVarianceDetails(CashierShift $shift): array
    {
        $formatted = $this->getVarianceFormatted($shift);

        return [
            'total_variance' => $shift->variance,
            'variance_type' => $shift->variance > 0 ? 'Over' : 'Short',
            'variance' => $formatted,
        ];
    }
}
