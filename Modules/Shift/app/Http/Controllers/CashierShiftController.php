<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Transformers\CashierShiftResource;
use Modules\Shift\Transformers\ShiftDetailResource;
use Modules\Shift\Enums\ShiftStatus;
use Carbon\Carbon;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Transformers\BranchManagerResource;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Transformers\CashierResource;

/**
 * CashierShiftController
 *
 * Handles all shift operations for Cashiers (Section 3.2.2)
 */
class CashierShiftController extends BaseController
{
    public function __construct(
        private ShiftService $shiftService,
        private HandoverService $handoverService
    ) {}

    /**
     * Section 3.2.2.1.1: Shifts Overview
     * Display all assigned shifts with weekly & daily summary
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $cashier = auth()->user();

            $query = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                },
                'shift.branch:id,name,location',
                'nextCashier:id,name',
                'assignedBy:id,name'
            ])
                ->where('cashier_id', $cashier->id)
                ->orderBy('shift_date', 'desc')
                ->orderBy('created_at', 'desc');

            // Filter by status
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            // Filter by date range
            if ($dateFrom = $request->input('date_from')) {
                $query->whereDate('shift_date', '>=', $dateFrom);
            }
            if ($dateTo = $request->input('date_to')) {
                $query->whereDate('shift_date', '<=', $dateTo);
            }

            $shifts = $query->paginate($request->input('per_page', 15));

            // Weekly summary (optimized - use same query structure)
            $weekStart = Carbon::now()->startOfWeek();
            $weekEnd = Carbon::now()->endOfWeek();
            $weeklyShifts = CashierShift::where('cashier_id', $cashier->id)
                ->whereBetween('shift_date', [$weekStart, $weekEnd])
                ->count();

            // Today's shifts with real-time status (optimized eager loading)
            $todayShifts = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                },
                'shift.branch:id,name,location',
                'nextCashier:id,name',
                'handoverStatus'
            ])
                ->where('cashier_id', $cashier->id)
                ->whereDate('shift_date', today())
                ->orderBy('created_at')
                ->get()
                ->map(function ($shift) {
                    return [
                        'id' => $shift->id,
                        'shift_name' => $shift->shift->name ?? 'N/A',
                        'shift_date' => $shift->shift_date->format('Y-m-d'),
                        'start_time' => $shift->shift->start_time?->format('H:i') ?? 'N/A',
                        'end_time' => $shift->shift->end_time?->format('H:i') ?? 'N/A',
                        'status' => $shift->status->value,
                        'status_label' => $this->getStatusLabel($shift->status),
                        'branch_name' => $shift->shift->branch->name ?? 'N/A',
                        'branch_id' => $shift->shift->branch_id,
                        'branch_location' => $shift->shift->branch->location ?? 'N/A',
                        'duration' => $this->calculateDuration($shift),
                        'can_start' => $shift->status === ShiftStatus::NOT_STARTED && $shift->shift_date->isToday(),
                        'can_end' => $shift->status === ShiftStatus::IN_PROGRESS,
                        'can_handover' => $shift->status === ShiftStatus::IN_PROGRESS ||
                            ($shift->total_sales > 0 && !$shift->handoverStatus?->isManagerApproved()),
                    ];
                });

            // Transform shifts for response
            $transformedShifts = $shifts->getCollection()->map(function ($shift) {
                return [
                    'id' => $shift->id,
                    'shift_date' => $shift->shift_date->format('Y-m-d'),
                    'shift_name' => $shift->shift->name ?? 'N/A',
                    'start_time' => $shift->shift->start_time?->format('H:i') ?? 'N/A',
                    'end_time' => $shift->shift->end_time?->format('H:i') ?? 'N/A',
                    'duration' => $this->calculateDuration($shift),
                    'branch_location' => $shift->shift->branch->name ?? 'N/A',
                    'status' => $shift->status->value,
                    'status_label' => $this->getStatusLabel($shift->status),
                    'assigned_by' => $shift->assignedBy?->name ?? 'N/A',
                    'assigned_by_user_type' => $shift->assigned_by ? 'branch_manager' : null,
                ];
            });

            $shifts->setCollection($transformedShifts);

            return $this->paginatedResponse(
                $shifts,
                'Shifts retrieved successfully',
                [
                    'summary' => [
                        'total_shifts_this_week' => $weeklyShifts,
                        'today_shifts' => $todayShifts,
                        'today_shifts_count' => $todayShifts->count(),
                    ],
                    'available_actions' => [
                        'start_shift' => true,
                        'end_shift' => true,
                        'manage_handover' => true,
                        'view_detailed_shift_information' => true,
                    ],
                ]
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get pending shifts
     */
    public function pendingShifts(Request $request): JsonResponse
    {
        try {
            $cashier = auth()->user();

            // Same date range as manager pending: last month to next month (so past not_started shifts are visible)
            $minDate = now()->subMonth();
            $maxDate = now()->addMonth();

            $shifts = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                },
                'shift.branch:id,name',
                'nextCashier:id,name',
                'assignedBy:id,name',
                'handover',
                'originalCashier:id,name',
                'reassignedBy:id,name',
                'handoverStatus',
            ])
                ->where('cashier_id', $cashier->id)
                ->where('status', ShiftStatus::NOT_STARTED)
                ->whereDate('shift_date', '>=', $minDate)
                ->whereDate('shift_date', '<=', $maxDate)
                ->orderBy('shift_date')
                ->orderByRaw('(SELECT start_time FROM shifts WHERE shifts.id = cashier_shifts.shift_id)')
                ->paginate($request->input('per_page', 15));

            $transformedShifts = $shifts->getCollection()->map(function ($shift, $index) {
                $isFirstShift = $index === 0;
                $handoverTo = $shift->handover?->handoverTo;
                $handoverToName = $handoverTo?->name ?? $shift->nextCashier?->name ?? 'Auto-assigned';
                $handoverToId = $shift->handover?->handover_to_id ?? $shift->next_cashier_id;
                $handoverToType = $shift->handover?->handover_to_type ?? 'cashier';

                return [
                    'id' => $shift->id,
                    'date' => $shift->shift_date->format('Y-m-d'),
                    'status' => $shift->status->value,
                    'status_label' => 'Not Started',
                    'start_time' => $shift->shift->start_time?->format('H:i') ?? 'N/A',
                    'end_time' => $shift->shift->end_time?->format('H:i') ?? 'N/A',
                    'opening_balance' => (float) ($shift->opening_balance ?? 0),
                    'cash_given' => (float) ($shift->opening_balance ?? 0),
                    'variance' => (float) ($shift->variance ?? 0),
                    'cash_from' => $shift->assignedBy ? [
                        'id' => $shift->assignedBy->id,
                        'name' => $shift->assignedBy->name,
                        'user_type' => 'branch_manager',
                    ] : null,
                    'handover_to' => [
                        'id' => $handoverToId,
                        'name' => $handoverToName,
                        'type' => $handoverToType,
                    ],
                    'assigned_to' => $shift->cashier->name ?? 'N/A',
                    'next_cashier' => $shift->nextCashier?->name ?? 'Auto-assigned',
                    'assigned_by' => $shift->assignedBy?->name ?? 'Branch Manager',
                    'assigned_by_user_type' => $shift->assigned_by ? 'branch_manager' : null,
                    'is_next_shift' => $isFirstShift,
                    'can_start' => $shift->shift_date->isToday(),
                    'actions' => [
                        'view_details' => true,
                        'reassign_shift' => true,
                    ],
                ];
            });

            $shifts->setCollection($transformedShifts);

            return $this->paginatedResponse($shifts, 'Pending shifts retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get in-progress shifts (recent window: last 7 days to catch any active shift)
     */
    public function inProgressShifts(Request $request): JsonResponse
    {
        try {
            $cashier = auth()->user();

            $shifts = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                },
                'shift.branch:id,name',
                'nextCashier:id,name',
                'assignedBy:id,name',
                'handoverStatus'
            ])
                ->where('cashier_id', $cashier->id)
                ->where('status', ShiftStatus::IN_PROGRESS)
                ->whereDate('shift_date', '>=', now()->subDays(7))
                ->whereDate('shift_date', '<=', now()->addDay())
                ->orderBy('actual_start_time')
                ->get()
                ->map(function ($shift) {
                    $progress = $this->calculateProgress($shift);

                    return [
                        'id' => $shift->id,
                        'date' => $shift->shift_date->format('Y-m-d'),
                        'status' => 'In Progress',
                        'start_time' => $shift->shift->start_time?->format('H:i') ?? 'N/A',
                        'end_time' => $shift->shift->end_time?->format('H:i') ?? 'N/A',
                        'actual_start_time' => $shift->actual_start_time?->format('H:i:s'),
                        'opening_balance' => $shift->opening_balance > 0
                            ? (float) $shift->opening_balance
                            : 'Not yet recorded',
                        'closing_balance' => (float) ($shift->closing_balance ?? 0),
                        'assigned_to' => $shift->cashier->name ?? 'N/A',
                        'next_cashier' => $shift->nextCashier?->name ?? 'Auto-assigned',
                        'assigned_by' => $shift->assignedBy?->name ?? 'Branch Manager',
                        'assigned_by_user_type' => $shift->assigned_by ? 'branch_manager' : null,
                        'progress' => $progress,
                        'actions' => [
                            'view_details' => true,
                            'end_shift' => true,
                        ],
                    ];
                });

            return $this->successResponse([
                'shifts' => $shifts,
                'count' => $shifts->count(),
            ], 'In-progress shifts retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section 3.2.2.1.1.1: Start Shift
     */
    public function startShift(Request $request, string $shift): JsonResponse
    {
        try {
            $cashier = auth()->user();

            $shiftModel = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                },
                'shift.branch:id,name',
                'cashier:id,name',
                'nextCashier:id,name'
            ])
                ->where('cashier_id', $cashier->id)
                ->findOrFail($shift);

            if ($shiftModel->status !== ShiftStatus::NOT_STARTED && $shiftModel->status !== ShiftStatus::REASSIGNED) {
                return $this->errorResponse('Shift has already been started or is not in pending status', 400);
            }

            if (!$shiftModel->shift_date->isToday()) {
                return $this->errorResponse('You can only start shifts scheduled for today', 400);
            }

            // Start the shift
            $shiftModel->startShift();
            $shiftModel->loadFullRelationships();

            $progress = $this->calculateProgress($shiftModel);

            return $this->successResponse([
                'shift' => new ShiftDetailResource($shiftModel),
                'message' => 'Shift started successfully',
                'progress' => [
                    'status' => 'In Progress',
                    'start_time' => $shiftModel->actual_start_time->format('H:i:s'),
                    'elapsed_time' => 0,
                    'remaining_time' => $this->calculateRemainingTime($shiftModel),
                    'progress_percentage' => $progress['progress_percentage'],
                ],
            ], 'Shift started successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section 3.2.2.1.1.2: View Shift Details
     */
    public function show(string $shift): JsonResponse
    {
        try {
            $cashier = auth()->user();

            $shiftModel = CashierShift::with([
                'cashier',
                'shift.branch',
                'nextCashier',
                'assignedBy',
                'originalCashier',
                'reassignedBy',
                'handover.handoverTo',
                'salesBreakdown.aggregator',
                'handoverStatus.reviewedBy',
                'varianceDetails.responsibleCashier',
                'history',
            ])
                ->where('cashier_id', $cashier->id)
                ->findOrFail($shift);

            return $this->successResponse(
                new CashierShiftResource($shiftModel),
                'Cashier shift retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Accept a shift that was reassigned to the current cashier by a manager.
     * POST /cashier/shifts/{shift}/reassign/accept
     */
    public function acceptReassignedShift(string $shift): JsonResponse
    {
        try {
            $cashier = auth()->user();

            $shiftModel = CashierShift::with(['handoverStatus', 'shift', 'cashier', 'originalCashier', 'reassignedBy'])
                ->where('cashier_id', $cashier->id)
                ->findOrFail($shift);

            $this->handoverService->acceptReassignedShift($shiftModel, $cashier->id);

            $shiftModel->loadFullRelationships();

            return $this->successResponse([
                'shift' => new ShiftDetailResource($shiftModel->fresh()),
            ], 'Shift accepted successfully. You can start it when scheduled.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Shift not found or not assigned to you.', 404);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Reject a shift that was reassigned to the current cashier by a manager.
     * The shift is reverted to the original cashier.
     * POST /cashier/shifts/{shift}/reassign/reject
     */
    public function rejectReassignedShift(Request $request, string $shift): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
            'rejection_files' => 'sometimes|array',
            'rejection_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',
        ]);

        try {
            $cashier = auth()->user();

            $shiftModel = CashierShift::with(['handoverStatus', 'shift', 'cashier', 'originalCashier', 'reassignedBy'])
                ->where('cashier_id', $cashier->id)
                ->findOrFail($shift);

            $files = $request->hasFile('rejection_files') ? $request->file('rejection_files') : [];

            $this->handoverService->rejectReassignedShift(
                $shiftModel,
                $cashier->id,
                $request->input('reason'),
                $files
            );

            return $this->successResponse([
                'message' => 'Shift rejected. It has been reverted to the original cashier.',
            ], 'Reassigned shift rejected successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Shift not found or not assigned to you.', 404);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section 3.2.2.1.2: Shift History - Completed Shifts
     */
    public function completedShifts(Request $request): JsonResponse
    {
        try {
            $cashier = auth()->user();

            $query = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                },
                'shift.branch:id,name',
                'nextCashier:id,name',
                'assignedBy:id,name',
                'salesBreakdown:id,cashier_shift_id,aggregator_id,amount',
                'salesBreakdown.aggregator:id,name',
                'handover.handoverTo',
                'handoverStatus.reviewedBy',
            ])
                ->where('cashier_id', $cashier->id)
                ->where('status', ShiftStatus::COMPLETED)
                ->orderBy('shift_date', 'desc')
                ->orderBy('actual_end_time', 'desc');

            // Filter by date range (default last_30_days so cashier sees more completed shifts)
            $dateFilter = $request->input('date_filter', 'last_30_days');
            switch ($dateFilter) {
                case 'last_7_days':
                    $query->where('shift_date', '>=', now()->subDays(7));
                    break;
                case 'last_30_days':
                    $query->where('shift_date', '>=', now()->subDays(30));
                    break;
                case 'last_90_days':
                    $query->where('shift_date', '>=', now()->subDays(90));
                    break;
                case 'custom':
                    if ($from = $request->input('date_from')) {
                        $query->whereDate('shift_date', '>=', $from);
                    }
                    if ($to = $request->input('date_to')) {
                        $query->whereDate('shift_date', '<=', $to);
                    }
                    break;
            }

            $shifts = $query->paginate($request->input('per_page', 15));

            $transformedShifts = $shifts->getCollection()->map(function ($shift) {
                $deliveryApps = $shift->salesBreakdown->sum('amount');
                $handoverTo = $shift->handover?->handoverTo;
                $handoverToName = $handoverTo?->name ?? $shift->nextCashier?->name ?? null;
                $handoverToType = $shift->handover?->handover_to_type ?? 'cashier';

                $handoverStatus = $shift->handoverStatus
                    ? [
                        'status' => $shift->handoverStatus->manager_approval_status ?? 'pending',
                        'reviewed_by' => $shift->handoverStatus->reviewedBy?->name,
                    ]
                    : ['status' => 'no_handover', 'reviewed_by' => null];

                $handoverToPayload = $handoverToName
                    ? ['id' => $shift->handover?->handover_to_id ?? $shift->next_cashier_id, 'name' => $handoverToName, 'type' => $handoverToType]
                    : null;

                $handoverApprovedOrRejectedBy = null;
                if ($shift->handoverStatus?->reviewedBy) {
                    $handoverApprovedOrRejectedBy = [
                        'id' => $shift->handoverStatus->reviewed_by_id,
                        'name' => $shift->handoverStatus->reviewedBy->name,
                        'user_type' => $shift->handoverStatus->reviewer_type ?? null,
                        'action' => $shift->handoverStatus->manager_approval_status ?? 'pending',
                    ];
                }

                return [
                    'id' => $shift->id,
                    'shift_date' => $shift->shift_date->format('Y-m-d'),
                    'shift_name' => $shift->shift->name ?? 'N/A',
                    'start_time' => $shift->actual_start_time?->format('H:i') ?? 'N/A',
                    'end_time' => $shift->actual_end_time?->format('H:i') ?? 'N/A',
                    'status' => 'Completed',
                    'closing_balance' => (float) ($shift->closing_balance ?? 0),
                    'total_sales' => (float) ($shift->total_sales ?? 0),
                    'variance' => (float) ($shift->variance ?? 0),
                    'variance_type' => $shift->variance > 0 ? 'Over' : ($shift->variance < 0 ? 'Short' : 'None'),
                    'next_cashier' => $shift->nextCashier ? ['id' => $shift->nextCashier->id, 'name' => $shift->nextCashier->name] : null,
                    'assigned_to' => $shift->cashier ? ['id' => $shift->cashier->id, 'name' => $shift->cashier->name] : null,
                    'handover_status' => $handoverStatus,
                    'handover_to' => $handoverToPayload,
                    'handover_approved_or_rejected_by' => $handoverApprovedOrRejectedBy,
                    'branch_name' => $shift->shift->branch->name ?? 'N/A',
                    'branch_id' => $shift->shift->branch_id,
                    'performance_metrics' => [
                        'total_sales' => (float) ($shift->total_sales ?? 0),
                        'cash_collected' => (float) ($shift->cash_collected ?? 0),
                        'card_payments' => (float) ($shift->card_payments ?? 0),
                        'delivery_apps' => (float) $deliveryApps,
                    ],
                ];
            });

            $shifts->setCollection($transformedShifts);

            return $this->paginatedResponse($shifts, 'Completed shifts retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section 3.2.2.1.2: Shift History - Reassigned Shifts
     */
    public function reassignedShifts(Request $request): JsonResponse
    {
        try {
            $cashier = auth()->user();

            // Reassigned shifts: either taken FROM me (original_cashier_id) or reassigned TO me (cashier_id)
            $shifts = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                },
                'shift.branch:id,name',
                'cashier:id,name',
                'originalCashier:id,name',
                'reassignedBy:id,name',
                'nextCashier:id,name',
                'handover',
                'handoverStatus',
            ])
                ->where('status', ShiftStatus::REASSIGNED)
                ->where(function ($q) use ($cashier) {
                    $q->where('original_cashier_id', $cashier->id)
                        ->orWhere('cashier_id', $cashier->id);
                })
                ->orderBy('reassigned_at', 'desc')
                ->paginate($request->input('per_page', 15));

            $transformedShifts = $shifts->getCollection()->map(function ($shift) use ($cashier) {
                $isMidReassign = $shift->handoverStatus && ($shift->handoverStatus->manager_approval_status ?? '') === 'pending';
                $canBeAccepted = $isMidReassign && $shift->cashier_id === $cashier->id;

                return [
                    'id' => $shift->id,
                    'shift_date' => $shift->shift_date->format('Y-m-d'),
                    'shift_name' => $shift->shift->name ?? 'N/A',
                    'start_time' => $shift->shift->start_time?->format('H:i') ?? 'N/A',
                    'end_time' => $shift->shift->end_time?->format('H:i') ?? 'N/A',
                    'reassigned_at' => $shift->reassigned_at?->format('Y-m-d H:i:s') ?? 'N/A',
                    'reassignment_reason' => $shift->reassignment_reason ?? 'N/A',
                    'closing_balance' => (float) ($shift->closing_balance ?? 0),
                    'variance' => (float) ($shift->variance ?? 0),
                    'reassigned_to' => $shift->cashier ? ['id' => $shift->cashier->id, 'name' => $shift->cashier->name] : null,
                    'reassigned_from' => $shift->originalCashier ? ['id' => $shift->originalCashier->id, 'name' => $shift->originalCashier->name] : null,
                    'reassigned_by' => $shift->reassignedBy ? [
                        'id' => $shift->reassignedBy->id,
                        'name' => $shift->reassignedBy->name,
                        'user_type' => 'branch_manager',
                    ] : null,
                    'cash_given' => (float) ($shift->handover?->handover_amount ?? $shift->opening_balance ?? 0),
                    'next_cashier' => $shift->nextCashier ? ['id' => $shift->nextCashier->id, 'name' => $shift->nextCashier->name] : null,
                    'is_mid_reassign' => $isMidReassign,
                    'can_be_accepted' => $canBeAccepted,
                    'original_cashier' => $shift->originalCashier?->name ?? 'N/A',
                    'branch_name' => $shift->shift->branch->name ?? 'N/A',
                    'branch_id' => $shift->shift->branch_id,
                    'status' => 'Reassigned',
                ];
            });

            $shifts->setCollection($transformedShifts);

            return $this->paginatedResponse($shifts, 'Reassigned shifts retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Shift History with filters
     */
    public function shiftHistory(Request $request): JsonResponse
    {
        try {
            $cashier = auth()->user();

            $query = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                },
                'shift.branch:id,name',
                'nextCashier:id,name',
                'assignedBy:id,name'
            ])
                ->where('cashier_id', $cashier->id)
                ->whereIn('status', [ShiftStatus::COMPLETED, ShiftStatus::REASSIGNED])
                ->orderBy('shift_date', 'desc');

            // Filter by status
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            // Filter by date range
            $dateFilter = $request->input('date_filter', 'last_7_days');
            switch ($dateFilter) {
                case 'last_7_days':
                    $query->where('shift_date', '>=', now()->subDays(7));
                    break;
                case 'last_30_days':
                    $query->where('shift_date', '>=', now()->subDays(30));
                    break;
                case 'custom':
                    if ($from = $request->input('date_from')) {
                        $query->whereDate('shift_date', '>=', $from);
                    }
                    if ($to = $request->input('date_to')) {
                        $query->whereDate('shift_date', '<=', $to);
                    }
                    break;
            }

            $shifts = $query->paginate($request->input('per_page', 15));

            return $this->paginatedResponse(
                ShiftDetailResource::collection($shifts),
                'Shift history retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Weekly Summary
     */
    public function weeklySummary(Request $request): JsonResponse
    {
        try {
            $cashier = auth()->user();
            $weekStart = Carbon::now()->startOfWeek();
            $weekEnd = Carbon::now()->endOfWeek();

            $shifts = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time']);
                }
            ])
                ->where('cashier_id', $cashier->id)
                ->whereBetween('shift_date', [$weekStart, $weekEnd])
                ->get();

            $summary = [
                'week_start' => $weekStart->format('Y-m-d'),
                'week_end' => $weekEnd->format('Y-m-d'),
                'total_shifts' => $shifts->count(),
                'completed_shifts' => $shifts->where('status', ShiftStatus::COMPLETED)->count(),
                'pending_shifts' => $shifts->whereIn('status', [ShiftStatus::NOT_STARTED, ShiftStatus::REASSIGNED])->count(),
                'in_progress_shifts' => $shifts->where('status', ShiftStatus::IN_PROGRESS)->count(),
                'total_sales' => (float) $shifts->where('status', ShiftStatus::COMPLETED)->sum('total_sales'),
                'total_variance' => (float) $shifts->where('status', ShiftStatus::COMPLETED)->sum('variance'),
                'shifts_by_day' => $shifts->groupBy(fn($s) => $s->shift_date->format('l'))
                    ->map(fn($g) => $g->count()),
            ];

            return $this->successResponse($summary, 'Weekly summary retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    private function getStatusLabel(ShiftStatus $status): string
    {
        return match ($status) {
            ShiftStatus::NOT_STARTED => 'Pending',
            ShiftStatus::IN_PROGRESS => 'In Progress',
            ShiftStatus::COMPLETED => 'Completed',
            ShiftStatus::REASSIGNED => 'Reassigned',
            ShiftStatus::CANCELED => 'Canceled',
            default => 'Unknown',
        };
    }

    private function calculateDuration(CashierShift $shift): string
    {
        if (!$shift->shift->start_time || !$shift->shift->end_time) {
            return 'N/A';
        }

        $start = Carbon::parse($shift->shift->start_time);
        $end = Carbon::parse($shift->shift->end_time);
        $hours = $start->diffInHours($end);

        return "{$hours} hours";
    }

    private function calculateRemainingTime(CashierShift $shift): string
    {
        if (!$shift->actual_start_time || !$shift->shift->end_time) {
            return 'N/A';
        }

        $endTime = Carbon::parse($shift->shift->end_time)->setDate(
            now()->year,
            now()->month,
            now()->day
        );

        if (now()->greaterThan($endTime)) {
            return '0 hours';
        }

        $minutes = now()->diffInMinutes($endTime);
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        return "{$hours}h {$mins}m";
    }

    private function calculateProgress(CashierShift $shift): array
    {
        $progressPercentage = 0;
        $elapsedHours = 0;
        $remainingHours = 0;

        if ($shift->actual_start_time && $shift->shift->end_time) {
            $start = $shift->actual_start_time;
            $end = Carbon::parse($shift->shift->end_time)->setDate(
                now()->year,
                now()->month,
                now()->day
            );

            $totalMinutes = $start->diffInMinutes($end);
            $elapsedMinutes = now()->diffInMinutes($start);

            $progressPercentage = $totalMinutes > 0
                ? min(($elapsedMinutes / $totalMinutes) * 100, 100)
                : 0;
            $elapsedHours = round($elapsedMinutes / 60, 2);
            $remainingHours = max(0, round(($totalMinutes - $elapsedMinutes) / 60, 2));
        }

        return [
            'progress_percentage' => round($progressPercentage, 1),
            'elapsed_hours' => $elapsedHours,
            'remaining_hours' => $remainingHours,
        ];
    }


    public function getAllCashiersAndBranchManagerAccount(Request $request)
    {
        try {
            $manager = auth()->user();

            if (!$manager || !$manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            // Load cashiers with relationships and count (optimized)
            $cashiers = Cashier::where('branch_id', $manager->branch_id)
                ->with([
                    'branch:id,name,location',
                    'creator:id,name'
                ])
                ->withCount('shifts')
                ->paginate($request->input('per_page', 10));

            // Load branch managers with relationships (excluding the current manager) - optimized
            $branchManagers = BranchManager::where('branch_id', $manager->branch_id)
                ->where('id', '!=', $manager->id)
                ->with('branch:id,name,location')
                ->select(['id', 'name', 'email', 'phone', 'branch_id', 'is_active', 'status', 'is_first_login', 'image', 'email_verified_at', 'phone_verified_at', 'created_at', 'updated_at'])
                ->get();

            // Create ResourceCollection for cashiers (this preserves pagination)
            $cashiersResource = CashierResource::collection($cashiers);

            // Get paginated response
            $response = $this->paginatedResponse($cashiersResource, 'Cashiers and branch managers retrieved successfully');

            // Add branch_managers to the response data
            $responseData = $response->getData(true);
            $responseData['data'] = [
                'branch_managers' => BranchManagerResource::collection($branchManagers),
                'cashiers' => $responseData['data'], // Keep the paginated cashiers data
            ];

            return response()->json($responseData, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }


    /**
     * Start shift by manager (for cashier)
     * Accepts either shiftId or cashierId - will find pending shift for cashier if cashierId is provided
     */
    public function startShiftByManager($shiftId): JsonResponse
    {
        try {
            $branchManager = auth()->user();

            // First try to find by shift ID (optimized eager loading)
            $shiftModel = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                },
                'shift.branch:id,name',
                'cashier:id,name,branch_id',
                'nextCashier:id,name'
            ])
                ->where('id', $shiftId)
                ->whereHas('shift', function ($query) use ($branchManager) {
                    $query->where('branch_id', $branchManager->branch_id);
                })
                ->whereHas('cashier', function ($query) use ($branchManager) {
                    $query->where('branch_id', $branchManager->branch_id);
                })
                ->first();

            // If not found, assume it's a cashier_id and find pending shift for that cashier
            if (!$shiftModel) {
                $shiftModel = CashierShift::with([
                    'shift' => function ($q) {
                        $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                    },
                    'shift.branch:id,name',
                    'cashier:id,name,branch_id',
                    'nextCashier:id,name'
                ])
                    ->where('cashier_id', $shiftId)
                    ->whereHas('shift', function ($query) use ($branchManager) {
                        $query->where('branch_id', $branchManager->branch_id);
                    })
                    ->whereHas('cashier', function ($query) use ($branchManager) {
                        $query->where('branch_id', $branchManager->branch_id);
                    })
                    ->whereIn('status', [ShiftStatus::NOT_STARTED, ShiftStatus::REASSIGNED])
                    ->whereDate('shift_date', '>=', today())
                    ->orderBy('shift_date')
                    ->first();
            }

            if (!$shiftModel) {
                return $this->errorResponse('Shift not found or no pending shift available for this cashier', 404);
            }

            // Verify the cashier belongs to the branch manager's branch
            if ($shiftModel->cashier->branch_id !== $branchManager->branch_id) {
                return $this->errorResponse('Unauthorized: This cashier does not belong to your branch', 403);
            }

            if ($shiftModel->status !== ShiftStatus::NOT_STARTED && $shiftModel->status !== ShiftStatus::REASSIGNED) {
                return $this->errorResponse('Shift has already been started', 400);
            }

            $shiftModel->startShift();
            $shiftModel->loadFullRelationships();

            return $this->successResponse(
                new ShiftDetailResource($shiftModel),
                'Shift started successfully by branch manager'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
