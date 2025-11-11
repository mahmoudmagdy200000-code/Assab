<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Shift\Services\{ShiftService, ShiftNotificationService};
use Modules\Shift\Transformers\{ShiftResource, ShiftDetailResource};
use Modules\Shift\Models\CashierShift;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;

class ReassignmentShiftController extends Controller
{
    public function __construct(
        private ShiftService $shiftService,
        // private ShiftNotificationService $notificationService
    ) {}

    /**
     * Display a listing of reassigned shifts
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $cashierId = $request->input('cashier_id');

            $shifts = $this->shiftService->getReassignedShifts($cashierId);

            return response()->json([
                'success' => true,
                'message' => 'Reassigned shifts retrieved successfully',
                'data' => ShiftResource::collection($shifts),
                'meta' => [
                    'total' => $shifts->count(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve reassigned shifts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified reassigned shift
     *
     * @param string $shift
     * @return JsonResponse
     */
    public function show(string $shift): JsonResponse
    {
        try {
            $shiftDetails = $this->shiftService->getShiftDetails($shift);

            // Get shift progress
            $progress = $this->shiftService->getShiftProgress($shift);

            return response()->json([
                'success' => true,
                'message' => 'Reassigned shift details retrieved successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($shiftDetails),
                    'progress' => $progress,
                    'reassignment_info' => [
                        'date' => $shiftDetails->reassigned_at?->format('Y-m-d H:i:s'),
                        'reassigned_from' => $shiftDetails->originalCashier?->name,
                        'reassigned_to' => $shiftDetails->cashier->name,
                        'reassigned_by' => $shiftDetails->reassignedBy?->name,
                        'reason' => $shiftDetails->reassignment_reason,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve shift details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reassign a shift to another cashier
     *
     * @param Request $request
     * @param string $shift
     * @return JsonResponse
     */
    public function reassign(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'new_cashier_id' => 'required|exists:cashiers,id',
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $shiftModel = CashierShift::with(['cashier', 'shift'])->findOrFail($shift);

            // Verify shift is pending or not started
            if (!in_array($shiftModel->status->value, ['not_started', 'reassigned'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot reassign a shift that is already in progress or completed',
                ], 400);
            }

            // Verify new cashier is not the same as current
            if ($shiftModel->cashier_id == $request->new_cashier_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot reassign to the same cashier',
                ], 400);
            }

            $newCashier = Cashier::findOrFail($request->new_cashier_id);

            // Check if new cashier is already assigned to this shift
            $conflictingShift = CashierShift::where('cashier_id', $newCashier->id)
                ->where('shift_date', $shiftModel->shift_date)
                ->where('shift_id', $shiftModel->shift_id)
                ->where('status', '!=', ShiftStatus::COMPLETED->value)
                ->first();

            if ($conflictingShift) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected cashier is already assigned to this shift',
                ], 400);
            }

            // Store original cashier if not already set
            $originalCashierId = $shiftModel->original_cashier_id ?? $shiftModel->cashier_id;

            // Update shift
            $shiftModel->update([
                'original_cashier_id' => $originalCashierId,
                'cashier_id' => $request->new_cashier_id,
                'status' => ShiftStatus::REASSIGNED,
                'reassigned_by' => auth()->id(),
                'reassignment_reason' => $request->reason,
                'reassigned_at' => now(),
            ]);

            // Record history
            $shiftModel->history()->create([
                'action' => 'reassigned',
                'performed_by' => auth()->id(),
                'performed_by_type' => 'branch_manager',
                'old_value' => json_encode([
                    'cashier_id' => $originalCashierId,
                    'cashier_name' => Cashier::find($originalCashierId)->name,
                ]),
                'new_value' => json_encode([
                    'cashier_id' => $request->new_cashier_id,
                    'cashier_name' => $newCashier->name,
                    'reason' => $request->reason,
                ]),
                'notes' => 'Shift reassigned by branch manager',
            ]);

            // Send notifications
            // $this->notificationService->notifyShiftReassigned($shiftModel->fresh());

            DB::commit();

            // Return summary
            return response()->json([
                'success' => true,
                'message' => 'Shift reassigned successfully',
                'data' => [
                    'summary' => [
                        'previous_cashier' => Cashier::find($originalCashierId)->name,
                        'next_cashier_selected' => $newCashier->name,
                        'shift_date' => $shiftModel->shift_date->format('Y-m-d'),
                        'start_time' => $shiftModel->shift->start_time,
                        'end_time' => $shiftModel->shift->end_time,
                        'reason' => $request->reason,
                        'reassigned_at' => now()->format('Y-m-d H:i:s'),
                    ],
                    'shift' => new ShiftDetailResource($shiftModel->fresh())
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reassign shift',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available cashiers for reassignment
     *
     * @param Request $request
     * @param string $shift
     * @return JsonResponse
     */
    public function getAvailableCashiers(string $shift): JsonResponse
    {
        try {
            $shiftModel = CashierShift::with('shift')->findOrFail($shift);

            // Get all active cashiers for this branch
            $allCashiers = Cashier::where('branch_id', $shiftModel->shift->branch_id)
                ->where('status', 'active')
                ->get();

            // Get cashiers who are already working on this date/shift
            $busyCashierIds = CashierShift::where('shift_date', $shiftModel->shift_date)
                ->where('shift_id', $shiftModel->shift_id)
                ->where('id', '!=', $shift)
                ->whereIn('status', ['not_started', 'in_progress', 'reassigned'])
                ->pluck('cashier_id')
                ->toArray();

            // Filter available cashiers
            $availableCashiers = $allCashiers->map(function ($cashier) use ($busyCashierIds, $shiftModel) {
                $isAvailable = !in_array($cashier->id, $busyCashierIds)
                    && $cashier->id != $shiftModel->cashier_id;

                return [
                    'id' => $cashier->id,
                    'name' => $cashier->name,
                    'image' => $cashier->image,
                    'is_available' => $isAvailable,
                    'is_current_cashier' => $cashier->id == $shiftModel->cashier_id,
                    'reason_disabled' => !$isAvailable ? 'Already assigned to this shift' : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Available cashiers retrieved successfully',
                'data' => $availableCashiers->values(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available cashiers',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

