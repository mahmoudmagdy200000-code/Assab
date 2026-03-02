<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Shift\Services\{ShiftService, ShiftNotificationService};
use Modules\Shift\Transformers\{ShiftResource, ShiftDetailResource, CashierShiftResource};
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftVarianceDetail;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Enums\VarianceType;
use Modules\Shift\Enums\ResponsibilityType;

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
                'data' => CashierShiftResource::collection($shifts),
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
     * Returns unified shift payload (ShiftDetailResource) with shift_progress and reassignment_info inside.
     *
     * @param string $shift
     * @return JsonResponse
     */
    public function show(string $shift): JsonResponse
    {
        try {
            $shiftDetails = $this->shiftService->getShiftDetails($shift);

            return response()->json([
                'success' => true,
                'message' => 'Reassigned shift details retrieved successfully',
                'data' => new ShiftDetailResource($shiftDetails),
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
            $authUser = auth()->user();

            if (!$authUser || !$authUser->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $isBranchManager = $authUser instanceof \Modules\BranchManagers\Models\BranchManager;
            $branchId = $authUser->branch_id;

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

            // Verify shift belongs to the same branch as the authenticated user
            if ($shiftModel->shift->branch_id !== $branchId) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift does not belong to your branch',
                ], 403);
            }

            // Verify cashier belongs to same branch
            if ($shiftModel->cashier->branch_id !== $branchId) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This cashier does not belong to your branch',
                ], 403);
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

            // reassigned_by FK references branch_managers; set only when auth user is a branch manager
            $reassignedBy = $isBranchManager ? $authUser->id : null;

            // Update shift
            $updateData = [
                'original_cashier_id' => $originalCashierId,
                'cashier_id' => $request->new_cashier_id,
                'status' => ShiftStatus::REASSIGNED,
                'reassigned_by' => $reassignedBy,
                'reassignment_reason' => $request->reason ?? null,
                'reassigned_at' => now(),
            ];

            $shiftModel->update($updateData);

            // Refresh the model to ensure all attributes are loaded
            $shiftModel->refresh();

            $performedByType = $isBranchManager ? 'branch_manager' : 'cashier';

            // Record history
            $shiftModel->history()->create([
                'action' => 'reassigned',
                'performed_by' => $authUser->id,
                'performed_by_type' => $performedByType,
                'old_value' => json_encode([
                    'cashier_id' => $originalCashierId,
                    'cashier_name' => $originalCashier->name,
                ]),
                'new_value' => json_encode([
                    'cashier_id' => $request->new_cashier_id,
                    'cashier_name' => $newCashier->name,
                    'reason' => $request->reason, // ممكن يكون null
                ]),
                'notes' => $request->reason ? 'Shift reassigned: ' . $request->reason : 'Shift reassigned',
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
     * Map request responsibility_type string to ResponsibilityType enum.
     */
    private function normalizeResponsibilityType(string $type): ResponsibilityType
    {
        return match (strtolower($type)) {
            'self' => ResponsibilityType::I_WAS_RESPONSIBLE,
            'self_and_others' => ResponsibilityType::ME_AND_OTHER_FACTORS,
            'other_factors' => ResponsibilityType::OTHER_FACTORS,
            'mixed' => ResponsibilityType::MIXED_FACTORS,
            default => ResponsibilityType::I_WAS_RESPONSIBLE,
        };
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
        $validator->after(function (\Illuminate\Validation\Validator $v) use ($request) {
            $aggs = $request->input('aggregators', []);
            if (empty($aggs)) {
                return;
            }
            $ids = collect($aggs)->pluck('aggregator_id')->filter();
            if ($ids->count() !== $ids->unique()->count()) {
                $v->errors()->add('aggregators', 'Each aggregator can only be selected once per shift.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $manager = auth()->user();
            
            // Ensure the user is a branch manager
            if (!$manager || !$manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }
            
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
            
            // Verify shift belongs to manager's branch
            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift does not belong to your branch',
                ], 403);
            }
            
            // Verify cashier belongs to manager's branch
            if ($shiftModel->cashier->branch_id !== $manager->branch_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This cashier does not belong to your branch',
                ], 403);
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
            
            // Verify new cashier belongs to manager's branch
            if ($newCashier->branch_id !== $manager->branch_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: The selected cashier does not belong to your branch',
                ], 403);
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
                    $shiftModel->salesBreakdown()->delete();
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
            // Variance: align with shift_variance_details schema (variance_amount, variance_type, etc.)
            // ------------------------------------------------

            if ($request->has('variance')) {
                $shiftModel->refresh();
                $signedVariance = $shiftModel->calculateVariance();
                $varianceAmount = abs($signedVariance);
                $varianceType = $signedVariance >= 0 ? VarianceType::OVER : VarianceType::SHORT;

                $shiftModel->update(['variance' => $signedVariance]);

                $varianceInput = $request->variance;
                $responsibilityType = $this->normalizeResponsibilityType($varianceInput['responsibility_type'] ?? 'self');

                $supportingFiles = null;
                if (!empty($varianceInput['supporting_files']) && is_array($varianceInput['supporting_files'])) {
                    $paths = [];
                    foreach ($varianceInput['supporting_files'] as $file) {
                        if (is_object($file) && method_exists($file, 'store')) {
                            $paths[] = $file->storeAs('variance/supporting-files', 'reassign_' . $shiftModel->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension(), 'public');
                        }
                    }
                    $supportingFiles = $paths ?: null;
                }

                $reason = $varianceInput['reason'] ?? $varianceInput['notes'] ?? null;

                if ($responsibilityType === ResponsibilityType::I_WAS_RESPONSIBLE) {
                    ShiftVarianceDetail::create([
                        'cashier_shift_id' => $shiftModel->id,
                        'variance_amount' => $varianceAmount,
                        'variance_type' => $varianceType,
                        'responsibility_type' => $responsibilityType,
                        'responsible_cashier_id' => $originalCashierId,
                        'assigned_amount' => $varianceAmount,
                        'reason' => $reason,
                        'supporting_files' => $supportingFiles,
                    ]);
                } elseif ($responsibilityType === ResponsibilityType::ME_AND_OTHER_FACTORS) {
                    $currentCashierAmount = (float) ($varianceInput['current_cashier_amount'] ?? 0);
                    ShiftVarianceDetail::create([
                        'cashier_shift_id' => $shiftModel->id,
                        'variance_amount' => $varianceAmount,
                        'variance_type' => $varianceType,
                        'responsibility_type' => $responsibilityType,
                        'responsible_cashier_id' => $originalCashierId,
                        'assigned_amount' => $currentCashierAmount,
                        'reason' => $reason,
                        'supporting_files' => $supportingFiles,
                    ]);
                    $otherCashiers = $varianceInput['other_cashiers'] ?? [];
                    foreach ($otherCashiers as $other) {
                        ShiftVarianceDetail::create([
                            'cashier_shift_id' => $shiftModel->id,
                            'variance_amount' => $varianceAmount,
                            'variance_type' => $varianceType,
                            'responsibility_type' => $responsibilityType,
                            'responsible_cashier_id' => $other['cashier_id'] ?? null,
                            'assigned_amount' => (float) ($other['amount'] ?? 0),
                            'reason' => $other['notes'] ?? null,
                            'supporting_files' => null,
                        ]);
                    }
                } elseif ($responsibilityType === ResponsibilityType::OTHER_FACTORS) {
                    ShiftVarianceDetail::create([
                        'cashier_shift_id' => $shiftModel->id,
                        'variance_amount' => $varianceAmount,
                        'variance_type' => $varianceType,
                        'responsibility_type' => $responsibilityType,
                        'responsible_cashier_id' => null,
                        'assigned_amount' => $varianceAmount,
                        'reason' => $reason,
                        'supporting_files' => $supportingFiles,
                    ]);
                } elseif ($responsibilityType === ResponsibilityType::MIXED_FACTORS) {
                    $otherCashiers = $varianceInput['other_cashiers'] ?? $varianceInput['cashiers'] ?? [];
                    foreach ($otherCashiers as $other) {
                        ShiftVarianceDetail::create([
                            'cashier_shift_id' => $shiftModel->id,
                            'variance_amount' => $varianceAmount,
                            'variance_type' => $varianceType,
                            'responsibility_type' => $responsibilityType,
                            'responsible_cashier_id' => $other['cashier_id'] ?? null,
                            'assigned_amount' => (float) ($other['amount'] ?? 0),
                            'reason' => $other['notes'] ?? null,
                            'supporting_files' => null,
                        ]);
                    }
                    $totalCashierAmount = collect($otherCashiers)->sum(fn ($c) => (float) ($c['amount'] ?? 0));
                    $externalAmount = $varianceAmount - $totalCashierAmount;
                    if ($externalAmount > 0) {
                        ShiftVarianceDetail::create([
                            'cashier_shift_id' => $shiftModel->id,
                            'variance_amount' => $varianceAmount,
                            'variance_type' => $varianceType,
                            'responsibility_type' => $responsibilityType,
                            'responsible_cashier_id' => null,
                            'assigned_amount' => $externalAmount,
                            'reason' => $varianceInput['external_reason'] ?? $reason,
                            'supporting_files' => $supportingFiles,
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
                        'varianceDetails.responsibleCashier',
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
            $manager = auth()->user();
            
            // Ensure the user is a branch manager
            if (!$manager || !$manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }
            
            // Optimized eager loading
            $shiftModel = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'branch_id', 'start_time', 'end_time']);
                },
                'shift.branch:id,name',
                'cashier:id,name,branch_id'
            ])->findOrFail($shift);
            
            // Verify shift belongs to manager's branch
            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift does not belong to your branch',
                ], 403);
            }

            // Get all active cashiers for this branch (optimized)
            $allCashiers = Cashier::where('branch_id', $shiftModel->shift->branch_id)
                ->where('status', 'active')
                ->select(['id', 'name', 'email', 'image', 'branch_id', 'status'])
                ->get();

            // Get cashiers who are already working on this date/shift (optimized)
            $busyCashierIds = CashierShift::where('shift_date', $shiftModel->shift_date)
                ->where('shift_id', $shiftModel->shift_id)
                ->where('id', '!=', $shift)
                ->whereIn('status', [ShiftStatus::NOT_STARTED->value, ShiftStatus::IN_PROGRESS->value, ShiftStatus::REASSIGNED->value])
                ->pluck('cashier_id')
                ->toArray();

            // Filter available cashiers
            $availableCashiers = $allCashiers->map(function ($cashier) use ($busyCashierIds, $shiftModel) {
                $isAvailable = !in_array($cashier->id, $busyCashierIds)
                    && $cashier->id != $shiftModel->cashier_id;

                return [
                    'id' => $cashier->id,
                    'name' => $cashier->name,
                    'email' => $cashier->email ?? null,
                    'image' => $cashier->image ? asset('storage/' . $cashier->image) : null,
                    'is_available' => $isAvailable,
                    'disabled' => !$isAvailable,
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
