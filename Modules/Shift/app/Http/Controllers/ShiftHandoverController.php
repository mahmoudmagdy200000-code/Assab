<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Transformers\ShiftDetailResource;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\HandoverStatus;

class ShiftHandoverController extends Controller
{
    public function __construct(
        private HandoverService $handoverService
    ) {}

    /**
     * Record handover (for shifts ended without handover)
     * Handover Cash Now - After ending shift only
     *
     * @param Request $request
     * @param string $shift
     * @return JsonResponse
     */
    public function recordHandover(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'next_cashier_id' => 'required|exists:cashiers,id',
            'handover_amount' => 'required|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $shiftModel = CashierShift::with(['cashier', 'nextCashier'])->findOrFail($shift);

            // Verify shift is completed but no handover yet
            if ($shiftModel->status->value !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Shift must be completed before handover',
                ], 400);
            }

            if ($shiftModel->handoverStatus && $shiftModel->handoverStatus->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Handover already processed',
                ], 400);
            }

            // Record handover
            $handoverStatus = $this->handoverService->recordHandover($shiftModel, $request->all());

            // Calculate variance
            $variance = $shiftModel->total_sales - $request->handover_amount;
            $varianceType = $variance > 0 ? 'Over' : ($variance < 0 ? 'Short' : 'None');

            $nextCashier = Cashier::findOrFail($request->next_cashier_id);

            return response()->json([
                'success' => true,
                'message' => 'Handover recorded successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($shiftModel->fresh()),
                    'summary' => [
                        'total_sales' => (float) $shiftModel->total_sales,
                        'sales_breakdown' => [
                            'cash_collected' => (float) $shiftModel->cash_collected,
                            'card_payments' => (float) $shiftModel->card_payments,
                            'delivery_apps' => (float) $shiftModel->salesBreakdown->sum('amount'),
                        ],
                        'handover_details' => [
                            'handover_amount' => (float) $request->handover_amount,
                            'variance' => (float) $variance,
                            'variance_type' => $varianceType,
                            'handover_to' => $nextCashier->name,
                            'next_cashier' => $nextCashier->name,
                            'handover_notes' => $request->handover_notes,
                            'status' => 'pending',
                        ]
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record handover',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Approve handover
     * Branch Manager approves the handover
     *
     * @param Request $request
     * @param string $shift
     * @return JsonResponse
     */
    public function approveHandover(Request $request, $shift): JsonResponse
    {
        try {
            $shiftModel = CashierShift::with(['handoverStatus'])->findOrFail($shift);

            // Check if handover exists and has correct status
            if (!$shiftModel->handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift'
                ], 404);
            }

            if ($shiftModel->handoverStatus->status !== HandoverStatus::PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Handover is not pending approval',
                    'current_status' => $shiftModel->handoverStatus->status->value
                ], 400);
            }

            // Process the approval
            $result = $this->handoverService->approveHandover(
                $shiftModel,
                auth()->id(),
                'branch_manager',
                $request->get('manager_comment')
            );

            return response()->json([
                'success' => true,
                'message' => 'Handover approved successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($result)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Handover approval failed', [
                'shift_id' => $shift,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve handover',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Reject handover
     * Branch Manager rejects the handover with reason
     *
     * @param Request $request
     * @param string $shift
     * @return JsonResponse
     */
    public function rejectHandover(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'nullable|string|max:500',
            'manager_comment' => 'nullable|string|max:500',
            'rejection_files' => 'sometimes|array',
            'rejection_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $shiftModel = CashierShift::with(['handoverStatus', 'cashier'])
                ->findOrFail($shift);

            // Verify handover exists and is pending
            if (!$shiftModel->handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift',
                ], 404);
            }

            if ($shiftModel->handoverStatus->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Handover is not pending approval',
                    'current_status' => $shiftModel->handoverStatus->status,
                ], 400);
            }

            // Handle file uploads
            $data = [
                'reviewed_by' => auth()->id(),
                'rejection_reason' => $request->rejection_reason,
                'manager_comment' => $request->manager_comment,
            ];

            if ($request->hasFile('rejection_files')) {
                $data['rejection_files'] = $request->file('rejection_files');
            }

            // Reject handover
            $this->handoverService->rejectHandover($shiftModel, $data);

            return response()->json([
                'success' => true,
                'message' => 'Handover rejected successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($shiftModel->fresh()),
                    'rejection_details' => [
                        'status' => 'rejected',
                        'rejected_by' => auth()->user()->name,
                        'rejection_reason' => $request->rejection_reason,
                        'manager_comment' => $request->manager_comment,
                        'rejected_at' => now()->format('Y-m-d H:i:s'),
                        'cashier_notified' => true,
                    ],
                    'next_actions' => [
                        'cashier_can_resubmit' => true,
                        'manager_can_request_corrections' => true,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject handover',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get handover status and details
     *
     * @param string $shift
     * @return JsonResponse
     */
    public function getHandoverStatus(string $shift): JsonResponse
    {
        try {
            $shiftModel = CashierShift::with([
                'handoverStatus.reviewedBy',
                'cashier',
                'nextCashier'
            ])->findOrFail($shift);

            if (!$shiftModel->handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift',
                ], 404);
            }

            $handoverStatus = $shiftModel->handoverStatus;

            return response()->json([
                'success' => true,
                'message' => 'Handover status retrieved successfully',
                'data' => [
                    'status' => $handoverStatus->status,
                    'handover_amount' => (float) $shiftModel->closing_balance,
                    'variance' => (float) $shiftModel->variance,
                    'variance_type' => $shiftModel->variance > 0 ? 'Over' : ($shiftModel->variance < 0 ? 'Short' : 'None'),
                    'handover_to' => $shiftModel->nextCashier?->name,
                    'handover_notes' => $shiftModel->handover_notes,
                    'handed_over_at' => $shiftModel->handed_over_at?->format('Y-m-d H:i:s'),
                    'reviewed_by' => $handoverStatus->reviewer?->name,
                    'reviewed_at' => $handoverStatus->reviewed_at?->format('Y-m-d H:i:s'),
                    'rejection_reason' => $handoverStatus->rejection_reason,
                    'rejection_files' => $handoverStatus->rejection_files
                        ? collect(json_decode($handoverStatus->rejection_files))->map(function ($file) {
                            return asset('storage/' . $file);
                        })
                        : [],
                    'manager_comment' => $handoverStatus->manager_comment,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve handover status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available cashiers for handover
     *
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

            // Get the next shift's cashier (auto-handover)
            $nextShift = CashierShift::where('shift_date', $shiftModel->shift_date)
                ->where('shift_id', '>', $shiftModel->shift_id)
                ->orderBy('shift_id')
                ->first();

            $suggestedCashier = $nextShift ? $nextShift->cashier_id : null;

            $availableCashiers = $allCashiers->map(function ($cashier) use ($suggestedCashier) {
                return [
                    'id' => $cashier->id,
                    'name' => $cashier->name,
                    'image' => $cashier->image,
                    'is_suggested' => $cashier->id == $suggestedCashier,
                    'suggestion_reason' => $cashier->id == $suggestedCashier
                        ? 'Next scheduled cashier (auto-handover)'
                        : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Available cashiers retrieved successfully',
                'data' => [
                    'cashiers' => $availableCashiers->values(),
                    'auto_handover_enabled' => !is_null($suggestedCashier),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available cashiers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get handover summary for all shifts
     * Restricted to branch managers via middleware
     */
    public function getHandoverSummaries(): JsonResponse
    {
        try {
            $user = auth()->user();
            $branchId = $user->branch_id;

            if (!$branchId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Branch manager is not assigned to any branch',
                ], 400);
            }

            $summaries = $this->handoverService->getHandoverSummaries([
                'branch_id' => $branchId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Handover summaries retrieved successfully',
                'data' => $summaries
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve handover summaries', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve handover summaries',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
