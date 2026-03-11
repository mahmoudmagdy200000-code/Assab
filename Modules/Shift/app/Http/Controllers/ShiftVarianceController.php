<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Shift\Services\VarianceCalculationService;
use Modules\Shift\Models\{CashierShift, ShiftVarianceAlert};
use Modules\Shift\Transformers\ShiftDetailResource;
use Modules\Cashier\Models\Cashier;

class ShiftVarianceController extends Controller
{
    public function __construct(
        private VarianceCalculationService $varianceService
    ) {}

    /**
     * Record variance with responsibility details
     * Four scenarios: Self, Self and Others, Other Factors, Mixed
     *
     * @param Request $request
     * @param string $shift
     * @return JsonResponse
     */
    public function recordVariance(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'responsibility_type' => 'required|in:self,self_and_others,other_factors,mixed',

            // For self_and_others and mixed (current_cashier_amount optional when other_cashiers sent — remainder is computed)
            'current_cashier_amount' => 'nullable|numeric|min:0',
            'other_cashiers' => 'sometimes|array',
            'other_cashiers.*.cashier_id' => 'required_with:other_cashiers|exists:cashiers,id',
            'other_cashiers.*.amount' => 'required_with:other_cashiers|numeric|min:0',
            'other_cashiers.*.notes' => 'nullable|string|max:255',

            // For other_factors and mixed (required), and optional for self_and_others
            'reason' => 'required_if:responsibility_type,other_factors,mixed|nullable|string|max:500',
            'external_reason' => 'required_if:responsibility_type,mixed|string|max:500',
            'supporting_files' => 'sometimes|array',
            'supporting_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',

            // Optional notes for self_and_others (legacy support)
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $shiftModel = CashierShift::with(['cashier', 'varianceDetails'])->findOrFail($shift);

            // Verify shift has variance
            if (!$shiftModel->hasVariance()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This shift has no variance to record',
                    'variance' => 0,
                ], 400);
            }

            // Verify variance not already recorded
            if ($shiftModel->varianceDetails->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Variance already recorded for this shift',
                ], 400);
            }

            $varianceAmount = abs($shiftModel->calculateVariance());
            $otherSum = collect($request->other_cashiers ?? [])->sum('amount');

            // For self_and_others: allow sending only other_cashiers — current_cashier_amount = variance - sum(others)
            if (in_array($request->responsibility_type, ['self_and_others', 'mixed'])) {
                $currentAmount = $request->has('current_cashier_amount') && $request->current_cashier_amount !== null && $request->current_cashier_amount !== ''
                    ? (float) $request->current_cashier_amount
                    : $varianceAmount - $otherSum;

                if ($currentAmount < -0.01) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sum of other cashiers amounts cannot exceed total variance',
                        'details' => [
                            'variance_amount' => $varianceAmount,
                            'other_cashiers_total' => $otherSum,
                            'your_share_would_be' => $varianceAmount - $otherSum,
                        ]
                    ], 400);
                }

                $totalAssigned = $currentAmount + $otherSum;
                if (abs($totalAssigned - $varianceAmount) > 0.01) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Total assigned amounts do not match variance amount',
                        'details' => [
                            'variance_amount' => $varianceAmount,
                            'total_assigned' => $totalAssigned,
                            'difference' => $varianceAmount - $totalAssigned,
                        ]
                    ], 400);
                }

                // Inject computed current_cashier_amount for service
                $request->merge(['current_cashier_amount' => round($currentAmount, 2)]);
            }

            // Handle file uploads
            $data = $request->all();
            if ($request->hasFile('supporting_files')) {
                $data['supporting_files'] = $request->file('supporting_files');
            }

            // Record variance
            $this->varianceService->recordVariance($shiftModel, $data);

            // Get variance details
            $variance = $this->varianceService->getVarianceFormatted($shiftModel->fresh());

            return response()->json([
                'success' => true,
                'message' => 'Variance recorded successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($shiftModel->fresh()),
                    'variance' => $variance,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record variance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get variance details for a shift
     *
     * @param string $shift
     * @return JsonResponse
     */
    public function getVarianceDetails(string $shift): JsonResponse
    {
        try {
            $shiftModel = CashierShift::with(['varianceDetails.responsibleCashier'])
                ->findOrFail($shift);

            if (!$shiftModel->hasVariance()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No variance found for this shift',
                    'data' => [
                        'has_variance' => false,
                        'variance' => 0,
                    ]
                ]);
            }

            $variance = $this->varianceService->getVarianceFormatted($shiftModel);

            return response()->json([
                'success' => true,
                'message' => 'Variance details retrieved successfully',
                'data' => [
                    'has_variance' => true,
                    'variance' => $variance,
                    'variance_recorded' => $shiftModel->varianceDetails->isNotEmpty(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve variance details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get variance alerts
     * Alerts when variance exceeds threshold
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getVarianceAlerts(Request $request): JsonResponse
    {
        try {
            $query = ShiftVarianceAlert::with(['cashierShift.cashier', 'acknowledgedBy'])
                ->orderBy('created_at', 'desc');

            // Filter by acknowledged status
            if ($request->has('is_acknowledged')) {
                $query->where('is_acknowledged', $request->boolean('is_acknowledged'));
            }

            // Filter by date range
            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $alerts = $query->paginate($request->input('per_page', 20));

            return response()->json([
                'success' => true,
                'message' => 'Variance alerts retrieved successfully',
                'data' => $alerts->map(function ($alert) {
                    return [
                        'id' => $alert->id,
                        'shift' => [
                            'id' => $alert->cashierShift->id,
                            'date' => $alert->cashierShift->shift_date->format('Y-m-d'),
                            'cashier' => $alert->cashierShift->cashier->name,
                        ],
                        'variance_amount' => (float) $alert->variance_amount,
                        'variance_percentage' => (float) $alert->variance_percentage,
                        'alert_type' => $alert->alert_type,
                        'is_acknowledged' => $alert->is_acknowledged,
                        'acknowledged_by' => $alert->acknowledgedBy?->name,
                        'acknowledged_at' => $alert->acknowledged_at?->format('Y-m-d H:i:s'),
                        'notes' => $alert->notes,
                        'created_at' => $alert->created_at->format('Y-m-d H:i:s'),
                    ];
                }),
                'meta' => [
                    'current_page' => $alerts->currentPage(),
                    'total' => $alerts->total(),
                    'per_page' => $alerts->perPage(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve variance alerts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Acknowledge a variance alert
     *
     * @param Request $request
     * @param string $alertId
     * @return JsonResponse
     */
    public function acknowledgeAlert(Request $request, string $alertId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $alert = ShiftVarianceAlert::findOrFail($alertId);

            if ($alert->is_acknowledged) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alert already acknowledged',
                ], 400);
            }

            $alert->update([
                'is_acknowledged' => true,
                'acknowledged_by' => auth()->id(),
                'acknowledged_at' => now(),
                'notes' => $request->notes ?? $alert->notes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Variance alert acknowledged successfully',
                'data' => [
                    'alert_id' => $alert->id,
                    'acknowledged_by' => auth()->user()->name,
                    'acknowledged_at' => now()->format('Y-m-d H:i:s'),
                    'notes' => $alert->notes,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to acknowledge alert',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cashier accepts the responsibility assigned to them on a shift (self_and_others / mixed).
     * The shift may belong to another cashier; this cashier must appear as responsible_cashier_id.
     */
    public function cashierApproveResponsibility(Request $request, string $shift): JsonResponse
    {
        try {
            $cashier = auth()->user();

            $detail = \Modules\Shift\Models\ShiftVarianceDetail::where('cashier_shift_id', $shift)
                ->where('responsible_cashier_id', $cashier->id)
                ->first();

            if (!$detail) {
                return response()->json([
                    'success' => false,
                    'message' => 'No responsibility record found for you on this shift',
                ], 404);
            }

            if ($detail->responsibility_status === 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Responsibility has already been approved',
                    'current_status' => $detail->responsibility_status,
                ], 400);
            }

            $detail->update([
                'responsibility_status' => 'approved',
                'rejection_reason'      => null,
                'reviewed_by_id'        => $cashier->id,
                'reviewed_by_type'      => get_class($cashier),
                'reviewed_at'           => now(),
            ]);

            // Dispatch VarianceRecorded so custody ledger entries are created/updated
            $shiftModel = CashierShift::with(['varianceDetails', 'handover'])->find($shift);
            if ($shiftModel) {
                event(new \Modules\Shift\Events\VarianceRecorded($shiftModel));
            }

            return response()->json([
                'success' => true,
                'message' => 'You have accepted the assigned responsibility',
                'data' => [
                    'shift_id'              => $shift,
                    'cashier_name'          => $cashier->name,
                    'assigned_amount'       => (float) $detail->assigned_amount,
                    'responsibility_status' => 'approved',
                    'reviewed_at'           => now()->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Cashier failed to approve responsibility', [
                'shift_id' => $shift,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to accept responsibility',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cashier rejects the responsibility assigned to them on a shift (self_and_others / mixed).
     * Requires a rejection reason.
     */
    public function cashierRejectResponsibility(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $cashier = auth()->user();

            $detail = \Modules\Shift\Models\ShiftVarianceDetail::where('cashier_shift_id', $shift)
                ->where('responsible_cashier_id', $cashier->id)
                ->first();

            if (!$detail) {
                return response()->json([
                    'success' => false,
                    'message' => 'No responsibility record found for you on this shift',
                ], 404);
            }

            if ($detail->responsibility_status === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Responsibility has already been rejected',
                    'current_status' => $detail->responsibility_status,
                ], 400);
            }

            $detail->update([
                'responsibility_status' => 'rejected',
                'rejection_reason'      => $request->reason,
                'reviewed_by_id'        => $cashier->id,
                'reviewed_by_type'      => get_class($cashier),
                'reviewed_at'           => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'You have rejected the assigned responsibility',
                'data' => [
                    'shift_id'              => $shift,
                    'cashier_name'          => $cashier->name,
                    'assigned_amount'       => (float) $detail->assigned_amount,
                    'responsibility_status' => 'rejected',
                    'rejection_reason'      => $request->reason,
                    'reviewed_at'           => now()->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Cashier failed to reject responsibility', [
                'shift_id' => $shift,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject responsibility',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve the cashier's responsibility for a variance (Branch Manager only).
     */
    public function approveResponsibility(Request $request, string $shift): JsonResponse
    {
        try {
            $manager = auth()->user();

            $shiftModel = CashierShift::with([
                'varianceDetails.responsibleCashier',
                'cashier:id,name',
                'shift:id,branch_id',
            ])->findOrFail($shift);

            if (!$manager->branch_id || $shiftModel->shift->branch_id !== $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift is not in your branch',
                ], 403);
            }

            if ($shiftModel->varianceDetails->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No responsibility details submitted yet for this shift',
                ], 404);
            }

            $currentStatus = $shiftModel->varianceDetails->first()->responsibility_status;
            if ($currentStatus === 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Responsibility has already been approved',
                    'current_status' => $currentStatus,
                ], 400);
            }

            $shiftModel->varianceDetails()->update([
                'responsibility_status' => 'approved',
                'rejection_reason'      => null,
                'reviewed_by_id'        => $manager->id,
                'reviewed_by_type'      => get_class($manager),
                'reviewed_at'           => now(),
            ]);

            $shiftModel->recordHistory(
                'responsibility_approved',
                ['responsibility_status' => $currentStatus],
                ['responsibility_status' => 'approved', 'reviewed_by_id' => $manager->id]
            );

            // Dispatch VarianceRecorded so custody ledger entries are created/updated
            $freshShift = $shiftModel->fresh(['varianceDetails', 'handover']);
            if ($freshShift) {
                event(new \Modules\Shift\Events\VarianceRecorded($freshShift));
            }

            return response()->json([
                'success' => true,
                'message' => 'Responsibility approved successfully',
                'data' => [
                    'shift_id'              => $shiftModel->id,
                    'cashier_name'          => $shiftModel->cashier?->name,
                    'responsibility_status' => 'approved',
                    'approved_by'           => $manager->name,
                    'approved_at'           => now()->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to approve responsibility', [
                'shift_id' => $shift,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve responsibility',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject the cashier's responsibility for a variance (Branch Manager only).
     * Requires a rejection reason.
     */
    public function rejectResponsibility(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $manager = auth()->user();

            $shiftModel = CashierShift::with([
                'varianceDetails.responsibleCashier',
                'cashier:id,name',
                'shift:id,branch_id',
            ])->findOrFail($shift);

            if (!$manager->branch_id || $shiftModel->shift->branch_id !== $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift is not in your branch',
                ], 403);
            }

            if ($shiftModel->varianceDetails->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No responsibility details submitted yet for this shift',
                ], 404);
            }

            $currentStatus = $shiftModel->varianceDetails->first()->responsibility_status;
            if ($currentStatus === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Responsibility has already been rejected',
                    'current_status' => $currentStatus,
                ], 400);
            }

            $shiftModel->varianceDetails()->update([
                'responsibility_status' => 'rejected',
                'rejection_reason'      => $request->reason,
                'reviewed_by_id'        => $manager->id,
                'reviewed_by_type'      => get_class($manager),
                'reviewed_at'           => now(),
            ]);

            $shiftModel->recordHistory(
                'responsibility_rejected',
                ['responsibility_status' => $currentStatus],
                [
                    'responsibility_status' => 'rejected',
                    'rejection_reason'      => $request->reason,
                    'reviewed_by_id'        => $manager->id,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Responsibility rejected successfully',
                'data' => [
                    'shift_id'              => $shiftModel->id,
                    'cashier_name'          => $shiftModel->cashier?->name,
                    'responsibility_status' => 'rejected',
                    'rejection_reason'      => $request->reason,
                    'rejected_by'           => $manager->name,
                    'rejected_at'           => now()->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reject responsibility', [
                'shift_id' => $shift,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject responsibility',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get variance statistics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getVarianceStatistics(Request $request): JsonResponse
    {
        try {
            $dateFrom = $request->input('date_from', now()->subMonth()->format('Y-m-d'));
            $dateTo = $request->input('date_to', now()->format('Y-m-d'));

            $shifts = CashierShift::whereBetween('shift_date', [$dateFrom, $dateTo])
                ->where('status', 'completed')
                ->get();

            $totalShifts = $shifts->count();
            $shiftsWithVariance = $shifts->filter(fn($s) => $s->hasVariance())->count();
            $totalVariance = $shifts->sum('variance');
            $averageVariance = $totalShifts > 0 ? $totalVariance / $totalShifts : 0;

            $overVariances = $shifts->filter(fn($s) => $s->variance > 0);
            $shortVariances = $shifts->filter(fn($s) => $s->variance < 0);

            return response()->json([
                'success' => true,
                'message' => 'Variance statistics retrieved successfully',
                'data' => [
                    'date_range' => [
                        'from' => $dateFrom,
                        'to' => $dateTo,
                    ],
                    'total_shifts' => $totalShifts,
                    'shifts_with_variance' => $shiftsWithVariance,
                    'variance_percentage' => $totalShifts > 0
                        ? round(($shiftsWithVariance / $totalShifts) * 100, 2)
                        : 0,
                    'total_variance' => round($totalVariance, 2),
                    'average_variance' => round($averageVariance, 2),
                    'over_variances' => [
                        'count' => $overVariances->count(),
                        'total_amount' => round($overVariances->sum('variance'), 2),
                        'average_amount' => $overVariances->count() > 0
                            ? round($overVariances->avg('variance'), 2)
                            : 0,
                    ],
                    'short_variances' => [
                        'count' => $shortVariances->count(),
                        'total_amount' => round(abs($shortVariances->sum('variance')), 2),
                        'average_amount' => $shortVariances->count() > 0
                            ? round(abs($shortVariances->avg('variance')), 2)
                            : 0,
                    ],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve variance statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
