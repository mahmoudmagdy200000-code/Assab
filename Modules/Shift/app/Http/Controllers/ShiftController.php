<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Transformers\BranchManagerResource;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Transformers\CashierResource;
use Modules\Shift\Helpers\ShiftHelper;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Transformers\CashierShiftResource;
use Modules\Shift\Transformers\ShiftTemplateResource;

class ShiftController extends BaseController
{
    // Controller methods will go here

    /**
     * Get all shifts
     * OPTIMIZED: Select only required fields and add authorization check
     */
    public function index()
    {
        try {
            $manager = auth()->user();

            // Ensure the user is a branch manager
            if (! $manager || ! $manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            // OPTIMIZED: Select only required fields + count active assignments for today
            $shifts = Shift::where('branch_id', $manager->branch_id)
                ->select(['id', 'name', 'start_time', 'end_time', 'branch_id', 'is_active', 'created_at', 'updated_at'])
                ->withCount(['cashierShifts as active_assignments_count' => function ($q) {
                    $q->whereDate('shift_date', today())
                        ->whereIn('status', ['not_started', 'in_progress']);
                }])
                ->orderBy('start_time')
                ->paginate(10);

            return $this->paginatedResponse(ShiftTemplateResource::collection($shifts), 'Shifts retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Error retrieving shifts', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('An error occurred while retrieving shifts', 500);
        }
    }

    /**
     * Get all cashiers shifts
     * OPTIMIZED: Use subquery instead of whereHas, select specific fields, improve ordering
     */
    public function getAllCashiersShifts(Request $request)
    {
        try {
            $manager = auth()->user();

            if (! $manager || ! $manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            // OPTIMIZED: Use subquery instead of whereHas for better performance
            $cashierIds = Cashier::where('branch_id', $manager->branch_id)->pluck('id');

            $query = CashierShift::whereIn('cashier_id', $cashierIds)
                ->select([
                    'id',
                    'cashier_id',
                    'shift_id',
                    'shift_date',
                    'status',
                    'assigned_by',
                    'original_cashier_id',
                    'reassigned_by',
                    'reassigned_at',
                    'reassignment_reason',
                    'opening_balance',
                    'closing_balance',
                    'expected_balance',
                    'variance',
                    'total_sales',
                    'net_sales',
                    'vat_amount',
                    'cash_collected',
                    'card_payments',
                    'pos_receipt',
                    'actual_start_time',
                    'actual_end_time',
                    'handed_over_at',
                    'handover_notes',
                    'next_cashier_id',
                    'created_at',
                    'updated_at',
                ])
                ->with([
                    'cashier:id,name,branch_id',
                    'shift' => function ($q) {
                        $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id', 'is_active']);
                    },
                    'shift.branch:id,name,location',
                    'assignedBy:id,name',
                    'originalCashier:id,name,branch_id',
                    'reassignedBy:id,name,email,phone',
                    'nextCashier:id,name,email,phone',
                ]);

            // Apply filters: date_filter, status (same keys as filter endpoint)
            $this->applyCashierShiftFilters($query, $request);

            // OPTIMIZED: Ordering logic
            if (Schema::hasColumn('cashier_shifts', 'start_time')) {
                $query->orderBy('start_time');
            } elseif (Schema::hasColumn('cashier_shifts', 'shift_id')) {
                // Use subquery for ordering instead of join
                $query->orderByRaw('(SELECT start_time FROM shifts WHERE shifts.id = cashier_shifts.shift_id) ASC');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $shifts = $query->paginate($request->input('per_page', 10));

            $shiftService = app(ShiftService::class);
            foreach ($shifts as $cs) {
                $cs->setAttribute('computed_next_cashier', $shiftService->getNextShiftCashier($cs));
            }

            return $this->paginatedResponse(
                CashierShiftResource::collection($shifts),
                'Cashiers shifts retrieved successfully'
            );
        } catch (\Exception $e) {
            Log::error('Error retrieving cashiers shifts', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('An error occurred while retrieving cashiers shifts', 500);
        }
    }

    /**
     * Filter cashier shifts
     * OPTIMIZED: Use subquery instead of whereHas, sanitize search input, extract filter logic
     */
    public function filterCashierShifts(Request $request)
    {
        try {
            $manager = auth()->user();

            if (! $manager || ! $manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            // OPTIMIZED: Use subquery instead of whereHas for better performance
            $cashierIds = Cashier::where('branch_id', $manager->branch_id)->pluck('id');

            $query = CashierShift::whereIn('cashier_id', $cashierIds)
                ->select([
                    'id',
                    'cashier_id',
                    'shift_id',
                    'shift_date',
                    'status',
                    'assigned_by',
                    'original_cashier_id',
                    'reassigned_by',
                    'reassigned_at',
                    'reassignment_reason',
                    'opening_balance',
                    'closing_balance',
                    'expected_balance',
                    'variance',
                    'total_sales',
                    'net_sales',
                    'vat_amount',
                    'cash_collected',
                    'card_payments',
                    'pos_receipt',
                    'actual_start_time',
                    'actual_end_time',
                    'handed_over_at',
                    'handover_notes',
                    'next_cashier_id',
                    'created_at',
                    'updated_at',
                ])
                ->with([
                    'cashier:id,name,branch_id',
                    'shift' => function ($q) {
                        $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id', 'is_active']);
                    },
                    'shift.branch:id,name,location',
                    'assignedBy:id,name',
                    'originalCashier:id,name,branch_id',
                    'reassignedBy:id,name,email,phone',
                    'nextCashier:id,name,email,phone',
                ]);

            // Apply filters using helper method
            $this->applyCashierShiftFilters($query, $request);

            // 🔄 ترتيب حسب وقت الشيفت الحقيقي من جدول shifts (optimized - use subquery instead of join)
            $query->orderByRaw('(SELECT start_time FROM shifts WHERE shifts.id = cashier_shifts.shift_id) DESC');

            // 📄 Pagination
            $shifts = $query->paginate($request->input('per_page', 20));

            return $this->paginatedResponse(
                CashierShiftResource::collection($shifts),
                'Filtered cashier shifts retrieved successfully'
            );
        } catch (\Exception $e) {
            Log::error('Error filtering cashier shifts', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('An error occurred while filtering cashier shifts', 500);
        }
    }

    /**
     * Show specific shift by ID
     * OPTIMIZED: Select only required fields, add authorization check
     */
    public function show($id)
    {
        try {
            $manager = auth()->user();

            if (! $manager || ! $manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            // OPTIMIZED: Select only required fields
            $shift = Shift::select(['id', 'name', 'start_time', 'end_time', 'branch_id', 'is_active', 'created_at', 'updated_at'])
                ->where('id', (int) $id)
                ->where('branch_id', $manager->branch_id)
                ->first();

            if (! $shift) {
                return $this->errorResponse('Shift not found', 404);
            }

            return $this->successResponse($shift, 'Shift retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Error retrieving shift', [
                'user_id' => auth()->id(),
                'shift_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('An error occurred while retrieving shift', 500);
        }
    }

    /**
     * Get cashier shift by ID
     * OPTIMIZED: Select only required fields, add authorization check
     */
    public function getCashierShiftById(ShiftService $shiftService, $id)
    {
        try {
            $manager = auth()->user();

            if (! $manager || ! $manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            // OPTIMIZED: Select only required fields
            // Note: CashierShift uses UUID, not integer
            $cashierShift = CashierShift::select([
                'id',
                'cashier_id',
                'shift_id',
                'shift_date',
                'status',
                'assigned_by', // Note: column name is 'assigned_by', not 'assigned_by_id'
                'next_cashier_id',
                'opening_balance',
                'closing_balance',
                'expected_balance',
                'variance',
                'total_sales',
                'net_sales',
                'vat_amount',
                'cash_collected',
                'card_payments',
                'pos_receipt',
                'actual_start_time',
                'actual_end_time',
                'handed_over_at',
                'handover_notes',
                'original_cashier_id',
                'reassigned_by',
                'reassignment_reason',
                'reassigned_at',
                'created_at',
                'updated_at',
            ])
                ->with([
                    'cashier:id,name,branch_id',
                    'shift' => function ($q) {
                        $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id', 'is_active']);
                    },
                    'shift.branch:id,name,location',
                    'assignedBy:id,name',
                    'nextCashier:id,name,email,phone',
                    'handover' => function ($q) {
                        // Note: Polymorphic relationship loading needs special handling
                        // We'll load the related model in the Resource transformer
                    },
                    'handoverStatus.reviewedBy:id,name',
                    'salesBreakdown.aggregator:id,name',
                    'varianceDetails.responsibleCashier:id,name',
                ])
                ->find($id);

            if (! $cashierShift) {
                return $this->errorResponse('Cashier Shift not found', 404);
            }

            // Verify cashier belongs to manager's branch
            if (! $cashierShift->cashier) {
                Log::warning('Cashier shift found but cashier relationship is missing', [
                    'shift_id' => $id,
                    'cashier_id' => $cashierShift->cashier_id,
                ]);

                return $this->errorResponse('Cashier information not found for this shift', 404);
            }

            if ($cashierShift->cashier->branch_id !== $manager->branch_id) {
                return $this->errorResponse('Unauthorized: This cashier does not belong to your branch', 403);
            }

            // Compute next cashier from chronologically next shift (same day, same branch) for display
            $cashierShift->setAttribute('computed_next_cashier', $shiftService->getNextShiftCashier($cashierShift));

            try {
                return $this->successResponse(
                    new CashierShiftResource($cashierShift),
                    'Cashier shift retrieved successfully'
                );
            } catch (\Exception $resourceException) {
                Log::error('Error in CashierShiftResource transformation', [
                    'shift_id' => $id,
                    'error' => $resourceException->getMessage(),
                    'trace' => $resourceException->getTraceAsString(),
                ]);
                throw $resourceException;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Cashier Shift not found', 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving cashier shift', [
                'user_id' => auth()->id(),
                'branch_id' => $manager->branch_id ?? null,
                'shift_id' => $id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('An error occurred while retrieving cashier shift: '.$e->getMessage(), 500);
        }
    }

    /**
     * Get shifts by cashier ID (current work week, all statuses).
     * Optional: ?week_start=YYYY-MM-DD to view another week.
     */
    public function getShiftByCashierId(Request $request, ShiftService $shiftService, $id)
    {
        try {
            $manager = auth()->user();

            if (! $manager || ! $manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            $cashier = Cashier::where('id', (int) $id)
                ->where('branch_id', $manager->branch_id)
                ->first(['id', 'branch_id']);

            if (! $cashier) {
                return $this->errorResponse('Cashier not found or does not belong to your branch', 404);
            }

            $weekStart = $request->query('week_start');
            $refDate = $weekStart ? Carbon::parse($weekStart) : Carbon::today();
            [$start, $end] = ShiftHelper::workWeekDatesFor($refDate);

            $cashierShifts = CashierShift::select([
                'id',
                'cashier_id',
                'shift_id',
                'shift_date',
                'status',
                'assigned_by',
                'created_at',
                'updated_at',
            ])
                ->where('cashier_id', $cashier->id)
                ->whereDate('shift_date', '>=', $start)
                ->whereDate('shift_date', '<=', $end)
                ->with([
                    'cashier:id,name,branch_id',
                    'shift' => function ($q) {
                        $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id', 'is_active']);
                    },
                    'shift.branch:id,name,location',
                    'assignedBy:id,name',
                    'nextCashier:id,name,email,phone',
                ])
                ->orderBy('shift_date', 'asc')
                ->get();

            if ($cashierShifts->isEmpty()) {
                return $this->errorResponse('No shifts found for this cashier in the selected week', 404);
            }

            foreach ($cashierShifts as $cs) {
                $computed = $shiftService->getNextShiftCashier($cs);
                $cs->setAttribute('computed_next_cashier', $computed);
            }

            return $this->successResponse(
                CashierShiftResource::collection($cashierShifts),
                'Cashier shifts retrieved successfully'
            );
        } catch (\Exception $e) {
            Log::error('Error retrieving shifts by cashier', [
                'user_id' => auth()->id(),
                'cashier_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('An error occurred while retrieving cashier shifts', 500);
        }
    }

    /**
     * Get all cashiers and branch manager accounts
     * OPTIMIZED: Select only required fields, improve error handling
     */
    public function getAllCashiersAndBranchManagerAccount(Request $request)
    {
        try {
            $manager = auth()->user();

            if (! $manager || ! $manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            // OPTIMIZED: Load cashiers with relationships and count (select specific fields)
            $cashiers = Cashier::where('branch_id', $manager->branch_id)
                ->select(['id', 'name', 'email', 'phone', 'branch_id', 'is_active', 'created_by_id', 'created_at', 'updated_at'])
                ->with([
                    'branch:id,name,location',
                    'creator:id,name',
                ])
                ->withCount('shifts')
                ->paginate($request->input('per_page', 10));

            // OPTIMIZED: Load branch managers with relationships (already optimized)
            $branchManagers = BranchManager::where('branch_id', $manager->branch_id)
                ->select(['id', 'name', 'email', 'phone', 'branch_id', 'is_active', 'status', 'is_first_login', 'image', 'email_verified_at', 'phone_verified_at', 'created_at', 'updated_at'])
                ->with('branch:id,name,location')
                ->get();

            $combined = [
                'branch_managers' => BranchManagerResource::collection($branchManagers),
                'cashiers' => CashierResource::collection($cashiers->items()),
                'pagination' => [
                    'current_page' => $cashiers->currentPage(),
                    'per_page' => $cashiers->perPage(),
                    'total' => $cashiers->total(),
                    'last_page' => $cashiers->lastPage(),
                    'from' => $cashiers->firstItem(),
                    'to' => $cashiers->lastItem(),
                ],
            ];

            return $this->successResponse($combined, 'Cashiers and branch managers retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Error retrieving cashiers and branch managers', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('An error occurred while retrieving cashiers and branch managers', 500);
        }
    }

    /**
     * Start shift by manager
     * OPTIMIZED: Add transaction, select specific fields, improve error handling
     */
    public function startShiftByManager($shiftId)
    {
        try {
            $branchManager = auth()->user();

            if (! $branchManager || ! $branchManager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            // OPTIMIZED: Select only required fields
            // Note: CashierShift uses UUID, not integer
            $shiftModel = CashierShift::select([
                'id',
                'cashier_id',
                'shift_id',
                'status',
                'created_at',
                'updated_at',
            ])
                ->with([
                    'shift' => function ($q) {
                        $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id', 'is_active']);
                    },
                    'shift.branch:id,name',
                    'cashier:id,name,branch_id',
                    'nextCashier:id,name',
                ])
                ->findOrFail($shiftId);

            // Verify the cashier belongs to the branch manager's branch
            if ($shiftModel->cashier->branch_id !== $branchManager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This cashier does not belong to your branch',
                ], 403);
            }

            if ($shiftModel->status->value !== 'not_started') {
                return response()->json([
                    'success' => false,
                    'message' => 'Shift has already been started',
                ], 400);
            }

            // Use database transaction for critical operations
            DB::beginTransaction();

            try {
                $shiftModel->startShift();
                $shiftModel->loadFullRelationships();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Shift started successfully by branch manager',
                    'data' => new \Modules\Shift\Transformers\ShiftDetailResource($shiftModel),
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Shift not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error starting shift by manager', [
                'user_id' => auth()->id(),
                'shift_id' => $shiftId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while starting the shift',
            ], 500);
        }
    }

    /**
     * Apply filters to cashier shift query
     * OPTIMIZED: Extract filter logic to reduce code duplication and improve maintainability
     */
    private function applyCashierShiftFilters($query, Request $request): void
    {
        // 👤 Filter by specific cashier
        if ($cashierId = $request->input('cashier_id')) {
            $query->where('cashier_id', (int) $cashierId);
        }

        // date_filter: last_24_hours, last_7_days, last_14_days, last_30_days, last_1_year (filter by shift_date)
        $dateFilter = $request->input('date_filter');
        $allowedDateFilters = ['last_24_hours', 'last_7_days', 'last_14_days', 'last_30_days', 'last_1_year'];
        if ($dateFilter && in_array($dateFilter, $allowedDateFilters, true)) {
            $now = Carbon::now();
            switch ($dateFilter) {
                case 'last_24_hours':
                    $query->where('cashier_shifts.shift_date', '>=', $now->copy()->subDay());
                    break;
                case 'last_7_days':
                    $query->where('cashier_shifts.shift_date', '>=', $now->copy()->subDays(7));
                    break;
                case 'last_14_days':
                    $query->where('cashier_shifts.shift_date', '>=', $now->copy()->subDays(14));
                    break;
                case 'last_30_days':
                    $query->where('cashier_shifts.shift_date', '>=', $now->copy()->subDays(30));
                    break;
                case 'last_1_year':
                    $query->where('cashier_shifts.shift_date', '>=', $now->copy()->subYear());
                    break;
                default:
                    break;
            }
        }

        // status: inProgress, pending, completed, all (map to DB: in_progress, not_started, completed; all = no filter)
        $status = $request->input('status');
        $allowedStatuses = ['inProgress', 'pending', 'completed', 'all'];
        if ($status && in_array($status, $allowedStatuses, true) && $status !== 'all') {
            $dbStatus = match ($status) {
                'inProgress' => 'in_progress',
                'pending' => 'not_started',
                'completed' => 'completed',
                default => null,
            };
            if ($dbStatus !== null) {
                $query->where('cashier_shifts.status', $dbStatus);
            }
        }

        // 🔍 البحث العام (بالكاشير أو رقم الشيفت) - SECURITY: Sanitize search input
        if ($search = $request->input('search')) {
            // Sanitize search input to prevent SQL injection
            $search = trim(strip_tags($search));
            if (! empty($search)) {
                $searchPattern = "%{$search}%";
                $query->where(function ($q) use ($searchPattern) {
                    // Use parameter binding for security
                    $q->where('cashier_shifts.id', 'like', $searchPattern)
                        ->orWhereIn('cashier_id', function ($subQuery) use ($searchPattern) {
                            $subQuery->select('id')
                                ->from('cashiers')
                                ->where('name', 'like', $searchPattern);
                        });
                });
            }
        }

        // 🕒 Legacy: period (date_filter is preferred)
        if ($period = $request->input('period')) {
            $allowedPeriods = ['today', 'last_7_days', 'last_30_days', 'last_24_hours'];
            if (in_array($period, $allowedPeriods)) {
                switch ($period) {
                    case 'today':
                        $query->whereDate('cashier_shifts.shift_date', now()->toDateString());
                        break;
                    case 'last_7_days':
                        $query->where('cashier_shifts.shift_date', '>=', now()->subDays(7));
                        break;
                    case 'last_30_days':
                        $query->where('cashier_shifts.shift_date', '>=', now()->subDays(30));
                        break;
                    case 'last_24_hours':
                        $query->where('cashier_shifts.shift_date', '>=', now()->subDay());
                        break;
                    default:
                        break;
                }
            }
        }

        // 📆 Custom date range
        if ($from = $request->input('date_from')) {
            $query->whereDate('cashier_shifts.shift_date', '>=', $from);
        }

        if ($to = $request->input('date_to')) {
            $query->whereDate('cashier_shifts.shift_date', '<=', $to);
        }

        // 💰 فلترة حسب المبالغ (اختياري) - SECURITY: Validate numeric inputs
        if ($min = $request->input('min_total')) {
            $min = filter_var($min, FILTER_VALIDATE_FLOAT);
            if ($min !== false) {
                $query->where('cashier_shifts.total_sales', '>=', $min);
            }
        }

        if ($max = $request->input('max_total')) {
            $max = filter_var($max, FILTER_VALIDATE_FLOAT);
            if ($max !== false) {
                $query->where('cashier_shifts.total_sales', '<=', $max);
            }
        }
    }
}
