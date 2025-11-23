<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Services\ShiftEndService;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Transformers\ShiftDetailResource;

class ShiftEndController extends Controller
{
    public function __construct(
        private ShiftEndService $shiftEndService
    ) {}

    /**
     * End shift only (without handover)
     */
    public function endShiftOnly(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'total_sales' => 'required|numeric|min:0',
            'cash_collected' => 'sometimes|numeric|min:0',
            'card_payments' => 'sometimes|numeric|min:0',
            'aggregators' => 'sometimes|array',
            'aggregators.*.aggregator_id' => 'required_with:aggregators|exists:aggregators,id',
            'aggregators.*.amount' => 'required_with:aggregators|numeric|min:0',
            'aggregators.*.notes' => 'nullable|string|max:255',
            'pos_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            // إضافة validation للـ variance (اختياري)
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $managerBranchId = $request->manager_branch_id;

            $shiftModel = CashierShift::whereHas('shift', function ($q) use ($managerBranchId) {
                $q->where('branch_id', $managerBranchId);
            })->findOrFail($shift);

            if ($shiftModel->status !== ShiftStatus::IN_PROGRESS) {
                return response()->json([
                    'success' => false,
                    'message' => 'This shift is not in progress',
                ], 400);
            }

            $isValid = $this->shiftEndService->validatePaymentBreakdown($request->all());
            if (!$isValid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment breakdown does not match total sales',
                    'details' => [
                        'total_sales' => $request->total_sales,
                        'calculated_total' => $request->cash_collected + $request->card_payments +
                            collect($request->aggregators ?? [])->sum('amount'),
                    ]
                ], 400);
            }

            $data = $request->all();
            if ($request->hasFile('pos_receipt')) {
                $data['pos_receipt'] = $request->file('pos_receipt');
            }

            // معالجة ملفات الـ variance إذا كانت موجودة
            if ($request->hasFile('variance.supporting_files')) {
                $data['variance']['supporting_files'] = $request->file('variance.supporting_files');
            }

            $updatedShift = $this->shiftEndService->endShiftOnly($shiftModel, $data);
            $salesCalculation = $this->shiftEndService->calculateNetSales($request->total_sales);

            // حساب الـ variance إذا كانت موجودة
            $varianceData = null;
            if ($request->has('variance')) {
                $totalVariance = 0;

                if (isset($data['variance']['current_cashier_amount'])) {
                    $totalVariance += $data['variance']['current_cashier_amount'];
                }

                if (isset($data['variance']['other_cashiers'])) {
                    $totalVariance += collect($data['variance']['other_cashiers'])->sum('amount');
                }

                $varianceData = [
                    'total_variance' => (float) $totalVariance,
                    'responsibility_type' => $data['variance']['responsibility_type'],
                    'variance_type' => $totalVariance > 0 ? 'Over' : ($totalVariance < 0 ? 'Short' : 'None'),
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift ended successfully without handover',
                'data' => [
                    'shift' => new ShiftDetailResource($updatedShift),
                    'summary' => [
                        'total_sales' => (float) $salesCalculation['total_sales'],
                        'net_sales' => (float) $salesCalculation['net_sales'],
                        'vat_amount' => (float) $salesCalculation['vat_amount'],
                        'sales_breakdown' => [
                            'cash_collected' => (float) $request->cash_collected,
                            'card_payments' => (float) $request->card_payments,
                            'delivery_apps' => collect($request->aggregators ?? [])->sum('amount'),
                        ],
                        'opening_balance' => 0,
                        'handover_status' => 'pending',
                        'variance' => $varianceData,
                    ],
                    'next_actions' => [
                        'handover_cash_now' => true,
                        'view_details' => true,
                    ]
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Shift not found or you do not have access to it',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to end shift',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * End shift with handover
     */
    public function endShiftWithHandover(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'total_sales' => 'required|numeric|min:0',
            'cash_collected' => 'sometimes|numeric|min:0',
            'card_payments' => 'sometimes|numeric|min:0',
            'aggregators' => 'sometimes|array',
            'aggregators.*.aggregator_id' => 'required_with:aggregators|exists:aggregators,id',
            'aggregators.*.amount' => 'required_with:aggregators|numeric|min:0',
            'aggregators.*.notes' => 'nullable|string|max:255',
            'pos_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'next_cashier_id' => 'required|exists:cashiers,id',
            'handover_amount' => 'required|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $managerBranchId = $request->manager_branch_id;

            $shiftModel = CashierShift::whereHas('shift', function ($q) use ($managerBranchId) {
                $q->where('branch_id', $managerBranchId);
            })->findOrFail($shift);

            if ($shiftModel->status !== ShiftStatus::IN_PROGRESS) {
                return response()->json([
                    'success' => false,
                    'message' => 'This shift is not in progress',
                ], 400);
            }

            // Fetch the next cashier BEFORE processing
            $nextCashier = Cashier::find($request->next_cashier_id);
            if (!$nextCashier) {
                return response()->json([
                    'success' => false,
                    'message' => 'Next cashier not found',
                ], 404);
            }

            $isValid = $this->shiftEndService->validatePaymentBreakdown($request->all());
            if (!$isValid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment breakdown does not match total sales',
                ], 400);
            }

            $data = $request->all();
            if ($request->hasFile('pos_receipt')) {
                $data['pos_receipt'] = $request->file('pos_receipt');
            }

            if ($request->hasFile('variance.supporting_files')) {
                $data['variance']['supporting_files'] = $request->file('variance.supporting_files');
            }

            $updatedShift = $this->shiftEndService->endShiftWithHandover($shiftModel, $data);

            // Reload the shift with all necessary relationships
            $updatedShift = CashierShift::with([
                'nextCashier',
                'cashier',
                'shift',
                'salesBreakdown.aggregator',
                'handoverStatus',
                'varianceDetails.responsibleCashier'
            ])->findOrFail($updatedShift->id);

            // Verify next_cashier_id was set
            if (!$updatedShift->next_cashier_id) {
                throw new \RuntimeException('Failed to set next_cashier_id on shift');
            }

            // Calculate variance
            $variance = $request->total_sales - $request->handover_amount;
            $varianceType = $variance > 0 ? 'Over' : ($variance < 0 ? 'Short' : 'None');
            $salesCalculation = $this->shiftEndService->calculateNetSales($request->total_sales);

            return response()->json([
                'success' => true,
                'message' => 'Shift ended successfully with handover',
                'data' => [
                    'shift' => new ShiftDetailResource($updatedShift),
                    'summary' => [
                        'total_sales' => (float) $salesCalculation['total_sales'],
                        'net_sales' => (float) $salesCalculation['net_sales'],
                        'vat_amount' => (float) $salesCalculation['vat_amount'],
                        'sales_breakdown' => [
                            'cash_collected' => (float) $request->cash_collected,
                            'card_payments' => (float) $request->card_payments,
                            'delivery_apps' => collect($request->aggregators ?? [])->sum('amount'),
                        ],
                        'handover_details' => [
                            'handover_amount' => (float) $request->handover_amount,
                            'variance' => (float) $variance,
                            'variance_type' => $varianceType,
                            'handover_to' => $nextCashier->name,
                            'next_cashier' => $nextCashier->name,
                            'handover_notes' => $request->handover_notes,
                        ]
                    ]
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Shift not found or you do not have access to it',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to end shift with handover',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate net sales and VAT
     */
    public function calculateSales(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'total_sales' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $calculation = $this->shiftEndService->calculateNetSales($request->total_sales);

        return response()->json([
            'success' => true,
            'data' => $calculation
        ]);
    }
}
