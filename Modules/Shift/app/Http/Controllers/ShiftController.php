<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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

        $query = CashierShift::whereHas('cashier', function ($q) use ($manager) {
            $q->where('branch_id', $manager->branch_id);
        });

        // Prefer ordering by cashier_shifts.start_time if that column exists,
        // otherwise try to order by related shifts.start_time (join), otherwise fallback.
        if (Schema::hasColumn('cashier_shifts', 'start_time')) {
            $query->orderBy('start_time');
        } elseif (Schema::hasColumn('cashier_shifts', 'shift_id') && Schema::hasColumn('shifts', 'start_time')) {
            $query = $query
                ->join('shifts', 'shifts.id', '=', 'cashier_shifts.shift_id')
                ->orderBy('shifts.start_time')
                ->select('cashier_shifts.*');
        } else {
            $query->orderBy('created_at');
        }

        $shifts = $query->paginate(10);

        return $this->paginatedResponse($shifts, 'Cashiers shifts retrieved successfully');
    }
}
