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

        // 🔍 البحث العام (مثلاً بالكاشير أو كود الشيفت)
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhereHas('cashier', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        // 🕒 الفلترة حسب الفترة الزمنية
        if ($period = $request->input('period')) {
            switch ($period) {
                case 'today':
                    $query->whereDate('start_time', now()->toDateString());
                    break;
                case 'last_7_days':
                    $query->where('start_time', '>=', now()->subDays(7));
                    break;
                case 'last_30_days':
                    $query->where('start_time', '>=', now()->subDays(30));
                    break;
                case 'last_24_hours':
                    $query->where('start_time', '>=', now()->subDay());
                    break;
            }
        }

        // 📆 فلترة بتواريخ مخصصة (اختياري)
        if ($from = $request->input('date_from')) {
            $query->whereDate('start_time', '>=', $from);
        }

        if ($to = $request->input('date_to')) {
            $query->whereDate('start_time', '<=', $to);
        }

        // 📌 فلترة حسب الحالة (مفتوح / مغلق)
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // 💰 فلترة حسب المبالغ (اختياري)
        if ($min = $request->input('min_total')) {
            $query->where('total_sales', '>=', $min);
        }

        if ($max = $request->input('max_total')) {
            $query->where('total_sales', '<=', $max);
        }

        // ترتيب و Pagination
        $shifts = $query->orderBy('start_time', 'desc')
            ->paginate($request->input('per_page', 20));

        return $this->paginatedResponse($shifts, 'Filtered cashier shifts retrieved successfully');
    }
}
