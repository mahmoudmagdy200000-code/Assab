<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Shift\Services\VarianceCalculationService;
use Modules\Shift\Models\{CashierShift, ShiftVarianceAlert};
use Modules\Shift\Transformers\ShiftDetailResource;

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
     * @param int $shift
     * @return JsonResponse
     */
    public function recordVariance(Request $request, int $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'responsibility_type' => 'required|in:self,self_and_others,other_factors,mixed',

            // For self_and_others and mixed
            'current_cashier_amount' => 'required_if:responsibility_type,self_and_others,mixed|numeric|min:0',
            'other_cashiers' => 'sometimes|array',
            'other_cashiers.*.cashier_id' => 'required_with:other_cashiers|exists:cashiers,id',
            'other_cashiers.*.amount' => 'required_with:other_cashiers|numeric|min:0',
            'other_cashiers.*.notes' => 'nullable|string|max:255',

            // For other_factors and mixed
            'reason' => 'required_if:responsibility_type,other_factors,mixed|string|max:500',
            'external_reason' => 'required_if:responsibility_type,mixed|string|max:500',
            'supporting_files' => 'sometimes|array',
            'supporting_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',

            // Optional notes for self_and_others
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

            // Validate total amounts for shared/mixed responsibility
            if (in_array($request->responsibility_type, ['self_and_others', 'mixed'])) {
                $totalAssigned = $request->current_cashier_amount +
                    collect($request->other_cashiers ?? [])->sum('amount');

                $varianceAmount = abs($shiftModel->calculateVariance());

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
            }

            // Handle file uploads
            $data = $request->all();
            if ($request->hasFile('supporting_files')) {
                $data['supporting_files'] = $request->file('supporting_files');
            }

            // Record variance
            $this->varianceService->recordVariance($shiftModel, $data);

            // Get variance details
            $varianceDetails = $this->varianceService->getVarianceDetails($shiftModel->fresh());

            return response()->json([
                'success' => true,
                'message' => 'Variance recorded successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($shiftModel->fresh()),
                    'variance_details' => $varianceDetails,
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
     * @param int $shift
     * @return JsonResponse
     */
    public function getVarianceDetails(int $shift): JsonResponse
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

            $varianceDetails = $this->varianceService->getVarianceDetails($shiftModel);

            return response()->json([
                'success' => true,
                'message' => 'Variance details retrieved successfully',
                'data' => [
                    'has_variance' => true,
                    'variance_details' => $varianceDetails,
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
     * @param int $alertId
     * @return JsonResponse
     */
    public function acknowledgeAlert(Request $request, int $alertId): JsonResponse
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

