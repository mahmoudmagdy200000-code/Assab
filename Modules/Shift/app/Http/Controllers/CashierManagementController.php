<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Http\Requests\CheckCashierEmailRequest;
use Modules\Shift\Services\ShiftService;
use Carbon\Carbon;

class CashierManagementController extends BaseController
{
    public function __construct(
        private ShiftService $shiftService
    ) {}

    /**
     * Section 3.1.2.1.1: Cashiers Listing
     * عرض قائمة الكاشيرز مع تفاصيلهم
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();
            $branchId = $manager->branch_id;

            if (!$branchId) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }

            $query = Cashier::with(['branch', 'creator', 'shifts.shift'])
                ->where('branch_id', $branchId);

            // Search by cashier name
            if ($search = $request->input('search')) {
                $query->where('name', 'like', "%{$search}%");
            }

            // Filter by status
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            // Filter by date added
            if ($dateFilter = $request->input('date_filter')) {
                switch ($dateFilter) {
                    case 'last_24_hours':
                        $query->where('created_at', '>=', now()->subDay());
                        break;
                    case 'last_7_days':
                        $query->where('created_at', '>=', now()->subDays(7));
                        break;
                    case 'last_30_days':
                        $query->where('created_at', '>=', now()->subDays(30));
                        break;
                    case 'last_1_year':
                        $query->where('created_at', '>=', now()->subYear());
                        break;
                }
            }

            // Get shifts count per cashier using CashierShift model
            $cashiers = $query->paginate($request->input('per_page', 15));

            // Group by branch name
            $totalByBranch = Cashier::where('branch_id', $branchId)
                ->selectRaw('branch_id, COUNT(*) as total')
                ->groupBy('branch_id')
                ->with('branch:id,name')
                ->first();

            $transformedCashiers = $cashiers->getCollection()->map(function ($cashier) {
                // Get number of shifts per day
                $shiftsPerDay = CashierShift::where('cashier_id', $cashier->id)
                    ->whereDate('shift_date', today())
                    ->count();

                // Working shifts: distinct Shift templates assigned to this cashier (same format as show())
                $workingShifts = $cashier->shifts
                    ->pluck('shift')
                    ->filter()
                    ->unique('id')
                    ->values()
                    ->map(fn($shift) => [
                        'id' => $shift->id,
                        'name' => $shift->name,
                        'start_time' => $shift->start_time?->format('H:i'),
                        'end_time' => $shift->end_time?->format('H:i'),
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => $cashier->id,
                    'name' => $cashier->name,
                    'email' => $cashier->email,
                    'image' => $cashier->image ? asset('storage/' . $cashier->image) : null,
                    'store_branch_name' => $cashier->branch->name ?? 'N/A',
                    'store_branch_id' => $cashier->branch_id,
                    'created_by_name' => $cashier->creator?->name ?? 'N/A',
                    'number_of_shifts_per_day' => $shiftsPerDay,
                    'working_shifts' => $workingShifts,
                    'status' => $cashier->status,
                    'status_label' => $cashier->status_label ?? match ($cashier->status) {
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'deactivated' => 'Deactivated',
                        default => 'Unknown',
                    },
                    'created_at' => $cashier->created_at->format('Y-m-d H:i:s'),
                ];
            });

            $cashiers->setCollection($transformedCashiers);

            return $this->paginatedResponse(
                $cashiers,
                'Cashiers retrieved successfully',
                [
                    'summary' => [
                        'total_cashiers' => $totalByBranch->total ?? 0,
                        'branch_name' => $totalByBranch->branch->name ?? 'N/A',
                        'grouped_by_branch' => [
                            'branch_id' => $branchId,
                            'branch_name' => $totalByBranch->branch->name ?? 'N/A',
                            'total_cashiers' => $totalByBranch->total ?? 0,
                        ],
                    ],
                ]
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Check if cashier email is already registered in the system.
     * التحقق من تسجيل إيميل الكاشير مسبقاً
     */
    public function checkEmail(CheckCashierEmailRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        $exists = Cashier::where('email', $email)->exists();

        return $this->successResponse([
            'email' => $email,
            'registered' => $exists,
            'message' => $exists
                ? 'This email is already registered as a cashier.'
                : 'This email is not registered yet.',
        ], $exists ? 'Cashier already registered' : 'Email available');
    }

