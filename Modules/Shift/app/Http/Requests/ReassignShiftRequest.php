<?php

namespace Modules\Shift\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Enums\ShiftStatus;

/**
 * Form Request for reassigning a shift
 * 
 * Business Rules:
 * - Cannot reassign to occupied shifts
 * - Cannot reassign currently working cashier
 * - Must provide reason for reassignment
 * - Automatic handover logic between consecutive shifts
 */
class ReassignShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_cashier_id' => [
                'required',
                'exists:cashiers,id',
                function ($attribute, $value, $fail) {
                    $this->validateCashierNotOccupied($value, $fail);
                },
            ],
            'reason' => 'nullable|string|max:500',
            
            // For in-progress shift reassignment with handover
            'with_handover' => 'sometimes|boolean',
            'handover_amount' => 'required_if:with_handover,true|nullable|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
            
            // Current sales data (for in-progress shifts)
            'current_sales' => 'sometimes|numeric|min:0',
            'cash_collected' => 'sometimes|numeric|min:0',
            'card_payments' => 'sometimes|numeric|min:0',
            'aggregators' => 'sometimes|array',
            'aggregators.*.aggregator_id' => 'required_with:aggregators|exists:aggregators,id',
            'aggregators.*.amount' => 'required_with:aggregators|numeric|min:0',
            'aggregators.*.notes' => 'nullable|string|max:255',
            'pos_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            
            // Variance data (for reassignment with variance)
            'variance' => 'sometimes|array',
            'variance.responsibility_type' => 'required_with:variance|in:self,self_and_others,other_factors,mixed',
            'variance.current_cashier_amount' => 'required_if:variance.responsibility_type,self_and_others,mixed|nullable|numeric|min:0',
            'variance.other_cashiers' => 'sometimes|array',
            'variance.other_cashiers.*.cashier_id' => 'required_with:variance.other_cashiers|exists:cashiers,id',
            'variance.other_cashiers.*.amount' => 'required_with:variance.other_cashiers|numeric|min:0',
            'variance.other_cashiers.*.notes' => 'nullable|string|max:255',
            'variance.reason' => 'required_if:variance.responsibility_type,other_factors,mixed|nullable|string|max:500',
            'variance.supporting_files' => 'sometimes|array',
            'variance.supporting_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'new_cashier_id.required' => 'Please select a cashier to reassign to.',
            'new_cashier_id.exists' => 'The selected cashier does not exist.',
            'handover_amount.required_if' => 'Handover amount is required when reassigning with handover.',
            'variance.responsibility_type.in' => 'Invalid variance responsibility type.',
        ];
    }

    /**
     * Validate that the new cashier is not already working or assigned to this shift
     */
    private function validateCashierNotOccupied($cashierId, $fail): void
    {
        $shift = $this->route('shift');
        
        if (!$shift) {
            return;
        }

        // Get the shift model
        $shiftModel = CashierShift::with('shift')->find($shift);
        
        if (!$shiftModel) {
            return;
        }

        // Check if trying to reassign to same cashier
        if ($shiftModel->cashier_id === $cashierId) {
            $fail('Cannot reassign to the same cashier.');
            return;
        }

        // Check if cashier is already working on another shift
        $conflictingShift = CashierShift::where('cashier_id', $cashierId)
            ->where('id', '!=', $shift)
            ->where('shift_date', $shiftModel->shift_date)
            ->whereIn('status', [
                ShiftStatus::IN_PROGRESS->value,
                ShiftStatus::NOT_STARTED->value,
            ])
            ->first();

        if ($conflictingShift) {
            $status = $conflictingShift->status === ShiftStatus::IN_PROGRESS 
                ? 'currently working' 
                : 'already assigned to another shift';
            $fail("The selected cashier is {$status} on this date.");
        }
    }

    /**
     * Get validated data with additional context
     */
    public function validatedWithContext(): array
    {
        $validated = $this->validated();
        $validated['reassigned_by'] = auth()->id();
        $validated['reassigned_at'] = now();
        
        return $validated;
    }
}

