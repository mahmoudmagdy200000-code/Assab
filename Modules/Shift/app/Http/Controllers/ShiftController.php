<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;


class ShiftController extends BaseController
{
    // Controller methods will go here


    // get all shifts
    public function index()
    {
        // Logic to get all shifts

        $manager = auth()->user();

        // Ensure the user is a branch manager
        if (!$manager || !$manager->branch_id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Get all shifts for that branch
        $shifts = Shift::where('branch_id', $manager->branch_id)
            ->orderBy('start_time')
            ->paginate(10);

        return $this->successResponse($shifts, 'Shifts retrieved successfully');
    }

    public function getAllCashiersShifts(Request $request)
    {
        $manager = auth()->user();

        if (!$manager || !$manager->branch_id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Filter by related cashier's branch_id (requires CashierShift::cashier relation)
        $shifts = CashierShift::whereHas('cashier', function ($q) use ($manager) {
            $q->where('branch_id', $manager->branch_id);
        })
            ->orderBy('start_time')
            ->paginate(10);

        return $this->paginatedResponse($shifts, 'Cashiers shifts retrieved successfully');
    }
}