    /**
     * Section 3.1.2.1.1.1: Manage Cashiers
     * إضافة كاشير جديد مع تعيين الشيفتات
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:cashiers,email',
            'store_branch_id' => 'required|exists:branches,id',
            'shift_ids' => 'required|array|min:1',
            'shift_ids.*' => 'exists:shifts,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            // Check if shifts are already occupied
            $occupiedShifts = $this->checkOccupiedShifts($request->shift_ids, $request->store_branch_id);
            if (!empty($occupiedShifts)) {
                return $this->errorResponse(
                    'Some shifts are already occupied by other cashiers: ' . implode(', ', $occupiedShifts),
                    400
                );
            }

            // Create cashier (using CashierService if available)
            $cashierService = app(\Modules\Cashier\Services\CashierService::class);
            $cashier = $cashierService->createCashier([
                'name' => $request->name,
                'email' => $request->email,
                'branch_id' => $request->store_branch_id,
                'created_by' => $manager->id,
                'shift_ids' => $request->shift_ids,
            ]);

            // Get assigned shifts details
            $assignedShifts = Shift::whereIn('id', $request->shift_ids)
                ->where('branch_id', $request->store_branch_id)
                ->get()
                ->map(function ($shift) {
                    return [
                        'id' => $shift->id,
                        'name' => $shift->name,
                        'start_time' => $shift->start_time?->format('H:i'),
                        'end_time' => $shift->end_time?->format('H:i'),
                    ];
                });

            return $this->createdResponse([
                'cashier' => [
                    'id' => $cashier->id,
                    'name' => $cashier->name,
                    'email' => $cashier->email,
                    'role' => 'Cashier',
                    'store_branch_name' => $cashier->branch->name ?? 'N/A',
                    'store_branch_id' => $cashier->branch_id,
                    'status' => $cashier->status,
                ],
                'assigned_shifts' => $assignedShifts,
                'summary' => [
                    'cashier_information' => [
                        'name' => $cashier->name,
                        'email' => $cashier->email,
                        'role' => 'Cashier',
                        'store_branch_name' => $cashier->branch->name ?? 'N/A',
                        'store_branch_id' => $cashier->branch_id,
                    ],
                    'working_shifts' => $assignedShifts,
                ],
                'activation_link_sent' => true,
                'message' => 'Cashier account created successfully. Activation link sent to cashier email.',
            ], 'Cashier created successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section 3.1.2.1.1.4: View Detailed Cashier Information
     * عرض تفاصيل الكاشير مع شيفتاته
     */
    public function show(string $cashier): JsonResponse
    {
        try {
            $manager = auth()->user();
            $branchId = $manager->branch_id;

            $cashierModel = Cashier::with(['branch', 'creator'])
                ->where('branch_id', $branchId)
                ->findOrFail($cashier);

            // Get shifts for this cashier
            $shifts = Shift::whereHas('cashierShifts', function ($q) use ($cashier) {
                $q->where('cashier_id', $cashier);
            })
                ->where('branch_id', $branchId)
                ->get()
                ->map(function ($shift) {
                    return [
                        'id' => $shift->id,
                        'name' => $shift->name,
                        'start_time' => $shift->start_time?->format('H:i'),
                        'end_time' => $shift->end_time?->format('H:i'),
                    ];
                });

            // Get pending, in-progress, and completed shifts
            $pendingShifts = $this->getCashierShiftsByStatus($cashier, ShiftStatus::NOT_STARTED);
            $inProgressShifts = $this->getCashierShiftsByStatus($cashier, ShiftStatus::IN_PROGRESS);
            $completedShifts = $this->getCashierShiftsByStatus($cashier, ShiftStatus::COMPLETED);

            return $this->successResponse([
                'cashier' => [
                    'id' => $cashierModel->id,
                    'name' => $cashierModel->name,
                    'email' => $cashierModel->email,
                    'image' => $cashierModel->image ? asset('storage/' . $cashierModel->image) : null,
                    'status' => $cashierModel->status,
                    'status_label' => $cashierModel->status_label,
                    'role' => 'Cashier',
                    'store_branch_name' => $cashierModel->branch->name ?? 'N/A',
                    'working_shifts' => $shifts,
                ],
                'shifts' => [
                    'pending' => $pendingShifts,
                    'in_progress' => $inProgressShifts,
                    'completed' => $completedShifts,
                ],
            ], 'Cashier details retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section 3.1.2.1.1.1: Update Cashier
     * تحديث معلومات الكاشير والشيفتات
     */
    public function update(Request $request, string $cashier): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:cashiers,email,' . $cashier,
            'store_branch_id' => 'sometimes|exists:branches,id',
            'status' => 'sometimes|in:active,deactivated',
            'shift_ids' => 'sometimes|array|min:1',
            'shift_ids.*' => 'exists:shifts,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();
            $cashierModel = Cashier::where('branch_id', $manager->branch_id)
                ->findOrFail($cashier);

            // Update cashier information
            $updateData = [];
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }
            if ($request->has('email')) {
                $updateData['email'] = $request->email;
            }
            if ($request->has('store_branch_id')) {
                $updateData['branch_id'] = $request->store_branch_id;
            }
            if ($request->has('status')) {
                $updateData['status'] = $request->status;
            }

            if (!empty($updateData)) {
                $cashierModel->update($updateData);
            }

            // Update shifts if provided
            if ($request->has('shift_ids')) {
                // Check if shifts are already occupied
                $occupiedShifts = $this->checkOccupiedShifts($request->shift_ids, $cashierModel->branch_id, $cashier);
                if (!empty($occupiedShifts)) {
                    return $this->errorResponse(
                        'Some shifts are already occupied by other cashiers: ' . implode(', ', $occupiedShifts),
                        400
                    );
                }

                $cashierService = app(\Modules\Cashier\Services\CashierService::class);
                $cashierService->updateCashierShifts($cashier, $request->shift_ids);
            }

            $cashierModel->refresh();
            $cashierModel->load(['branch', 'creator']);

            return $this->successResponse([
                'cashier' => [
                    'id' => $cashierModel->id,
                    'name' => $cashierModel->name,
                    'email' => $cashierModel->email,
                    'status' => $cashierModel->status,
                    'status_label' => $cashierModel->status_label,
                    'role' => 'Cashier',
                    'store_branch_name' => $cashierModel->branch->name ?? 'N/A',
                ],
            ], 'Cashier updated successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section 3.1.2.1.1.2: Search Cashiers
     * البحث عن الكاشيرز بالاسم
     */
    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:2',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();
            $branchId = $manager->branch_id;

            $cashiers = Cashier::where('branch_id', $branchId)
                ->where('name', 'like', "%{$request->query}%")
                ->with('branch:id,name,location')
                ->paginate($request->input('per_page', 15));

            return $this->paginatedResponse(
                $cashiers->through(function ($cashier) {
                    return [
                        'id' => $cashier->id,
                        'name' => $cashier->name,
                        'email' => $cashier->email,
                        'image' => $cashier->image ? asset('storage/' . $cashier->image) : null,
                        'store_branch_name' => $cashier->branch->name ?? 'N/A',
                        'status' => $cashier->status,
                        'status_label' => $cashier->status_label,
                    ];
                }),
                'Search results retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section 3.1.2.1.1.3: Filter Cashiers
     * فلترة الكاشيرز حسب التاريخ والحالة
     */
    public function filter(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();
            $branchId = $manager->branch_id;

            $query = Cashier::where('branch_id', $branchId);

            // Filter by date added
            if ($dateFilter = $request->input('date_filter')) {
                switch ($dateFilter) {
                    case 'last_24_hours':
                        $query->where('created_at', '>=', now()->subDay());
                        break;
                    case 'last_7_days':
                        $query->where('created_at', '>=', now()->subDays(7));
                        break;
                    case 'last_30_days':
                        $query->where('created_at', '>=', now()->subDays(30));
                        break;
                    case 'last_1_year':
                        $query->where('created_at', '>=', now()->subYear());
                        break;
                }
            }

            // Filter by status (multiple selection support)
            if ($statuses = $request->input('status')) {
                if (is_array($statuses)) {
                    $query->whereIn('status', $statuses);
                } else {
                    $query->where('status', $statuses);
                }
            }

            $cashiers = $query->with(['branch'])
                ->paginate($request->input('per_page', 15));

            return $this->paginatedResponse(
                $cashiers->through(function ($cashier) {
                    return [
                        'id' => $cashier->id,
                        'name' => $cashier->name,
                        'email' => $cashier->email,
                        'image' => $cashier->image ? asset('storage/' . $cashier->image) : null,
                        'store_branch_name' => $cashier->branch->name ?? 'N/A',
                        'status' => $cashier->status,
                        'status_label' => $cashier->status_label,
                        'created_at' => $cashier->created_at->format('Y-m-d H:i:s'),
                    ];
                }),
                'Filtered cashiers retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Helper: Check if shifts are already occupied
     * يتحقق من أن الشيفتات غير متخذة لكاشير آخر في نفس البرانش
     * نفس الشيفت يمكن أن يكون لكاشيرين مختلفين في برانشات مختلفة
     */
    private function checkOccupiedShifts(array $shiftIds, string $branchId, ?string $excludeCashierId = null): array
    {
        $occupied = [];

        foreach ($shiftIds as $shiftId) {
            // التحقق من أن الشيفت متخذ لكاشير آخر في نفس البرانش فقط
            $query = CashierShift::where('shift_id', $shiftId)
                ->whereHas('cashier', function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId);
                })
                ->whereDate('shift_date', '>=', today())
                ->where('status', '!=', ShiftStatus::COMPLETED->value);

            if ($excludeCashierId) {
                $query->where('cashier_id', '!=', $excludeCashierId);
            }

            $existing = $query->first();
            if ($existing) {
                $shift = Shift::find($shiftId);
                $occupied[] = $shift->name ?? "Shift #{$shiftId}";
            }
        }

        return $occupied;
    }

    /**
     * Get available cashiers for shift assignment/reassignment
     * جلب الكاشيرز المتاحين للتعيين أو إعادة التعيين
     */
    public function getAvailableCashiers(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();
            $branchId = $manager->branch_id;

            if (!$branchId) {
                return $this->errorResponse('Branch manager is not assigned to any branch', 400);
            }

            // Get shift_id and shift_date from query parameters (optional)
            $shiftId = $request->input('shift_id');
            $shiftDate = $request->input('shift_date', today()->format('Y-m-d'));

            // If shift_id is provided, filter out busy cashiers
            $busyCashierIds = [];
            if ($shiftId) {
                $shiftModel = CashierShift::with([
                    'shift' => function ($q) {
                        $q->select(['id', 'name', 'branch_id']);
                    }
                ])->find($shiftId);

                if ($shiftModel) {
                    // Get cashiers who are already working on this date/shift
                    $busyCashierIds = CashierShift::where('shift_date', $shiftDate)
                        ->where('shift_id', $shiftModel->shift_id)
                        ->where('id', '!=', $shiftId)
                        ->whereIn('status', [ShiftStatus::NOT_STARTED->value, ShiftStatus::IN_PROGRESS->value, ShiftStatus::REASSIGNED->value])
                        ->pluck('cashier_id')
                        ->toArray();
                }
            }

            // Get all active cashiers for this branch with pagination
            $query = Cashier::where('branch_id', $branchId)
                ->where('status', 'active')
                ->select(['id', 'name', 'email', 'image', 'branch_id', 'status']);

            // Paginate the results
            $cashiers = $query->paginate($request->input('per_page', 15));

            // Calculate total counts before pagination
            $totalCount = Cashier::where('branch_id', $branchId)
                ->where('status', 'active')
                ->count();

            $availableCount = Cashier::where('branch_id', $branchId)
                ->where('status', 'active')
                ->whereNotIn('id', $busyCashierIds)
                ->count();

            // Transform and filter available cashiers
            $transformedCashiers = $cashiers->through(function ($cashier) use ($busyCashierIds) {
                $isAvailable = !in_array($cashier->id, $busyCashierIds);

                return [
                    'id' => $cashier->id,
                    'name' => $cashier->name,
                    'email' => $cashier->email,
                    'image' => $cashier->image ? asset('storage/' . $cashier->image) : null,
                    'is_available' => $isAvailable,
                    'disabled' => !$isAvailable,
                    'reason_disabled' => !$isAvailable ? 'Already assigned to this shift' : null,
                ];
            });

            // Get the response from paginatedResponse
            $response = $this->paginatedResponse(
                $transformedCashiers,
                'Available cashiers retrieved successfully'
            );

            // Add additional metadata to the response
            $responseData = json_decode($response->getContent(), true);
            $responseData['meta']['total_count'] = $totalCount;
            $responseData['meta']['available_count'] = $availableCount;

            return response()->json($responseData, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Helper: Get cashier shifts by status
     */
    private function getCashierShiftsByStatus(string $cashierId, ShiftStatus $status, int $limit = 10): array
    {
        $query = CashierShift::where('cashier_id', $cashierId)
            ->where('status', $status)
            ->with(['shift', 'nextCashier', 'assignedBy']);

        if ($status === ShiftStatus::NOT_STARTED) {
            // For pending shifts: minimum 1 week, maximum 1 month
            $query->whereDate('shift_date', '>=', now())
                ->whereDate('shift_date', '<=', now()->addMonth())
                ->orderBy('shift_date')
                ->limit($limit);
        } elseif ($status === ShiftStatus::IN_PROGRESS) {
            // For in-progress shifts: today only
            $query->whereDate('shift_date', today())
                ->orderBy('actual_start_time')
                ->limit($limit);
        } else {
            // For completed shifts: sorted by date descending
            $query->orderBy('shift_date', 'desc')
                ->orderBy('actual_end_time', 'desc')
                ->limit($limit);
        }

        return $query->get()->map(function ($shift) {
            return [
                'id' => $shift->id,
                'date' => $shift->shift_date->format('Y-m-d'),
                'status' => $shift->status->value,
                'start_time' => $shift->shift->start_time?->format('H:i') ?? 'N/A',
                'end_time' => $shift->shift->end_time?->format('H:i') ?? 'N/A',
                'opening_balance' => (float) ($shift->opening_balance ?? 0),
                'assigned_to' => $shift->cashier->name,
                'next_cashier' => $shift->nextCashier?->name ?? 'N/A',
                'assigned_by' => $shift->assignedBy?->name ?? 'N/A',
                'assigned_by_user_type' => $shift->assigned_by ? 'branch_manager' : null,
            ];
        })->toArray();
    }
}
