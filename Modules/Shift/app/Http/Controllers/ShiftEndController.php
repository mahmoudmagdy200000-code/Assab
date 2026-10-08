<?php

namespace Modules\Shift\Http\Controllers;

use App\Support\ShiftMoneyValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftEndService;
use Modules\Shift\Services\VarianceCalculationService;
use Modules\Shift\Transformers\ShiftDetailResource;

/**
 * ShiftEndController
 *
 * Handles all 4 shift ending options:
 * - Option 1: End Shift Only (without handover)
 * - Option 2: Handover Without Variance
 * - Option 3: End Shift with Handover (No Variance)
 * - Option 4: End Shift with Handover and Variance
 */
class ShiftEndController extends Controller
{
    public function __construct(
        private ShiftEndService $shiftEndService,
        private HandoverService $handoverService,
        private VarianceCalculationService $varianceService
    ) {}

    /**
     * OPTION 1: End Shift Only (without handover)
     *
     * Cashier records:
     * - Total sales amount
     * - Cash collections
     * - Mada card payments
     * - Payment aggregator collections
     * - Optional: POS receipt upload
     *
     * Result: Shift completed without handover
     */
    public function endShiftOnly(Request $request, string $shift): JsonResponse
    {
        ShiftMoneyValidation::normalizeRepresentationNoise($request);
        $validator = Validator::make($request->all(), [
            'total_sales' => 'required|'.ShiftMoneyValidation::SAR,
            'cash_collected' => 'sometimes|'.ShiftMoneyValidation::SAR,
            'card_payments' => 'sometimes|'.ShiftMoneyValidation::SAR,
            'aggregators' => 'sometimes|array',
            'aggregators.*.aggregator_id' => 'required_with:aggregators|exists:aggregators,id',
            'aggregators.*.amount' => 'required_with:aggregators|'.ShiftMoneyValidation::SAR,
            'aggregators.*.notes' => 'nullable|string|max:255',
            'pos_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            // Optional variance for shifts with variance but no handover yet
            'variance' => 'sometimes|array',
            'variance.responsibility_type' => 'required_with:variance|in:self,self_and_others,other_factors,mixed',
            'variance.current_cashier_amount' => 'required_if:variance.responsibility_type,self_and_others,mixed|'.ShiftMoneyValidation::SAR,
            'variance.other_cashiers' => 'sometimes|array',
            'variance.other_cashiers.*.cashier_id' => 'required_with:variance.other_cashiers|exists:cashiers,id',
            'variance.other_cashiers.*.amount' => 'required_with:variance.other_cashiers|'.ShiftMoneyValidation::SAR,
            'variance.other_cashiers.*.notes' => 'nullable|string|max:255',
            'variance.reason' => 'required_if:variance.responsibility_type,other_factors,mixed|nullable|string|max:500',
            'variance.supporting_files' => 'sometimes|array',
            'variance.supporting_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',
        ]);
        $validator->after(function (\Illuminate\Validation\Validator $v) use ($request) {
            $this->validateUniqueAggregators($v, $request->input('aggregators', []));
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Get shift model - support both manager and cashier access
            $user = auth()->user();
            $shiftModel = $this->getShiftForUser($shift, $user);

            if (! $shiftModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shift not found or you do not have access to it',
                ], 404);
            }

            if ($shiftModel->status !== ShiftStatus::IN_PROGRESS) {
                return response()->json([
                    'success' => false,
                    'message' => 'This shift is not in progress. Current status: '.$shiftModel->status->value,
                ], 400);
            }

            // Validate payment breakdown
            // $isValid = $this->shiftEndService->validatePaymentBreakdown($request->all());
            // if (!$isValid) {
            //     $calculatedTotal = ($request->cash_collected ?? 0) + ($request->card_payments ?? 0) +
            //         collect($request->aggregators ?? [])->sum('amount');

            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Payment breakdown does not match total sales',
            //         'details' => [
            //             'total_sales' => $request->total_sales,
            //             'calculated_total' => $calculatedTotal,
            //             'difference' => abs($request->total_sales - $calculatedTotal),
            //         ]
            //     ], 400);
            // }

            // Prepare data
            $data = $request->all();
            if ($request->hasFile('pos_receipt')) {
                $data['pos_receipt'] = $request->file('pos_receipt');
            }
            if ($request->hasFile('variance.supporting_files')) {
                $data['variance']['supporting_files'] = $request->file('variance.supporting_files');
            }

            // End shift
            $updatedShift = $this->shiftEndService->endShiftOnly($shiftModel, $data);

            // Handle variance if provided
            if ($request->has('variance') && $updatedShift->hasVariance()) {
                $this->varianceService->recordVariance($updatedShift, $request->variance);
            }

            // Reload shift with relationships
            $updatedShift = $updatedShift->fresh()->loadFullRelationships();

            // Get variance if exists
            $variance = null;
            if ($updatedShift->hasVariance()) {
                $variance = $this->varianceService->getVarianceFormatted($updatedShift);
            }

            // Calculate sales
            $salesCalculation = $this->shiftEndService->calculateNetSales($request->total_sales);

            return response()->json([
                'success' => true,
                'message' => 'Shift ended successfully without handover',
                'data' => [
                    'shift' => new ShiftDetailResource($updatedShift),
                    'variance' => $variance,
                    'summary' => [
                        'total_sales' => (float) $salesCalculation['total_sales'],
                        'net_sales' => (float) $salesCalculation['net_sales'],
                        'vat_amount' => (float) $salesCalculation['vat_amount'],
                        'sales_breakdown' => [
                            'cash_collected' => (float) ($updatedShift->cash_collected ?? 0),
                            'card_payments' => (float) ($updatedShift->card_payments ?? 0),
                            'delivery_apps' => (float) $updatedShift->salesBreakdown->sum('amount'),
                        ],
                        'opening_balance' => (float) ($updatedShift->opening_balance ?? 0),
                        'handover_status' => 'pending',
                    ],
                    'next_actions' => [
                        'handover_cash_now' => true,
                        'view_details' => true,
                    ],
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Shift not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('End shift only failed', [
                'shift_id' => $shift,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to end shift',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * OPTION 2, 3, 4: End Shift with Handover
     *
     * Supports:
     * - Handover to next cashier (auto-handover)
     * - Handover to branch manager (final handover)
     * - With or without variance
     * - All 4 variance types
     */
    public function endShiftWithHandover(Request $request, string $shift): JsonResponse
    {
        ShiftMoneyValidation::normalizeRepresentationNoise($request);
        $validator = Validator::make($request->all(), [
            // Sales information
            'total_sales' => 'required|'.ShiftMoneyValidation::SAR,
            'cash_collected' => 'sometimes|'.ShiftMoneyValidation::SAR,
            'card_payments' => 'sometimes|'.ShiftMoneyValidation::SAR,
            'aggregators' => 'sometimes|array',
            'aggregators.*.aggregator_id' => 'required_with:aggregators|exists:aggregators,id',
            'aggregators.*.amount' => 'required_with:aggregators|'.ShiftMoneyValidation::SAR,
            'aggregators.*.notes' => 'nullable|string|max:255',
            'pos_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',

            // Handover recipient
            'handover_to_type' => 'sometimes|in:cashier,branch_manager',
            'next_cashier_id' => 'required_without_all:handover_to_type,branch_manager_id|nullable|exists:cashiers,id',
            'branch_manager_id' => 'required_without_all:handover_to_type,next_cashier_id|nullable|exists:branch_managers,id',

            // Handover details
            'handover_amount' => 'required|'.ShiftMoneyValidation::SAR,
            'handover_notes' => 'nullable|string|max:500',

            // Variance information
            'variance' => 'sometimes|array',
            'variance.responsibility_type' => 'required_with:variance|in:self,self_and_others,other_factors,mixed',
            'variance.current_cashier_amount' => 'required_if:variance.responsibility_type,self_and_others,mixed|'.ShiftMoneyValidation::SAR,
            'variance.other_cashiers' => 'sometimes|array',
            'variance.other_cashiers.*.cashier_id' => 'required_with:variance.other_cashiers|exists:cashiers,id',
            'variance.other_cashiers.*.amount' => 'required_with:variance.other_cashiers|'.ShiftMoneyValidation::SAR,
            'variance.other_cashiers.*.notes' => 'nullable|string|max:255',
            'variance.reason' => 'required_if:variance.responsibility_type,other_factors,mixed|nullable|string|max:500',
            'variance.supporting_files' => 'sometimes|array',
            'variance.supporting_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',
        ]);
        $validator->after(function (\Illuminate\Validation\Validator $v) use ($request) {
            $this->validateUniqueAggregators($v, $request->input('aggregators', []));
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = auth()->user();
            $shiftModel = $this->getShiftForUser($shift, $user);

            if (! $shiftModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shift not found or you do not have access to it',
                ], 404);
            }

            if ($shiftModel->status !== ShiftStatus::IN_PROGRESS) {
                return response()->json([
                    'success' => false,
                    'message' => 'This shift is not in progress',
                ], 400);
            }

            // Determine handover type
            $handoverToType = $request->input('handover_to_type');
            $handoverToId = null;
            $handoverToName = null;

            // Check if branch_manager_id is provided (must be filled; ignore null/empty so cashier handover works)
            if ($request->filled('branch_manager_id')) {
                $branchManager = \Modules\BranchManagers\Models\BranchManager::find($request->branch_manager_id);

                if (! $branchManager) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Branch manager not found',
                    ], 404);
                }

                // Verify branch manager belongs to the same branch
                if ($branchManager->branch_id !== $shiftModel->shift->branch_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Branch manager does not belong to this branch',
                    ], 400);
                }

                $handoverToType = 'branch_manager';
                $handoverToId = $branchManager->id;
                $handoverToName = $branchManager->name;
            }
            // Check if handover_to_type is explicitly set to branch_manager
            elseif ($handoverToType === 'branch_manager') {
                // Get branch manager for this branch
                $branchManager = \Modules\BranchManagers\Models\BranchManager::where('branch_id', $shiftModel->shift->branch_id)
                    ->where('is_active', true)
                    ->first();

                if (! $branchManager) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No active branch manager found for this branch',
                    ], 400);
                }

