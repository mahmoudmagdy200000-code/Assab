<?php

namespace Modules\Shift\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request for recording a handover
 * 
 * Supports handover to:
 * - Next cashier (auto-handover between consecutive shifts)
 * - Branch manager (final handover at end of day)
 */
class RecordHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Handover recipient
            'handover_to_type' => [
                'required',
                Rule::in(['cashier', 'branch_manager']),
            ],
            'next_cashier_id' => 'required_if:handover_to_type,cashier|nullable|exists:cashiers,id',
            
            // Handover details
            'handover_amount' => 'required|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
            
            // Variance handling (optional)
            'variance' => 'sometimes|array',
            'variance.responsibility_type' => [
                'required_with:variance',
                Rule::in(['self', 'self_and_others', 'other_factors', 'mixed']),
            ],
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
            'handover_to_type.required' => 'Please specify the handover recipient type.',
            'handover_to_type.in' => 'Handover recipient must be either cashier or branch_manager.',
            'next_cashier_id.required_if' => 'Please select the next cashier for handover.',
            'next_cashier_id.exists' => 'The selected cashier does not exist.',
            'handover_amount.required' => 'Handover amount is required.',
            'handover_amount.min' => 'Handover amount cannot be negative.',
            'variance.responsibility_type.in' => 'Invalid responsibility type.',
            'variance.reason.required_if' => 'A reason is required for external factors.',
        ];
    }
}

