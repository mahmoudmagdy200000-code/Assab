<?php

namespace Modules\Shift\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Enums\ShiftStatus;

/**
 * Form Request for creating a cashier with shift assignments
 * 
 * Business Rules:
 * - Store name, number of branches, branch assignments → Admin ONLY
 * - Number of shifts, schedules, working hours → Admin ONLY
 * - Cashiers and assigned shifts → Branch Manager ONLY
 * - Cannot assign cashier to shift already occupied
 * - Automatic shift handover between consecutive shifts
 */
class StoreCashierWithShiftsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only branch managers can create cashiers
        return auth()->user() instanceof \Modules\BranchManagers\Models\BranchManager;
    }

    public function rules(): array
    {
        return [
            // Cashier Information
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:cashiers,email',
            
            // Branch assignment (from admin-managed list)
            'store_branch_id' => [
                'required',
                'exists:branches,id',
                function ($attribute, $value, $fail) {
                    // Verify branch belongs to the manager
                    $manager = auth()->user();
                    if ($manager->branch_id !== $value) {
                        $fail('You can only create cashiers for your assigned branch.');
                    }
                },
            ],
            
            // Working Shifts Assignment (multi-select)
            'shift_ids' => 'required|array|min:1',
            'shift_ids.*' => [
                'exists:shifts,id',
                function ($attribute, $value, $fail) {
                    $this->validateShiftNotOccupied($value, $fail);
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Cashier name is required.',
            'email.required' => 'Cashier email is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email is already registered.',
            'store_branch_id.required' => 'Please select a branch.',
            'store_branch_id.exists' => 'The selected branch does not exist.',
            'shift_ids.required' => 'Please assign at least one shift.',
            'shift_ids.min' => 'Please assign at least one shift.',
            'shift_ids.*.exists' => 'One or more selected shifts do not exist.',
        ];
    }

    /**
     * Validate that the shift is not already occupied by another cashier
     */
    private function validateShiftNotOccupied($shiftId, $fail): void
    {
        $branchId = $this->input('store_branch_id');
        
        // Check if any cashier is already assigned to this shift for future dates
        $occupied = CashierShift::where('shift_id', $shiftId)
            ->whereHas('shift', fn($q) => $q->where('branch_id', $branchId))
            ->whereDate('shift_date', '>=', today())
            ->whereIn('status', [
                ShiftStatus::NOT_STARTED->value,
                ShiftStatus::IN_PROGRESS->value,
                ShiftStatus::REASSIGNED->value,
            ])
            ->with('cashier')
            ->first();

        if ($occupied) {
            $shift = \Modules\Shift\Models\Shift::find($shiftId);
            $fail("Shift '{$shift->name}' is already assigned to {$occupied->cashier->name}.");
        }
    }

    /**
     * Get the data for creating the cashier
     */
    public function getCashierData(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'branch_id' => $this->store_branch_id,
            'status' => 'pending', // Pending until activation
            'created_by' => auth()->id(),
        ];
    }

    /**
     * Get the shift IDs for assignment
     */
    public function getShiftIds(): array
    {
        return $this->shift_ids;
    }
}

