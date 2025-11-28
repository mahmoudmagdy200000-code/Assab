<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        // Validate UUID format
        if (!$this->isValidUuid($shift)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid shift ID format',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'new_cashier_id' => 'required|exists:cashiers,id',
            'reason' => 'nullable|string|max:500', // غيرت من required ل nullable
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
            // البحث مرة واحدة فقط داخل ال transaction
            $shiftModel = CashierShift::with(['cashier', 'shift'])->find($shift);

            if (!$shiftModel) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Shift not found',
                    'error' => 'The specified shift does not exist'
                ], 404);
            }

            // Verify shift is pending or not started
            if (!in_array($shiftModel->status->value, ['not_started', 'reassigned'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot reassign a shift that is already in progress or completed',
                ], 400);
            }

            // Verify new cashier is not the same as current
            if ($shiftModel->cashier_id == $request->new_cashier_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot reassign to the same cashier',
                ], 400);
            }

            $newCashier = Cashier::find($request->new_cashier_id);
            if (!$newCashier) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'New cashier not found',
                ], 404);
            }

            // Check if new cashier is already assigned to this shift
            $conflictingShift = CashierShift::where('cashier_id', $newCashier->id)
                ->where('shift_date', $shiftModel->shift_date)
                ->where('shift_id', $shiftModel->shift_id)
                ->where('status', '!=', ShiftStatus::COMPLETED->value)
                ->first();

            if ($conflictingShift) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'The selected cashier is already assigned to this shift',
                ], 400);
            }

            // Store original cashier if not already set
            $originalCashierId = $shiftModel->original_cashier_id ?? $shiftModel->cashier_id;

            // البحث عن الكاشير الأصلي
            $originalCashier = Cashier::find($originalCashierId);
            if (!$originalCashier) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Original cashier not found',
                ], 404);
            }

            // Update shift
            $shiftModel->update([
                'original_cashier_id' => $originalCashierId,
                'cashier_id' => $request->new_cashier_id,
                'status' => ShiftStatus::REASSIGNED,
                'reassigned_by' => auth()->id(),
                'reassignment_reason' => $request->reason, // ممكن يكون null
                'reassigned_at' => now(),
            ]);

            // Record history
            $shiftModel->history()->create([
                'action' => 'reassigned',
                'performed_by' => auth()->id(),
                'performed_by_type' => 'branch_manager',
                'old_value' => json_encode([
                    'cashier_id' => $originalCashierId,
                    'cashier_name' => $originalCashier->name,
                ]),
                'new_value' => json_encode([
                    'cashier_id' => $request->new_cashier_id,
                    'cashier_name' => $newCashier->name,
                    'reason' => $request->reason, // ممكن يكون null
                ]),
                'notes' => $request->reason ? 'Shift reassigned by branch manager: ' . $request->reason : 'Shift reassigned by branch manager',
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
                        'previous_cashier' => $originalCashier->name,
                        'next_cashier_selected' => $newCashier->name,
                        'shift_date' => $shiftModel->shift_date->format('Y-m-d'),
                        'start_time' => $shiftModel->shift->start_time,
                        'end_time' => $shiftModel->shift->end_time,
                        'reason' => $request->reason, // ممكن يكون null
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
     * Check if string is valid UUID
     */
    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
    }
    /**
     * Reassign shift with handover (for in-progress shifts)
     *
     * @param Request $request
     * @param string $shift
     * @return JsonResponse
     */
    public function reassignWithHandover(Request $request, string $shift): JsonResponse
    {
        // Validate UUID format
        if (!$this->isValidUuid($shift)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid shift ID format',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'new_cashier_id' => 'required|exists:cashiers,id',
            'reason' => 'nullable|string|max:500', // غيرت من required ل nullable
            'handover_amount' => 'required|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',

            'current_sales' => 'sometimes|numeric|min:0',
            'cash_collected' => 'sometimes|numeric|min:0',
            'card_payments' => 'sometimes|numeric|min:0',

            'aggregators' => 'sometimes|array',
            'aggregators.*.aggregator_id' => 'required_with:aggregators|exists:aggregators,id',
            'aggregators.*.amount' => 'required_with:aggregators|numeric|min:0',
            'aggregators.*.notes' => 'nullable|string|max:255',

            'pos_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',

            // ---------- Added Variance Validation ----------
            'variance' => 'sometimes|array',
            'variance.responsibility_type' => 'required_with:variance|in:self,self_and_others,other_factors,mixed',
            'variance.current_cashier_amount' => 'required_if:variance.responsibility_type,self_and_others,mixed|numeric|min:0',

            'variance.other_cashiers' => 'sometimes|array',
            'variance.other_cashiers.*.cashier_id' => 'required_with:variance.other_cashiers|exists:cashiers,id',
            'variance.other_cashiers.*.amount' => 'required_with:variance.other_cashiers|numeric|min:0',
            'variance.other_cashiers.*.notes' => 'nullable|string|max:255',

            'variance.reason' => 'required_if:variance.responsibility_type,other_factors,mixed|string|max:500',

            'variance.supporting_files' => 'sometimes|array',
            'variance.supporting_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',
            // ------------------------------------------------
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
            // البحث مرة واحدة فقط
            $shiftModel = CashierShift::with(['cashier', 'shift'])->find($shift);

            if (!$shiftModel) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Shift not found',
                    'error' => 'The specified shift does not exist'
                ], 404);
            }

            if ($shiftModel->status !== ShiftStatus::IN_PROGRESS) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Can only reassign with handover for in-progress shifts',
                ], 400);
            }

            if ($shiftModel->cashier_id == $request->new_cashier_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot reassign to the same cashier',
                ], 400);
            }

            $newCashier = Cashier::find($request->new_cashier_id);
            if (!$newCashier) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'New cashier not found',
                ], 404);
            }

            // Conflict check
            $conflictingShift = CashierShift::where('cashier_id', $newCashier->id)
                ->where('id', '!=', $shift)
                ->whereIn('status', [ShiftStatus::IN_PROGRESS, ShiftStatus::NOT_STARTED])
                ->whereDate('shift_date', $shiftModel->shift_date)
                ->first();

            if ($conflictingShift) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'The selected cashier is already assigned to another active shift',
                ], 400);
            }

            // Store original cashier
            $originalCashierId = $shiftModel->original_cashier_id ?? $shiftModel->cashier_id;
            $originalCashier = Cashier::find($originalCashierId);

            if (!$originalCashier) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Original cashier not found',
                ], 404);
            }

            // Receipt upload
            $posReceiptPath = null;
            if ($request->hasFile('pos_receipt')) {
                $posReceiptPath = $request->file('pos_receipt')->store('handovers/receipts', 'public');
            }

            // Create handover
            $handover = $shiftModel->handoverStatus()->create([
                'handover_amount' => $request->handover_amount,
                'handover_notes' => $request->handover_notes,
                'handover_type' => 'reassignment',
                'handed_over_by' => $shiftModel->cashier_id,
                'handed_over_to' => $request->new_cashier_id,
                'handover_at' => now(),
                'acceptance_status' => 'pending',
                'pos_receipt_path' => $posReceiptPath,
            ]);

            // Store current sales breakdown (optional)
            if ($request->has('current_sales')) {
                $shiftModel->update([
                    'total_sales' => $request->current_sales,
                    'cash_collected' => $request->cash_collected ?? 0,
                    'card_payments' => $request->card_payments ?? 0,
                ]);

                if ($request->has('aggregators')) {
                    foreach ($request->aggregators as $aggregator) {
                        $shiftModel->salesBreakdown()->create([
                            'aggregator_id' => $aggregator['aggregator_id'],
                            'amount' => $aggregator['amount'],
                            'notes' => $aggregator['notes'] ?? null,
                        ]);
                    }
                }
            }

            // ------------------------------------------------
            // ⭐⭐⭐ ADDING FULL VARIANCE STORING HERE ⭐⭐⭐
            // ------------------------------------------------

            if ($request->has('variance')) {
                $varianceInput = $request->variance;

                // 1) Create variance record
                $variance = $shiftModel->varianceDetails()->create([
                    'responsibility_type' => $varianceInput['responsibility_type'],
                    'current_cashier_amount' => $varianceInput['current_cashier_amount'] ?? null,
                    'reason' => $varianceInput['reason'] ?? null,
                ]);

                // 2) Store other cashiers
                if (!empty($varianceInput['other_cashiers'])) {
                    foreach ($varianceInput['other_cashiers'] as $other) {
                        $variance->otherCashiers()->create([
                            'cashier_id' => $other['cashier_id'],
                            'amount' => $other['amount'],
                            'notes' => $other['notes'] ?? null,
                        ]);
                    }
                }

                // 3) Store supporting files
                if (!empty($varianceInput['supporting_files'])) {
                    foreach ($varianceInput['supporting_files'] as $file) {
                        $path = $file->store('variance/files', 'public');
                        $variance->files()->create([
                            'file_path' => $path,
                        ]);
                    }
                }
            }

            // ------------------------------------------------

            // Update shift after reassignment
            $shiftModel->update([
                'original_cashier_id' => $originalCashierId,
                'cashier_id' => $request->new_cashier_id,
                'next_cashier_id' => $request->new_cashier_id,
                'status' => ShiftStatus::REASSIGNED,
                'reassigned_by' => auth()->id(),
                'reassignment_reason' => $request->reason, // ممكن يكون null
                'reassigned_at' => now(),
                'handover_completed' => true,
            ]);

            // Record history
            $shiftModel->history()->create([
                'action' => 'reassigned_with_handover',
                'performed_by' => auth()->id(),
                'performed_by_type' => 'branch_manager',
                'old_value' => json_encode([
                    'cashier_id' => $originalCashierId,
                    'cashier_name' => $originalCashier->name,
                ]),
                'new_value' => json_encode([
                    'cashier_id' => $request->new_cashier_id,
                    'cashier_name' => $newCashier->name,
                    'handover_amount' => $request->handover_amount,
                    'reason' => $request->reason, // ممكن يكون null
                ]),
                'notes' => $request->reason ?
                    'Shift reassigned with handover by branch manager: ' . $request->reason :
                    'Shift reassigned with handover by branch manager',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Shift reassigned successfully with handover',
                'data' => [
                    'summary' => [
                        'previous_cashier' => $originalCashier->name,
                        'next_cashier_selected' => $newCashier->name,
                        'shift_date' => $shiftModel->shift_date->format('Y-m-d'),
                        'start_time' => $shiftModel->shift->start_time,
                        'end_time' => $shiftModel->shift->end_time,
                        'reason' => $request->reason, // ممكن يكون null
                        'reassigned_at' => now()->format('Y-m-d H:i:s'),
                        'handover_details' => [
                            'handover_amount' => (float)$request->handover_amount,
                            'handover_notes' => $request->handover_notes,
                            'current_sales' => (float)($request->current_sales ?? 0),
                            'handover_status' => 'pending_acceptance',
                        ],
                    ],
                    'shift' => new ShiftDetailResource($shiftModel->fresh([
                        'cashier',
                        'shift',
                        'nextCashier',
                        'originalCashier',
                        'reassignedBy',
                        'handoverStatus',
                        'salesBreakdown.aggregator',
                        'varianceDetails.otherCashiers',
                        'varianceDetails.files'
                    ]))
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reassign shift with handover',
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
