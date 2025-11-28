<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Transformers\BranchManagerResource;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Transformers\CashierResource;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Transformers\CashierShiftResource;

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

        $query = CashierShift::with(['cashier', 'shift', 'assignedBy'])  // Added eager loading
            ->whereHas('cashier', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            });

        // 👤 Filter by specific cashier
        if ($cashierId = $request->input('cashier_id')) {
            $query->where('cashier_id', $cashierId);
        }

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

        $shifts = $query->paginate($request->input('per_page', 10));

        return $this->paginatedResponse(
            CashierShiftResource::collection($shifts),
            'Cashiers shifts retrieved successfully'
        );
    }

    public function filterCashierShifts(Request $request)
    {
        $manager = auth()->user();

        if (!$manager || !$manager->branch_id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $query = CashierShift::with(['cashier', 'shift'])
            ->whereHas('cashier', function ($q) use ($manager) {
                $q->where('branch_id', $manager->branch_id);
            });

        // 👤 Filter by specific cashier
        if ($cashierId = $request->input('cashier_id')) {
            $query->where('cashier_id', $cashierId);
        }

        // 🔍 البحث العام (بالكاشير أو رقم الشيفت)
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('cashier_shifts.id', 'like', "%{$search}%")
                    ->orWhereHas('cashier', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        // 🕒 الفلترة حسب الفترة الزمنية
        if ($period = $request->input('period')) {
            switch ($period) {
                case 'today':
                    $query->whereDate('cashier_shifts.created_at', now()->toDateString());
                    break;
                case 'last_7_days':
                    $query->where('cashier_shifts.created_at', '>=', now()->subDays(7));
                    break;
                case 'last_30_days':
                    $query->where('cashier_shifts.created_at', '>=', now()->subDays(30));
                    break;
                case 'last_24_hours':
                    $query->where('cashier_shifts.created_at', '>=', now()->subDay());
                    break;
            }
        }

        // 📆 فلترة مخصصة حسب التاريخ
        if ($from = $request->input('date_from')) {
            $query->whereDate('cashier_shifts.created_at', '>=', $from);
        }

        if ($to = $request->input('date_to')) {
            $query->whereDate('cashier_shifts.created_at', '<=', $to);
        }

        // 📌 فلترة حسب الحالة (مفتوح / مغلق)
        if ($status = $request->input('status')) {
            $query->where('cashier_shifts.status', $status);
        }

        // 💰 فلترة حسب المبالغ (اختياري)
        if ($min = $request->input('min_total')) {
            $query->where('cashier_shifts.total_sales', '>=', $min);
        }

        if ($max = $request->input('max_total')) {
            $query->where('cashier_shifts.total_sales', '<=', $max);
        }

        // 🔄 ترتيب حسب وقت الشيفت الحقيقي من جدول shifts
        $query->leftJoin('shifts', 'shifts.id', '=', 'cashier_shifts.shift_id')
            ->orderBy('shifts.start_time', 'desc')
            ->select('cashier_shifts.*');

        // 📄 Pagination
        $shifts = $query->paginate($request->input('per_page', 20));

        return $this->paginatedResponse(
            CashierShiftResource::collection($shifts),
            'Filtered cashier shifts retrieved successfully'
        );
    }

    public function show($id)
    {
        // Logic to get a specific shift by ID
        $shift = Shift::find($id);
        if (!$shift) {
            return $this->errorResponse('Shift not found', 404);
        }
        return $this->successResponse($shift, 'Shift retrieved successfully');
    }

    public function getCashierShiftById($id)
    {
        $cashierShift = CashierShift::with(['cashier', 'shift', 'assignedBy'])->find($id);

        if (!$cashierShift) {
            return $this->errorResponse('Cashier Shift not found', 404);
        }

        return $this->successResponse(
            new CashierShiftResource($cashierShift),
            'Cashier shift retrieved successfully'
        );
    }

    public function getShiftByCashierId($id)
    {
        $cashierShifts = CashierShift::with(['cashier', 'shift', 'assignedBy'])
            ->where('cashier_id', $id)
            ->get();

        if ($cashierShifts->isEmpty()) {
            return $this->errorResponse('No shifts found for this cashier', 404);
        }

        return $this->successResponse(
            CashierShiftResource::collection($cashierShifts),
            'Cashier shifts retrieved successfully'
        );
    }

    public function getAllCashiersAndBranchManagerAccount(Request $request)
    {
        $manager = auth()->user();

        if (!$manager || !$manager->branch_id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $cashiers = Cashier::where('branch_id', $manager->branch_id)
            ->paginate($request->input('per_page', 10));

        $branchManagers = BranchManager::where('branch_id', $manager->branch_id)->get();

        return $this->paginatedResponse([
            'cashiers' => CashierResource::collection($cashiers),
            'branch_managers' => BranchManagerResource::collection($branchManagers),
        ], 'Filtered cashier shifts retrieved successfully');
    }
}
