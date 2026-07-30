<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Services\BranchManagerShiftService;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Transformers\BranchManagerShiftResource;

class BranchManagerShiftController extends BaseController
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const DATE_FORMAT = 'Y-m-d';

    private const TIME_FORMAT = 'H:i:s';

    private const TIME_SHORT_FORMAT = 'H:i';

    public function __construct(
        private BranchManagerShiftService $shiftService,
        private HandoverService $handoverService
    ) {}

    // =========================================================================
    // Section A – Start Shift
    // =========================================================================

    public function start(Request $request): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->firstOrFail();

            if (! $managerShift->canStart()) {
                return $this->errorResponse('Cannot start shift. Current status: '.$managerShift->status, 400);
            }

            $managerShift->update([
                'status' => 'in_progress',
                'actual_start_time' => now(),
            ]);

            $progress = $this->shiftService->calculateShiftProgress($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'progress' => $progress,
                'message' => 'Shift started successfully',
            ], 'Shift started successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Section B – Shift Details & History
    // =========================================================================

    public function getShiftDetails(Request $request): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->select([
                    'id', 'branch_manager_id', 'branch_id', 'shift_date',
                    'status', 'actual_start_time', 'actual_end_time',
                    'next_manager_id', 'approved_by_id', 'approved_by_type',
                ])
                ->with([
                    'branch:id,name',
                    'nextManager:id,name',
                    'approvedBy:id,name',
                ])
                ->when(
                    $request->has('shift_id'),
                    fn ($q) => $q->where('id', $request->shift_id),
                    fn ($q) => $q->whereDate('shift_date', today())
                )
                ->firstOrFail();

            $details = [
                'assigned_to' => 'Me (Branch Manager)',
                'assigned_by' => 'Brand Owner',
                'store_branch' => $managerShift->branch->name,
                'start_time' => $managerShift->actual_start_time?->format(self::TIME_SHORT_FORMAT),
                'end_time' => $managerShift->actual_end_time?->format(self::TIME_SHORT_FORMAT),
                'final_approval_by' => $managerShift->approvedBy?->name ?? 'Pending',
                'status' => $managerShift->status,
                'shift_date' => $managerShift->shift_date->format(self::DATE_FORMAT),
            ];

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'details' => $details,
            ], 'Shift details retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getShiftHistory(Request $request): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $query = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->select([
                    'id', 'branch_manager_id', 'branch_id', 'shift_date',
                    'status', 'actual_start_time', 'actual_end_time',
                    'next_manager_id', 'created_at', 'updated_at',
                ])
                ->with(['branch:id,name,location', 'nextManager:id,name'])
                ->orderBy('shift_date', 'desc');

            $this->applyShiftFilters($query, $request);

            $shifts = $query->paginate($request->input('per_page', 10));
            $this->shiftService->attachHandoffsAndFinancialSummariesForCollection($shifts->getCollection());

            return $this->paginatedResponse(
                BranchManagerShiftResource::collection($shifts),
                'Shifts history retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Section A – Shift Overview (current workday)
    // =========================================================================

    public function current(Request $request): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $managerShift = BranchManagerShift::firstOrCreate(
                ['branch_manager_id' => $manager->id, 'shift_date' => today()],
                [
                    'branch_id' => $manager->branch_id,
                    'status' => 'not_started',
                    'opening_balance' => $this->shiftService->resolveBranchManagerOpeningBalance($manager->branch_id),
                ]
            );

            $managerShift->load(['branch:id,name', 'nextManager:id,name']);

            $progress = $this->shiftService->calculateShiftProgress($managerShift);
            $handoversSummary = $managerShift->getHandoverSummary();
            $handoversToManager = $this->shiftService->getShiftHandovers($managerShift, 'to_manager', true);
            $handoffsToManager = $handoversToManager->map(fn ($h) => $this->shiftService->transformHandover($h));

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'shift_progress' => [
                    'title' => $progress['title'],
                    'description' => $progress['description'],
                    'status' => $progress['status'],
                    'start_time' => $progress['start_time'],
                    'end_time' => $progress['end_time'],
                    'number_of_hours' => $progress['elapsed_hours'],
                    // Planned workday = sum of the branch's shift hours (FR: the
                    // manager covers every shift of the day).
                    'planned_hours' => $progress['planned_hours'],
                    'shifts_count' => $progress['shifts_count'],
                    'progress_percentage' => $progress['progress_percentage'],
                ],
                'handovers_summary' => $handoversSummary,
                'handovers_details' => [
                    'to_branch_manager' => $handoffsToManager,
                ],
                'can_start' => $managerShift->canStart(),
                'can_end' => $managerShift->canEnd(),
                'pending_handovers' => $handoversSummary['pending'],
            ], 'Current shift retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Section C – Handoffs
    // =========================================================================

    public function getHandoffsReceived(Request $request): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->firstOrFail();

            // Fresh, like workday/current: this IS the approve/reject surface, and
            // a 5-minute cached list makes a just-submitted handover look lost.
            $handoversToManager = $this->shiftService->getShiftHandovers($managerShift, 'to_manager', true);
            $cashierToCashier = $this->shiftService->getShiftHandovers($managerShift, 'between_cashiers', true);
            $handoffsToManager = $handoversToManager->map(fn ($h) => $this->shiftService->transformHandover($h));
            $cashierToCashierXfrm = $cashierToCashier->map(fn ($h) => $this->shiftService->transformHandover($h));
            $summary = $managerShift->getHandoverSummary();

            return $this->successResponse([
                'handoffs' => [
                    'to_branch_manager' => $handoffsToManager,
                    'between_cashiers' => $cashierToCashierXfrm,
                ],
                'summary' => $summary,
                'can_end_shift' => $managerShift->canEnd(),
            ], 'Handoffs retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function approveHandoff(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_id' => 'required|exists:cashier_shift_handovers,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();
            $handover = CashierShiftHandover::with('handoverTo')->findOrFail($request->handover_id);

            // Branch-scoped: any manager of the branch may approve a handover
            // addressed to a branch manager (branch ownership verified below).
            if ($handover->handover_to_type !== 'branch_manager') {
                return $this->errorResponse('Unauthorized to approve this handover', 403);
            }

            if (! $handover->canApprove()) {
                return $this->errorResponse('Handover cannot be approved. Current status: '.$handover->status, 400);
            }

            $cashierShift = CashierShift::with(['handoverStatus', 'shift', 'cashier', 'varianceDetails'])
                ->findOrFail($handover->cashier_shift_id);

            if ($cashierShift->shift->branch_id !== $manager->branch_id) {
                return $this->errorResponse('Unauthorized to approve this handover', 403);
            }

            $this->handoverService->approveHandover(
                $cashierShift,
                $manager->id,
                get_class($manager),
                null
            );

            $handover->refresh();
            $normalizedStatus = $this->shiftService->normalizeHandoverStatus($handover->status);

            return $this->successResponse([
                'handover' => array_merge($handover->toArray(), ['status' => $normalizedStatus]),
                'message' => 'Handoff approved successfully',
            ], 'Handoff approved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function rejectHandoff(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_id' => 'required|exists:cashier_shift_handovers,id',
            'rejection_reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();
            $handover = CashierShiftHandover::with('handoverTo')->findOrFail($request->handover_id);

            // Branch-scoped: any manager of the branch may reject a handover
            // addressed to a branch manager (branch ownership verified below).
            if ($handover->handover_to_type !== 'branch_manager') {
                return $this->errorResponse('Unauthorized to reject this handover', 403);
            }

            if (! $handover->canReject()) {
                return $this->errorResponse('Handover cannot be rejected. Current status: '.$handover->status, 400);
            }

            $cashierShift = CashierShift::with(['handoverStatus', 'shift'])
                ->findOrFail($handover->cashier_shift_id);

            if ($cashierShift->shift->branch_id !== $manager->branch_id) {
                return $this->errorResponse('Unauthorized to reject this handover', 403);
            }

            $result = $this->handoverService->rejectHandover(
                $cashierShift,
                $manager->id,
                get_class($manager),
                $request->rejection_reason,
                [],
                null
            );

            $handoverAfter = CashierShiftHandover::find($request->handover_id);
            $handoverPayload = $handoverAfter
                ? array_merge($handoverAfter->toArray(), [
                    'status' => $this->shiftService->normalizeHandoverStatus($handoverAfter->status),
                ])
                : [
                    'id' => $request->handover_id,
                    'status' => 'reverted',
                    'note' => 'Handover record cleared; cashier shift reset to in progress',
                ];

            return $this->successResponse([
                'handover' => $handoverPayload,
                'rejection_count' => $result['rejection_count'],
                'is_final_rejection' => $result['is_final_rejection'],
                'message' => $result['is_final_rejection']
                    ? 'Handoff rejected permanently (2nd rejection)'
                    : 'Handoff rejected. Shift reverted to in progress; cashier must end shift again.',
            ], 'Handoff rejected successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getRejectionDetails(string $shift): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $shiftModel = CashierShift::select(['id', 'cashier_id', 'shift_id'])
                ->with([
                    'cashier:id,name',
                    'handoverStatus:id,cashier_shift_id,rejection_reason,rejection_count,rejection_file_urls,first_rejected_at,second_rejected_at,manager_comment,reviewed_by_id,reviewed_by_type,reviewed_at',
                    'handoverStatus.reviewedBy:id,name',
                    'shift:id,name,branch_id',
                ])
                ->findOrFail($shift);

            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return $this->errorResponse('Unauthorized to view this rejection', 403);
            }

            $handoverStatus = $shiftModel->handoverStatus;

            if (! $handoverStatus) {
                return $this->errorResponse('No handover found for this shift', 404);
            }

            if (! $handoverStatus->isManagerRejected()) {
                return $this->errorResponse('This handover is not rejected', 400);
            }

            return $this->successResponse([
                'rejection_details' => [
                    'cashier_name' => $shiftModel->cashier->name,
                    'cashier_id' => $shiftModel->cashier_id,
                    'shift_id' => $shiftModel->id,
                    'rejection_reason' => $handoverStatus->rejection_reason,
                    'rejection_count' => $handoverStatus->rejection_count,
                    'is_final_rejection' => $handoverStatus->isPermanentlyRejected(),
                    'rejection_files' => $handoverStatus->rejection_file_urls,
                    'first_rejected_at' => $handoverStatus->first_rejected_at?->format(self::DATETIME_FORMAT),
                    'second_rejected_at' => $handoverStatus->second_rejected_at?->format(self::DATETIME_FORMAT),
                    'manager_comment' => $handoverStatus->manager_comment,
                    'reviewed_by' => $handoverStatus->reviewedBy?->name,
                    'reviewed_at' => $handoverStatus->reviewed_at?->format(self::DATETIME_FORMAT),
                ],
                'can_approve_rejection' => ! $handoverStatus->isPermanentlyRejected(),
                'can_request_corrections' => $handoverStatus->rejection_count === 1,
            ], 'Rejection details retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function processRejectionDecision(Request $request, string $shift): JsonResponse
    {
        $jsonData = $request->json()->all();
        $requestData = ! empty($jsonData) ? $jsonData : $request->all();

        $validator = Validator::make($requestData, [
            'decision' => 'nullable|in:approve_rejection,request_corrections',
            'manager_comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $shiftModel = CashierShift::select(['id', 'cashier_id', 'shift_id'])
                ->with([
                    'cashier:id,name',
                    'handoverStatus:id,cashier_shift_id,rejection_reason,rejection_count,manager_approval_status,first_rejected_at,second_rejected_at,manager_comment,reviewed_by_id,reviewed_by_type,reviewed_at',
                    'shift:id,name,branch_id',
                ])
                ->findOrFail($shift);

            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return $this->errorResponse('Unauthorized to process this rejection', 403);
            }

            $handoverStatus = $shiftModel->handoverStatus;

            if (! $handoverStatus) {
                return $this->errorResponse('No handover found for this shift', 404);
            }

            if (! $handoverStatus->isManagerRejected()) {
                return $this->errorResponse('This handover is not rejected', 400);
            }

            if ($handoverStatus->isPermanentlyRejected()) {
                return $this->errorResponse('This rejection is already final and cannot be modified', 400);
            }

            $decision = $requestData['decision'] ?? $request->decision;
            $comment = $requestData['manager_comment'] ?? $request->manager_comment;

            if ($decision === 'approve_rejection') {
                return $this->applyApproveRejection($handoverStatus, $shiftModel, $comment, $manager);
            }

            return $this->applyRequestCorrections($handoverStatus, $shiftModel, $comment, $manager);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Section D – End Shift
    // =========================================================================

    public function endShift(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_to' => 'nullable|exists:branch_managers,id',
            'handover_amount' => 'nullable|numeric|min:0',
            'handover_timing' => 'required|in:today,yesterday',
            'handover_notes' => 'nullable|string|max:500',
            'total_sales' => 'nullable|numeric',
            'cash_collected' => 'nullable|numeric|min:0',
            'card_payments' => 'nullable|numeric|min:0',
            'aggregator_payments' => 'nullable|numeric|min:0',
            'cashier_breakdown' => 'nullable|array',
            'cashier_breakdown.*.cashier_id' => 'required_with:cashier_breakdown|exists:cashiers,id',
            'cashier_breakdown.*.cash_collected' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.card_payments' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.delivery_app_payments' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.variance' => 'nullable|numeric',
            'cashier_breakdown.*.sales' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->firstOrFail();

            if (! $managerShift->canEnd()) {
                return $this->errorResponse('Cannot end shift. Check all cashier handoffs are approved.', 400);
            }

            DB::beginTransaction();

            try {
                if ($request->has('cashier_breakdown') && is_array($request->cashier_breakdown)) {
                    $this->shiftService->bulkUpdateCashierShifts($request->cashier_breakdown, $managerShift);
                }

                $financialSummary = $this->shiftService->calculateFinancialSummary($managerShift);
                $updatedHandovers = $this->shiftService->getShiftHandovers($managerShift, 'to_manager');
                $financial = $this->shiftService->resolveFinancialValues($request, $financialSummary, $managerShift);
                $handoverAmount = $request->handover_amount !== null
                    ? (float) $request->handover_amount
                    : $this->shiftService->sumApprovedHandoverAmount($managerShift);
                $closingBalance = $handoverAmount;
                $handoverTime = $request->handover_timing === 'yesterday' ? now()->subDay() : now();

                $updateData = [
                    'status' => 'completed',
                    'actual_end_time' => now(),
                    'handover_date' => $handoverTime->format(self::DATE_FORMAT),
                    'handover_time' => $handoverTime,
                    'handover_timing' => $request->handover_timing,
                    'handover_status' => 'pending',
                    'handover_amount' => $handoverAmount,
                    'closing_balance' => $closingBalance,
                    'total_sales' => $financial['total_sales'],
                    'net_sales' => $financial['net_sales'],
                    'vat_amount' => $financial['vat_amount'],
                    'cash_collected' => $financial['cash_collected'],
                    'card_payments' => $financial['card_payments'],
                    'aggregator_payments' => $financial['aggregator_payments'],
                ];

                if ($request->has('handover_to')) {
                    $updateData['next_manager_id'] = $request->handover_to;
                }

                if ($request->has('handover_notes')) {
                    $updateData['handover_notes'] = $request->handover_notes;
                }

                $managerShift->update($updateData);

                $handoverStatus = $this->shiftService->normalizeHandoverStatus($managerShift->handover_status);
                $currentTime = $handoverTime->format(self::DATETIME_FORMAT);

                $this->shiftService->clearShiftCaches($managerShift);
                $managerShift->refresh();
                $managerShift->load('nextManager');

                $cashierBreakdownResponse = $this->shiftService->buildCashierBreakdownFromHandovers($updatedHandovers);
                $accountantName = $this->shiftService->responsibleAccountantNameForBranch($managerShift->branch_id);

                DB::commit();

                return $this->successResponse([
                    'shift' => new BranchManagerShiftResource($managerShift),
                    'final_handover' => [
                        'handover_amount' => (float) ($managerShift->handover_amount ?? $closingBalance),
                        'status' => $handoverStatus,
                        'status_options' => ['pending', 'accepted', 'rejected'],
                        'handover_from' => $manager->name,
                        'handover_to' => $managerShift->nextManager?->name ?? $accountantName ?? 'Not specified',
                        'accountant_name' => $accountantName,
                        'handover_date' => $managerShift->handover_date?->format(self::DATE_FORMAT) ?? now()->format(self::DATE_FORMAT),
                        'handover_time' => $managerShift->handover_time?->format(self::TIME_FORMAT) ?? now()->format(self::TIME_FORMAT),
                        'current_time' => $currentTime,
                        'current_time_setting' => $request->handover_timing,
                        'handover_notes' => $managerShift->handover_notes,
                    ],
                    'daily_totals' => [
                        'total_cash_collected' => $financial['cash_collected'],
                        'total_card_payments' => $financial['card_payments'],
                        'total_delivery_apps' => $financial['aggregator_payments'],
                        'total_variance' => $financial['total_variance'],
                        'total_sales' => $financial['total_sales'],
                        'shift_date' => $managerShift->shift_date->format(self::DATE_FORMAT),
                    ],
                    'cashier_breakdown' => $cashierBreakdownResponse,
                    'message' => 'Shift ended and handover recorded successfully',
                ], 'Shift ended successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Section E – Final Daily Close
    // =========================================================================

    public function getFinalDailyClose(Request $request): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->when(
                    $request->has('shift_id'),
                    fn ($q) => $q->where('id', $request->shift_id),
                    fn ($q) => $q->whereDate('shift_date', today())
                )
                ->firstOrFail();

            $dailyClose = $this->shiftService->prepareDailyCloseSummary($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'cashier_breakdown' => $dailyClose['cashier_breakdown'],
                'aggregators' => $dailyClose['aggregators'] ?? [],
                'totals' => $dailyClose['totals'],
                'daily_close_status' => $this->shiftService->buildDailyCloseStatusArray($managerShift),
                'manager_summary' => $dailyClose['manager_summary'] ?? null,
                'shift_info' => $dailyClose['shift_info'] ?? null,
            ], 'Final daily close summary retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function updateFinalDailyClose(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_to' => 'nullable|exists:branch_managers,id',
            'handover_amount' => 'nullable|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
            'total_sales' => 'nullable|numeric',
            'cash_collected' => 'nullable|numeric|min:0',
            'card_payments' => 'nullable|numeric|min:0',
            'aggregator_payments' => 'nullable|numeric|min:0',
            'cashier_breakdown' => 'nullable|array',
            'cashier_breakdown.*.cashier_id' => 'required_with:cashier_breakdown|exists:cashiers,id',
            'cashier_breakdown.*.cash_collected' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.card_payments' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.delivery_app_payments' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.variance' => 'nullable|numeric',
            'cashier_breakdown.*.sales' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->firstOrFail();

            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed to update daily close', 400);
            }

            DB::beginTransaction();

            try {
                if ($request->has('cashier_breakdown') && is_array($request->cashier_breakdown)) {
                    $this->shiftService->bulkUpdateCashierShifts($request->cashier_breakdown, $managerShift);
                }

                $financialSummary = $this->shiftService->calculateFinancialSummary($managerShift);
                $updatedHandovers = $this->shiftService->getShiftHandovers($managerShift, 'to_manager');
                $financial = $this->shiftService->resolveFinancialValues($request, $financialSummary, $managerShift);
                $handoverAmount = $request->handover_amount ?? $managerShift->handover_amount;

                if ($handoverAmount === null) {
                    $handoverAmount = $this->shiftService->sumApprovedHandoverAmount($managerShift);
                }

                $closingBalance = (float) $handoverAmount;

                $updateData = [
                    'handover_amount' => $handoverAmount,
                    'closing_balance' => $closingBalance,
                    'total_sales' => $financial['total_sales'],
                    'net_sales' => $financial['net_sales'],
                    'vat_amount' => $financial['vat_amount'],
                    'cash_collected' => $financial['cash_collected'],
                    'card_payments' => $financial['card_payments'],
                    'aggregator_payments' => $financial['aggregator_payments'],
                ];

                if ($request->has('handover_to')) {
                    $updateData['next_manager_id'] = $request->handover_to;
                }

                if ($request->has('handover_notes')) {
                    $updateData['handover_notes'] = $request->handover_notes;
                }

                $managerShift->update($updateData);
                $this->shiftService->clearShiftCaches($managerShift);
                $managerShift->refresh();
                $managerShift->load('nextManager');

                $cashierBreakdownResponse = $this->shiftService->buildCashierBreakdownFromHandovers($updatedHandovers);

                DB::commit();

                return $this->successResponse([
                    'shift' => new BranchManagerShiftResource($managerShift),
                    'cashier_breakdown' => $cashierBreakdownResponse,
                    'daily_totals' => [
                        'total_cash_collected' => $financial['cash_collected'],
                        'total_card_payments' => $financial['card_payments'],
                        'total_delivery_apps' => $financial['aggregator_payments'],
                        'total_variance' => $financial['total_variance'],
                        'total_sales' => $financial['total_sales'],
                        'shift_date' => $managerShift->shift_date->format(self::DATE_FORMAT),
                    ],
                    'daily_close_status' => $this->shiftService->buildDailyCloseStatusArray($managerShift),
                    'message' => 'Final daily close updated successfully',
                ], 'Final daily close updated successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function submitDailyReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'final_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->where('status', 'completed')
                ->firstOrFail();

            if ($managerShift->daily_report_submitted) {
                return $this->errorResponse('Daily report already submitted', 400);
            }

            DB::transaction(function () use ($managerShift, $request) {
                $managerShift->update([
                    'daily_report_submitted' => true,
                    'daily_report_submitted_at' => now(),
                    'daily_report_notes' => $request->final_notes,
                    'can_reopen' => true,
                ]);

                // Sweep every approved handover included in this close (today's
                // plus carried-over unclosed days) so it is never re-included
                // in a future daily close.
                $includedIds = $this->shiftService
                    ->getShiftHandovers($managerShift, 'to_manager', true)
                    ->where('status', 'approved')
                    ->pluck('id');

                if ($includedIds->isNotEmpty()) {
                    CashierShiftHandover::whereIn('id', $includedIds)
                        ->whereNull('daily_closed_at')
                        ->update(['daily_closed_at' => now()]);
                }
            });

            $this->shiftService->clearShiftCaches($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'message' => 'Daily report submitted successfully. Waiting for Sales Team approval.',
            ], 'Daily report submitted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function reopenShift(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reopen_reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->firstOrFail();

            if (! $managerShift->can_reopen) {
                return $this->errorResponse('This shift cannot be reopened', 400);
            }

            if (! $managerShift->daily_report_submitted) {
                return $this->errorResponse('Shift must be submitted first', 400);
            }

            DB::transaction(function () use ($managerShift, $request) {
                $managerShift->update([
                    'daily_report_submitted' => false,
                    'daily_report_submitted_at' => null,
                    'reopened_at' => now(),
                    'reopen_reason' => $request->reopen_reason,
                    'can_reopen' => false,
                ]);
            });

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'message' => 'Shift reopened successfully. You can now make changes and resubmit.',
            ], 'Shift reopened successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Handover Detail Endpoints
    // =========================================================================

    public function getCashierHandoverDetails(string $handoverId): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $handover = CashierShiftHandover::where('id', $handoverId)
                ->select([
                    'id', 'cashier_shift_id', 'handover_to_id', 'handover_to_type',
                    'handover_amount', 'variance_amount', 'variance_reason', 'variance_files',
                    'status', 'rejection_reason', 'rejection_count', 'handed_over_at',
                    'approved_at', 'approved_by_id', 'approved_by_type',
                    'handover_date', 'handover_time', 'handover_notes',
                ])
                ->with([
                    'cashierShift:id,cashier_id,shift_id,total_sales,net_sales,vat_amount,cash_collected,card_payments',
                    'cashierShift.cashier:id,name',
                    'cashierShift.shift:id,name,branch_id',
                    'cashierShift.salesBreakdown:id,cashier_shift_id,aggregator_id,amount',
                    'cashierShift.salesBreakdown.aggregator:id,name',
                    'cashierShift.varianceDetails:id,cashier_shift_id,responsible_cashier_id,assigned_amount,reason',
                    'cashierShift.varianceDetails.responsibleCashier:id,name',
                    'cashierShift.handoverStatus.reviewedBy:id,name',
                    'handoverTo:id,name',
                    'approvedBy:id,name',
                ])
                ->firstOrFail();

            $cashierShift = $handover->cashierShift;
            $shift = $cashierShift->shift;

            $authError = $this->authorizeHandoverAccess($handover, $shift, $manager);
            if ($authError !== null) {
                return $authError;
            }

            $varianceDetails = $this->shiftService->buildHandoverVarianceDetails($handover, $cashierShift);

            $deliveryApps = $cashierShift->salesBreakdown->sum('amount');

            return $this->successResponse([
                'handover' => [
                    'handover_id' => $handover->id,
                    'cashier_shift_id' => $handover->cashier_shift_id,
                    'cashier_name' => $cashierShift->cashier->name,
                    'cashier_id' => $cashierShift->cashier_id,
                    'shift_time' => $shift ? $shift->name : 'N/A',
                    'shift_id' => $shift ? $shift->id : null,
                    'handover_amount' => (float) $handover->handover_amount,
                    'total_sales' => (float) $cashierShift->total_sales,
                    'net_sales' => (float) ($cashierShift->net_sales ?? 0),
                    'vat_amount' => (float) ($cashierShift->vat_amount ?? 0),
                    'cash_collected' => (float) ($cashierShift->cash_collected ?? 0),
                    'card_payments' => (float) ($cashierShift->card_payments ?? 0),
                    'delivery_app_payments' => (float) $deliveryApps,
                    'variance_amount' => (float) $handover->variance_amount,
                    'variance_type' => $this->shiftService->normalizeVarianceType((float) $handover->variance_amount),
                    'variance_reason' => $handover->variance_reason,
                    'attached_files' => $handover->variance_files ?? [],
                    'status' => $handover->status,
                    'rejection_reason' => $handover->rejection_reason,
                    'rejection_count' => $handover->rejection_count,
                    'handed_over_at' => $handover->handed_over_at?->format(self::DATETIME_FORMAT),
                    'approved_at' => $handover->approved_at?->format(self::DATETIME_FORMAT),
                    'approved_by' => $handover->approvedBy?->name,
                    'approved_by_id' => $handover->approved_by_id,
                    'can_approve' => $handover->canApprove(),
                    'can_reject' => $handover->canReject(),
                    'variance_details' => $varianceDetails,
                    'handover_to_type' => $handover->handover_to_type,
                    'handover_to' => $handover->handoverTo?->name ?? 'N/A',
                    'handover_to_id' => $handover->handover_to_id,
                    'handover_date' => $handover->handover_date?->format(self::DATE_FORMAT),
                    'handover_time' => $handover->handover_time?->format(self::TIME_FORMAT),
                    'handover_notes' => $handover->handover_notes,
                    'correction_details' => $cashierShift->handoverStatus
                        ? $this->shiftService->getCorrectionDetails($cashierShift->handoverStatus)
                        : null,
                ],
            ], 'Cashier handover details retrieved successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Handover not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getManagerFinalHandover(string $shiftId): JsonResponse
    {
        try {
            /** @var BranchManager $manager */
            $manager = Auth::user();

            $managerShift = BranchManagerShift::where('id', $shiftId)
                ->where('branch_manager_id', $manager->id)
                ->select([
                    'id', 'branch_manager_id', 'branch_id', 'shift_date', 'status',
                    'total_sales', 'net_sales', 'vat_amount', 'cash_collected',
                    'card_payments', 'aggregator_payments', 'handover_amount',
                    'closing_balance', 'opening_balance', 'handover_status',
                    'handover_timing', 'handover_date', 'handover_time',
                    'handover_notes', 'next_manager_id',
                    'daily_report_submitted', 'daily_report_submitted_at',
                    'daily_report_notes', 'can_reopen', 'reopened_at', 'reopen_reason',
                ])
                ->with(['branchManager:id,name', 'nextManager:id,name', 'branch:id,name'])
                ->firstOrFail();

            $financialSummary = $this->shiftService->calculateFinancialSummary($managerShift);
            $handovers = $this->shiftService->getShiftHandovers($managerShift, 'to_manager');
            $cashierBreakdown = $this->shiftService->buildCashierBreakdownFromHandovers($handovers);

            $totalSales = (float) ($managerShift->total_sales > 0 ? $managerShift->total_sales : ($financialSummary['total_sales'] ?? 0));
            $cashCollected = (float) ($managerShift->cash_collected > 0 ? $managerShift->cash_collected : ($financialSummary['cash_collected'] ?? 0));
            $cardPayments = (float) ($managerShift->card_payments > 0 ? $managerShift->card_payments : ($financialSummary['card_payments'] ?? 0));
            $aggregatorPayments = (float) ($managerShift->aggregator_payments > 0 ? $managerShift->aggregator_payments : ($financialSummary['delivery_app_payments'] ?? 0));
            $vatAmount = (float) ($managerShift->vat_amount > 0 ? $managerShift->vat_amount : ($totalSales * 0.15));
            $netSales = (float) ($managerShift->net_sales > 0 ? $managerShift->net_sales : ($totalSales - $vatAmount));
            $closingBalance = (float) ($managerShift->handover_amount ?? $managerShift->closing_balance ?? 0);
            $variance = $totalSales - $closingBalance;
            $handoverStatus = $this->shiftService->normalizeHandoverStatus($managerShift->handover_status);
            $accountantName = $this->shiftService->responsibleAccountantNameForBranch($managerShift->branch_id);
            $currentTime = $managerShift->handover_timing === 'yesterday'
                ? now()->subDay()->format(self::DATETIME_FORMAT)
                : now()->format(self::DATETIME_FORMAT);

            return $this->successResponse([
                'shift' => [
                    'id' => $managerShift->id,
                    'shift_date' => $managerShift->shift_date->format(self::DATE_FORMAT),
                    'status' => $managerShift->status,
                    'manager_name' => $managerShift->branchManager->name,
                    'manager_id' => $managerShift->branch_manager_id,
                    'branch_name' => $managerShift->branch->name,
                    'branch_id' => $managerShift->branch_id,
                ],
                'handover' => [
                    'handover_amount' => (float) ($managerShift->handover_amount ?? 0),
                    'status' => $handoverStatus,
                    'status_options' => ['pending', 'accepted', 'rejected'],
                    'handover_from' => $managerShift->branchManager->name,
                    'handover_to' => $managerShift->nextManager?->name ?? $accountantName ?? 'Not specified',
                    'accountant_name' => $accountantName,
                    'handover_to_id' => $managerShift->next_manager_id,
                    'handover_date' => $managerShift->handover_date?->format(self::DATE_FORMAT) ?? now()->format(self::DATE_FORMAT),
                    'handover_time' => $managerShift->handover_time?->format(self::TIME_FORMAT) ?? now()->format(self::TIME_FORMAT),
                    'current_time' => $currentTime,
                    'current_time_setting' => $managerShift->handover_timing ?? 'today',
                    'handover_notes' => $managerShift->handover_notes,
                    'opening_balance' => (float) ($managerShift->opening_balance ?? 0),
                    'closing_balance' => $closingBalance,
                    'expected_balance' => $totalSales,
                    'variance' => $variance,
                    'variance_type' => $this->shiftService->normalizeVarianceType($variance),
                ],
                'financial_summary' => [
                    'total_sales' => $totalSales,
                    'net_sales' => $netSales,
                    'vat_amount' => $vatAmount,
                    'cash_collected' => $cashCollected,
                    'card_payments' => $cardPayments,
                    'aggregator_payments' => $aggregatorPayments,
                    'total_variance' => (float) ($financialSummary['total_variance'] ?? 0),
                ],
                'cashier_breakdown' => $cashierBreakdown,
                'daily_close_status' => $this->shiftService->buildDailyCloseStatusArray($managerShift),
            ], 'Manager final handover details retrieved successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Shift not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Apply optional date/status filters to a shift history query.
     */
    private function applyShiftFilters(Builder $query, Request $request): void
    {
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->input('date_from')) {
            $query->whereDate('shift_date', '>=', $from);
        }

        if ($to = $request->input('date_to')) {
            $query->whereDate('shift_date', '<=', $to);
        }
    }

    /**
     * Handle the "approve_rejection" branch of processRejectionDecision.
     */
    private function applyApproveRejection($handoverStatus, CashierShift $shiftModel, ?string $comment, BranchManager $manager): JsonResponse
    {
        if ($handoverStatus->rejection_count >= 2) {
            return $this->errorResponse('Rejection is already final', 400);
        }

        $handoverStatus->update([
            'manager_approval_status' => 'rejected_final',
            'rejection_count' => 2,
            'second_rejected_at' => now(),
            'manager_comment' => $comment,
            'reviewed_by_id' => $manager->id,
            'reviewed_by_type' => get_class($manager),
            'reviewed_at' => now(),
        ]);

        CashierShiftHandover::where('cashier_shift_id', $shiftModel->id)
            ->update(['status' => 'rejected_final', 'rejection_count' => 2]);

        return $this->successResponse([
            'decision' => 'approve_rejection',
            'message' => 'Rejection approved and finalized. Cashier cannot edit anymore.',
            'rejection_details' => [
                'cashier_name' => $shiftModel->cashier->name,
                'rejection_count' => 2,
                'is_final_rejection' => true,
                'manager_comment' => $comment,
                'processed_at' => now()->format(self::DATETIME_FORMAT),
            ],
        ], 'Rejection approved successfully');
    }

    /**
     * Validate that the authenticated manager is allowed to access the given handover.
     * Returns an error JsonResponse when access is denied, or null when access is granted.
     */
    private function authorizeHandoverAccess(CashierShiftHandover $handover, $shift, BranchManager $manager): ?JsonResponse
    {
        $isManagerHandover = $handover->handover_to_type === 'branch_manager';
        $isCashierHandover = $handover->handover_to_type === 'cashier';

        if (! $isManagerHandover && ! $isCashierHandover) {
            return $this->errorResponse('Invalid handover type', 403);
        }

        // Branch-scoped: both handover types are accessible to any manager of the branch.
        if ($shift->branch_id !== $manager->branch_id) {
            return $this->errorResponse('You do not have access to this handover', 403);
        }

        return null;
    }

    /**
     * Handle the "request_corrections" branch of processRejectionDecision.
     */
    private function applyRequestCorrections($handoverStatus, CashierShift $shiftModel, ?string $comment, BranchManager $manager): JsonResponse
    {
        $handoverStatus->update([
            'manager_comment' => $comment,
            'reviewed_by_id' => $manager->id,
            'reviewed_by_type' => get_class($manager),
            'reviewed_at' => now(),
        ]);

        return $this->successResponse([
            'decision' => 'request_corrections',
            'message' => 'Corrections requested. Cashier can edit and resubmit.',
            'rejection_details' => [
                'cashier_name' => $shiftModel->cashier->name,
                'rejection_count' => $handoverStatus->rejection_count,
                'is_final_rejection' => false,
                'manager_comment' => $comment,
                'can_cashier_edit' => true,
                'processed_at' => now()->format(self::DATETIME_FORMAT),
            ],
        ], 'Corrections requested successfully');
    }
}