                $handoverToId = $branchManager->id;
                $handoverToName = $branchManager->name;
            }
            // Default to cashier
            else {
                $handoverToType = 'cashier';

                if (! $request->has('next_cashier_id')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'next_cashier_id is required when handing over to cashier',
                    ], 400);
                }

                // Handover to next cashier
                $nextCashier = Cashier::find($request->next_cashier_id);
                if (! $nextCashier) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Next cashier not found',
                    ], 404);
                }
                $handoverToId = $nextCashier->id;
                $handoverToName = $nextCashier->name;
            }

            // Validate payment breakdown
            // $isValid = $this->shiftEndService->validatePaymentBreakdown($request->all());
            // if (!$isValid) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Payment breakdown does not match total sales',
            //     ], 400);
            // }

            // Prepare data
            $data = $request->all();
            // Ensure handover_to_type and handover_to_id are set correctly
            $data['handover_to_type'] = $handoverToType;
            $data['handover_to_id'] = $handoverToId;
            // Also pass branch_manager_id if provided
            if ($handoverToType === 'branch_manager' && $request->has('branch_manager_id')) {
                $data['branch_manager_id'] = $request->branch_manager_id;
            }

            // Log for debugging
            Log::info('ShiftEndController: Preparing handover data', [
                'handover_to_type' => $handoverToType,
                'handover_to_id' => $handoverToId,
                'handover_to_name' => $handoverToName,
                'request_handover_to_type' => $request->input('handover_to_type'),
            ]);

            if ($request->hasFile('pos_receipt')) {
                $data['pos_receipt'] = $request->file('pos_receipt');
            }
            if ($request->hasFile('variance.supporting_files')) {
                $data['variance']['supporting_files'] = $request->file('variance.supporting_files');
            }

            // End shift with handover
            $updatedShift = $this->shiftEndService->endShiftWithHandover($shiftModel, $data);

            // Reload with relationships
            $updatedShift = CashierShift::with([
                'nextCashier',
                'cashier',
                'shift.branch',
                'salesBreakdown.aggregator',
                'handoverStatus',
                'varianceDetails.responsibleCashier',
            ])->findOrFail($updatedShift->id);

            // Get variance if exists
            $variance = null;
            if ($updatedShift->hasVariance()) {
                $variance = $this->varianceService->getVarianceFormatted($updatedShift);
            }

            // Calculate variance amount using the correct formula:
            // Variance = Total Sales - (Cash Collected + Card Payments + Delivery Apps)
            $varianceAmount = $updatedShift->calculateVariance();
            $varianceType = $varianceAmount > 0 ? 'Over' : ($varianceAmount < 0 ? 'Short' : 'None');
            $salesCalculation = $this->shiftEndService->calculateNetSales($request->total_sales);

            // Get handover status from the created handover
            $handoverStatus = 'pending';
            if ($updatedShift->handoverStatus) {
                $handoverStatus = $this->normalizeHandoverStatus($updatedShift->handoverStatus->manager_approval_status ?? 'pending');
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift ended successfully with handover',
                'data' => [
                    'shift' => new ShiftDetailResource($updatedShift),
                    'variance' => $variance,
                    'summary' => [
                        'total_sales' => (float) $salesCalculation['total_sales'],
                        'net_sales' => (float) $salesCalculation['net_sales'],
                        'vat_amount' => (float) $salesCalculation['vat_amount'],
                        'sales_breakdown' => [
                            'cash_collected' => (float) ($updatedShift->cash_collected ?? 0),
                            'card_payments' => (float) ($updatedShift->card_payments ?? 0),
                            'delivery_apps' => (float) $updatedShift->salesBreakdown->sum('amount'),
                        ],
                        'handover_details' => [
                            'handover_amount' => (float) $request->handover_amount,
                            'variance' => (float) $varianceAmount,
                            'variance_type' => $varianceType,
                            'handover_to_type' => $handoverToType,
                            'handover_to' => $handoverToName,
                            'next_cashier' => $handoverToType === 'cashier' ? $handoverToName : null,
                            'handover_notes' => $request->handover_notes,
                            'status' => $handoverStatus,
                        ],
                    ],
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Shift not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('End shift with handover failed', [
                'shift_id' => $shift,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to end shift with handover',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Record handover after shift ended (Start Handover action)
     *
     * Used when cashier ended shift without handover and now wants to do handover
     */
    public function startHandover(Request $request, string $shift): JsonResponse
    {
        ShiftMoneyValidation::normalizeRepresentationNoise($request);
        $validator = Validator::make($request->all(), [
            'handover_to_type' => 'sometimes|in:cashier,branch_manager',
            'next_cashier_id' => 'required_without_all:handover_to_type,branch_manager_id|nullable|exists:cashiers,id',
            'branch_manager_id' => 'required_without_all:handover_to_type,next_cashier_id|nullable|exists:branch_managers,id',
            'handover_amount' => 'required|'.ShiftMoneyValidation::SAR,
            'handover_notes' => 'nullable|string|max:500',
            'variance' => 'sometimes|array',
            'variance.responsibility_type' => 'required_with:variance|in:self,self_and_others,other_factors,mixed',
            'variance.reason' => 'required_if:variance.responsibility_type,other_factors,mixed|string|max:500',
            'variance.supporting_files' => 'sometimes|array',
            'variance.supporting_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = auth()->user();
            $shiftModel = $this->getShiftForUser($shift, $user);

            if (! $shiftModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shift not found',
                ], 404);
            }

            // Check if shift can have handover recorded
            if (! $shiftModel->total_sales) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shift sales must be recorded before handover',
                ], 400);
            }

            // Determine handover type
            $handoverToType = $request->input('handover_to_type');
            $handoverToId = null;
            $handoverToName = null;

            // Check if branch_manager_id is provided (must be filled; ignore null/empty so cashier handover works)
            if ($request->filled('branch_manager_id')) {
                $branchManager = \Modules\BranchManagers\Models\BranchManager::find($request->branch_manager_id);

                if (! $branchManager) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Branch manager not found',
                    ], 404);
                }

                // Verify branch manager belongs to the same branch
                if ($branchManager->branch_id !== $shiftModel->shift->branch_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Branch manager does not belong to this branch',
                    ], 400);
                }

                $handoverToType = 'branch_manager';
                $handoverToId = $branchManager->id;
                $handoverToName = $branchManager->name;
            }
            // Check if handover_to_type is explicitly set to branch_manager
            elseif ($handoverToType === 'branch_manager') {
                $branchManager = \Modules\BranchManagers\Models\BranchManager::where('branch_id', $shiftModel->shift->branch_id)
                    ->where('is_active', true)
                    ->first();

                if (! $branchManager) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No active branch manager found',
                    ], 400);
                }

                $handoverToId = $branchManager->id;
                $handoverToName = $branchManager->name;
            }
            // Default to cashier
            else {
                $handoverToType = 'cashier';

                if (! $request->has('next_cashier_id')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'next_cashier_id is required when handing over to cashier',
                    ], 400);
                }

                $nextCashier = Cashier::find($request->next_cashier_id);
                if (! $nextCashier) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Next cashier not found',
                    ], 404);
                }
                $handoverToId = $nextCashier->id;
                $handoverToName = $nextCashier->name;
            }

            // Record handover
            $handoverData = [
                'handover_to_type' => $handoverToType,
                'handover_to_id' => $handoverToId,
                'next_cashier_id' => $handoverToType === 'cashier' ? $handoverToId : null,
                'handover_amount' => $request->handover_amount,
                'handover_notes' => $request->handover_notes,
            ];

            // Handle variance files
            if ($request->hasFile('variance.supporting_files')) {
                $handoverData['variance_files'] = $request->file('variance.supporting_files');
            }
            if ($request->has('variance.reason')) {
                $handoverData['variance_reason'] = $request->input('variance.reason');
            }

            $updatedShift = $this->handoverService->recordHandover($shiftModel, $handoverData);

            // Record variance if provided
            if ($request->has('variance') && $updatedShift->hasVariance()) {
                $this->varianceService->recordVariance($updatedShift, $request->variance);
            }

            // Calculate variance using the correct formula:
            // Variance = Total Sales - (Cash Collected + Card Payments + Delivery Apps)
            $variance = $shiftModel->calculateVariance();

            // Get handover status from the created handover
            $handoverStatus = 'pending';
            $freshShift = $updatedShift->fresh()->loadFullRelationships();
            if ($freshShift->handoverStatus) {
                $handoverStatus = $this->normalizeHandoverStatus($freshShift->handoverStatus->manager_approval_status ?? 'pending');
            }

            return response()->json([
                'success' => true,
                'message' => 'Handover recorded successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($freshShift),
                    'handover_details' => [
                        'total_sales' => (float) $shiftModel->total_sales,
                        'handover_amount' => (float) $request->handover_amount,
                        'variance' => (float) $variance,
                        'variance_type' => $variance > 0 ? 'Over' : ($variance < 0 ? 'Short' : 'None'),
                        'handover_to_type' => $handoverToType,
                        'handover_to' => $handoverToName,
                        'status' => $handoverStatus,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Start handover failed', [
                'shift_id' => $shift,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to record handover',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate net sales and VAT
     */
    public function calculateSales(Request $request): JsonResponse
    {
        ShiftMoneyValidation::normalizeRepresentationNoise($request);
        $validator = Validator::make($request->all(), [
            'total_sales' => 'required|'.ShiftMoneyValidation::SAR,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $calculation = $this->shiftEndService->calculateNetSales($request->total_sales);

        return response()->json([
            'success' => true,
            'data' => $calculation,
        ]);
    }

    /**
     * Get available cashiers for handover
     */
    public function getAvailableCashiersForHandover(string $shift): JsonResponse
    {
        try {
            $shiftModel = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
                },
                'shift.branch:id,name',
            ])->findOrFail($shift);

            // Cashier caller must own this shift
            $user = auth()->user();
            if ($user instanceof \Modules\Cashier\Models\Cashier
                && (string) $shiftModel->cashier_id !== (string) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift does not belong to you',
                ], 403);
            }

            // Get all active cashiers for this branch except current cashier
            $allCashiers = Cashier::where('branch_id', $shiftModel->shift->branch_id)
                ->where('status', 'active')
                ->where('id', '!=', $shiftModel->cashier_id)
                ->get();

            // Find the next scheduled shift (for auto-handover suggestion)
            $nextShift = CashierShift::where('shift_date', $shiftModel->shift_date)
                ->whereHas('shift', function ($q) use ($shiftModel) {
                    $q->where('branch_id', $shiftModel->shift->branch_id)
                        ->where('start_time', '>=', $shiftModel->shift->end_time);
                })
                ->where('status', ShiftStatus::NOT_STARTED)
                ->orderBy('shift_id')
                ->first();

            $suggestedCashierId = $nextShift?->cashier_id;

            // Get all active branch managers for this branch (handover can be to manager)
            $branchManagers = \Modules\BranchManagers\Models\BranchManager::where('branch_id', $shiftModel->shift->branch_id)
                ->where('is_active', true)
                ->where('status', 'active')
                ->get();

            $availableCashiers = $allCashiers->map(function ($cashier) use ($suggestedCashierId) {
                return [
                    'id' => $cashier->id,
                    'name' => $cashier->name,
                    'image' => $cashier->image ? asset('storage/'.$cashier->image) : null,
                    'type' => 'cashier',
                    'is_available' => true,
                    'disabled' => false,
                    'reason_disabled' => null,
                    'is_suggested' => $cashier->id === $suggestedCashierId,
                    'suggestion_reason' => $cashier->id === $suggestedCashierId
                        ? 'Next scheduled cashier (auto-handover)'
                        : null,
                ];
            });

            $recipients = $availableCashiers->toArray();
            $recipientIds = [];
            foreach ($branchManagers as $branchManager) {
                $recipients[] = [
                    'id' => $branchManager->id,
                    'name' => $branchManager->name.' (Branch Manager)',
                    'image' => $branchManager->image ? asset('storage/'.$branchManager->image) : null,
                    'type' => 'branch_manager',
                    'is_available' => true,
                    'disabled' => false,
                    'reason_disabled' => null,
                    'is_suggested' => false,
                    'suggestion_reason' => 'Final handover to Branch Manager',
                ];
                $recipientIds[(string) $branchManager->id] = true;
            }

            // Always include logged-in branch manager for this branch (so they can receive handover even if not in active list)
            $authUser = auth()->user();
            if ($authUser instanceof \Modules\BranchManagers\Models\BranchManager
                && (string) $authUser->branch_id === (string) $shiftModel->shift->branch_id
                && empty($recipientIds[(string) $authUser->id])) {
                $recipients[] = [
                    'id' => $authUser->id,
                    'name' => $authUser->name.' (Branch Manager)',
                    'image' => $authUser->image ? asset('storage/'.$authUser->image) : null,
                    'type' => 'branch_manager',
                    'is_available' => true,
                    'disabled' => false,
                    'reason_disabled' => null,
                    'is_suggested' => false,
                    'suggestion_reason' => 'Final handover to Branch Manager',
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Available recipients retrieved successfully',
                'data' => [
                    'recipients' => $recipients,
                    'auto_handover_enabled' => ! is_null($suggestedCashierId),
                    'has_branch_manager' => $branchManagers->isNotEmpty(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available recipients',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ensure each aggregator is selected at most once per shift.
     */
    private function validateUniqueAggregators(\Illuminate\Validation\Validator $validator, array $aggregators): void
    {
        if (empty($aggregators)) {
            return;
        }

        $ids = collect($aggregators)->pluck('aggregator_id')->filter();
        if ($ids->count() !== $ids->unique()->count()) {
            $validator->errors()->add(
                'aggregators',
                'Each aggregator can only be selected once per shift.'
            );
        }
    }

    /**
     * Normalize handover status to standard values: pending, accepted, rejected
     */
    private function normalizeHandoverStatus(?string $status): string
    {
        if (in_array($status, ['approved', 'accepted', 'completed'])) {
            return 'accepted';
        }

        if (in_array($status, ['rejected', 'rejected_final'])) {
            return 'rejected';
        }

        return 'pending';
    }

    /**
     * Helper: Get shift for current user (supports both cashier and manager)
     */
    private function getShiftForUser(string $shiftId, $user): ?CashierShift
    {
        $query = CashierShift::with([
            'shift' => function ($q) {
                $q->select(['id', 'name', 'start_time', 'end_time', 'branch_id']);
            },
            'shift.branch:id,name,location',
            'cashier:id,name,branch_id',
            'nextCashier:id,name',
        ]);

        // If user is cashier, only show their shifts
        if ($user instanceof \Modules\Cashier\Models\Cashier) {
            return $query->where('id', $shiftId)
                ->where('cashier_id', $user->id)
                ->first();
        }

        // If user is branch manager, show shifts for their branch
        if ($user instanceof \Modules\BranchManagers\Models\BranchManager) {
            return $query->where('id', $shiftId)
                ->whereHas('shift', function ($q) use ($user) {
                    $q->where('branch_id', $user->branch_id);
                })
                ->first();
        }

        // Fallback for admin or other users
        return $query->find($shiftId);
    }
}
