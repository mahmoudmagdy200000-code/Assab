<?php

namespace Modules\Shift\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request for approving/rejecting handovers
 * 
 * Business Rules:
 * - Approve: Changes status from Pending → Approved
 * - Reject: Must require reason for rejection
 * - Re-approval flow:
 *   - If cashier edits rejected handover → Can re-approve or reject
 *   - Second rejection → Status permanently changes to Rejected
 */
class HandoverApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isRejection = $this->routeIs('*.reject') || $this->input('action') === 'reject';
        
        return [
            // Action type
            'action' => 'sometimes|in:approve,reject',
            
            // Manager comment (optional for approval, required for rejection)
            'manager_comment' => $isRejection ? 'nullable|string|max:500' : 'nullable|string|max:500',
            
            // Rejection specific fields
            'rejection_reason' => $isRejection ? 'required|string|max:500' : 'nullable|string|max:500',
            'rejection_files' => 'sometimes|array',
            'rejection_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'A reason is required when rejecting a handover.',
            'rejection_reason.max' => 'Rejection reason cannot exceed 500 characters.',
            'rejection_files.*.mimes' => 'Rejection files must be PDF, PNG, or JPEG.',
            'rejection_files.*.max' => 'Each rejection file must not exceed 5MB.',
        ];
    }
}

