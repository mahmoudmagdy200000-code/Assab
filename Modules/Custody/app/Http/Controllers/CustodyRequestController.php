<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\MobileBranchScopeService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Custody\Services\CustodyRequestService;

class CustodyRequestController extends BaseController
{
    public function __construct(
        private CustodyRequestService $requestService,
        private MobileBranchScopeService $branchScope,
    ) {}

    /**
     * List all custody requests
     * GET /api/custody/requests
     *
     * Brand Owner: lists all custody requests (no branch_manager scope).
     * Branch Manager: lists own requests only.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $timePeriod = $request->input('timePeriod');

            $validTimePeriods = ['last_24_hours', 'last_7_days', 'last_30_days', 'last_90_days', 'last_365_days'];
            if (! empty($timePeriod) && ! in_array($timePeriod, $validTimePeriods)) {
                return $this->errorResponse(
                    'Invalid timePeriod. Must be: last_24_hours, last_7_days, last_30_days, last_90_days, or last_365_days',
                    400
                );
            }

            $status = $request->input('status');
            $preferredReceiptMethod = null;

            if (! empty($status)) {
                $status = trim(str_replace(['+', '_'], ' ', $status));
                $validStatuses = ['Pending', 'Approved', 'Rejected', 'Completed', 'Cancelled'];
                $validMethods = ['Cash Handover', 'Bank Transfer'];

                $lower = strtolower($status);
                if (in_array($lower, ['pending', 'approved', 'rejected', 'completed', 'cancelled'])) {
                    $status = ucfirst($lower);
                } elseif (in_array($status, $validStatuses)) {
                    // pass
                } elseif (in_array($status, $validMethods)) {
                    $preferredReceiptMethod = $status;
                    $status = null;
                } else {
                    return $this->errorResponse(
                        'Invalid status. Must be one of: '.implode(', ', array_merge($validStatuses, $validMethods)),
                        400
                    );
                }
            }

            if (empty($preferredReceiptMethod)) {
                $preferredReceiptMethod = $request->input('preferredReceiptMethod')
                    ?? $request->input('preferred_receipt_method')
                    ?? $request->input('preferredReceipt');

                if (! empty($preferredReceiptMethod)) {
                    $preferredReceiptMethod = trim(str_replace(['+', '_'], ' ', $preferredReceiptMethod));
                    $validMethods = ['Cash Handover', 'Bank Transfer'];
                    if (! in_array($preferredReceiptMethod, $validMethods)) {
                        return $this->errorResponse(
                            'Invalid preferredReceiptMethod. Must be one of: '.implode(', ', $validMethods),
                            400
                        );
                    }
                }
            }

            $user = auth()->user();
            $isBrandOwner = $user instanceof BrandOwner;

            $requests = $this->requestService->listRequests(
                $isBrandOwner ? null : auth()->id(),
                $timePeriod,
                $status,
                $preferredReceiptMethod,
                $isBrandOwner,
                $isBrandOwner ? null : ($user->branch_id ?? null),
                $isBrandOwner ? $user : null
            );

            return $this->successResponse([
                'requests' => $requests,
            ], 'Custody requests retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get request details with timeline
     * GET /api/custody/requests/{requestId}
     */
    public function show(string $requestId): JsonResponse
    {
        try {
            // Tenant isolation (read side): a branch manager reads only the requests addressed to / raised by
            // themselves; any other actor that is not a brand owner/manager gets the same 404 as an unknown id,
            // so another branch's request (amount, purpose, attachments) is neither readable nor confirmable.
            // Brand owners and brand managers keep their existing access (their own scoping is a separate decision).
            $user = auth()->user();
            if ($user instanceof BrandOwner) {
                // F-09: brand owners / brand managers reach only the requests of their own brands (fail-closed).
                if (! $this->requestService->ownerCanAccess($requestId, $user)) {
                    return $this->errorResponse('Custody request not found', 404);
                }
            } else {
                $owned = $user instanceof BranchManager
                    && \Modules\Custody\Models\CustodyRequest::whereKey($requestId)->where('branch_manager_id', $user->id)->exists();
                if (! $owned) {
                    return $this->errorResponse('Custody request not found', 404);
                }
            }

            $details = $this->requestService->getRequestDetails($requestId);

            return $this->successResponse($details, 'Request details retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Create new cash-in request
     * POST /api/custody/request-cashin
     *
     * multipart/form-data:
     *   requestedAmount         number (required unless reuseRequestId)
     *   purpose                 string (required unless reuseRequestId)
     *   preferredReceiptMethod  cash_handover | bank_transfer
     *                           (also accepts "Cash Handover" / "Bank Transfer" for compatibility)
     *   branchId                UUID    (required for a brand-level actor; must be in their server-resolved scope)
     *   additionalNotes         string (optional)
     *   attachments[]           file   (optional)
     *   reuseRequestId          string (optional — branch-manager only)
     */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        $brandOwnerRequest = $user instanceof BrandOwner;
        $reuseId = $request->input('reuseRequestId');
        $rules = [
            'requestedAmount' => 'required_without:reuseRequestId|numeric|min:1|max:1000000',
            'purpose' => 'required_without:reuseRequestId|string|min:3|max:500',
            'preferredReceiptMethod' => 'required_without:reuseRequestId|in:cash_handover,bank_transfer,Cash Handover,Bank Transfer',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,docx|max:5120',
            'additionalNotes' => 'nullable|string|max:1000',
            'reuseRequestId' => 'nullable|exists:custody_requests,id',
            'branchId' => $brandOwnerRequest
                ? 'required_without:reuseRequestId|uuid|exists:branches,id'
                : 'nullable|uuid|exists:branches,id',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            if (! $user instanceof BrandOwner && ! $user instanceof BranchManager) {
                return $this->errorResponse('Only a brand-level actor or branch manager can create a custody request', 403);
            }
            $isBrandOwner = $user instanceof BrandOwner;

            $branchId = $isBrandOwner ? $request->input('branchId') : ($user->branch_id ?? null);
            $branchManagerId = $isBrandOwner ? null : $user->id;
            if ($isBrandOwner) {
                if (! $branchId && $reuseId) {
                    $previous = \Modules\Custody\Models\CustodyRequest::query()
                        ->whereKey($reuseId)
                        ->where('created_by_brand_owner_id', $user->id)
                        ->first();
                    $branchId = $previous?->branch_id;
                }
                if (! $branchId || ! in_array((string) $branchId, array_map('strval', $this->branchScope->visibleBranchIds($user)), true)) {
                    return $this->errorResponse('Target branch not found', 404);
                }
                $targetBranch = Branch::query()->whereKey($branchId)->first();
                $targetManager = $targetBranch?->branchManager()->where('is_active', true)->first();
                if ($targetBranch === null || $targetManager === null) {
                    return $this->errorResponse('Target branch has no active branch manager', 422);
                }
                $branchManagerId = $targetManager->id;
            }

            $method = $request->input('preferredReceiptMethod');
            $normalizedMethod = $this->normalizeReceiptMethod($method);

            $data = [
                'branch_manager_id' => $branchManagerId,
                'branch_id' => $branchId,
                'created_by_brand_owner_id' => $isBrandOwner ? $user->id : null,
                'requestedAmount' => $request->input('requestedAmount'),
                'purpose' => $request->input('purpose'),
                'preferredReceiptMethod' => $normalizedMethod,
                'additionalNotes' => $request->input('additionalNotes'),
                'attachments' => $request->file('attachments', []),
            ];

            if ($reuseId) {
                $previousRequest = \Modules\Custody\Models\CustodyRequest::find($reuseId);
                if (! $previousRequest) {
                    return $this->errorResponse('Previous request not found.', 404);
                }
                $owns = $isBrandOwner
                    ? $previousRequest->created_by_brand_owner_id === $user->id
                    : $previousRequest->branch_manager_id === $user->id;
                if (! $owns) {
                    return $this->errorResponse('You are not allowed to reuse this request.', 403);
                }

                $data['requestedAmount'] = $request->input('requestedAmount', $previousRequest->requested_amount);
                $data['purpose'] = $request->input('purpose', $previousRequest->purpose);
                $data['preferredReceiptMethod'] = $normalizedMethod ?: $previousRequest->preferred_receipt_method;
                if ($request->input('additionalNotes') === null || $request->input('additionalNotes') === '') {
                    $data['additionalNotes'] = $previousRequest->additional_notes;
                }
            }

            $custodyRequest = $this->requestService->createRequest($data);

            return $this->createdResponse([
                'requestId' => $custodyRequest->id,
                'status' => $this->normalizeStatus($custodyRequest->status),
                'submittedAt' => $custodyRequest->created_at?->toIso8601String(),
            ], 'Cash-in request submitted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    private function normalizeReceiptMethod(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return match (strtolower($value)) {
            'cash_handover', 'cash handover' => 'Cash Handover',
            'bank_transfer', 'bank transfer' => 'Bank Transfer',
            default => $value,
        };
    }

    /**
     * Get previous requests for reuse / Owner Payment Form history
     * GET /api/custody/request-cashin/history
     */
    public function getHistory(): JsonResponse
    {
        try {
            $user = auth()->user();
            $history = $user instanceof BrandOwner
                ? $this->requestService->getBrandOwnerRequestHistory($user)
                : $this->requestService->getRequestHistory(auth()->id());

            return $this->successResponse([
                'previousRequests' => $history,
            ], 'Request history retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Approve a custody request (Brand Owner only)
     * POST /api/custody/requests/{requestId}/approve
     */
    public function approve(string $requestId): JsonResponse
    {
        $user = auth()->user();
        if (! ($user instanceof BrandOwner)) {
            return $this->errorResponse('Only brand owners can approve custody requests', 403);
        }

        try {
            $request = $this->requestService->approveRequest($requestId, $user);

            return $this->successResponse([
                'requestId' => $request->id,
                'status' => $this->normalizeStatus($request->status),
            ], 'Custody request approved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /** Accountant-only Execute/Send. This writes durable transfer evidence but posts no money. */
    public function execute(Request $request, string $requestId): JsonResponse
    {
        $user = auth()->user();
        if (! $user instanceof AsabUser
            || ! $user->roleAssignments()->where('role_key', 'accountant')->exists()) {
            return $this->errorResponse('Only an assigned accountant can execute a custody transfer', 403);
        }

        $validator = Validator::make($request->all(), [
            'transferReference' => 'required|string|min:2|max:120',
            'proof' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $custodyRequest = $this->requestService->executeFundingRequest(
                $requestId,
                $user,
                $request->string('transferReference')->toString(),
                $request->file('proof'),
            );

            return $this->successResponse([
                'requestId' => $custodyRequest->id,
                'status' => $this->normalizeStatus($custodyRequest->status),
                'executedAt' => $custodyRequest->executed_at?->toIso8601String(),
                'transferReference' => $custodyRequest->transfer_reference,
                'financiallyPosted' => false,
            ], 'Custody transfer execution recorded; receipt confirmation is still required');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Custody request not found', 404);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            return $this->errorResponse('Could not record custody transfer execution.', 500);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse('Could not record custody transfer execution.', 500);
        }
    }

    /**
     * Confirm the money reached the branch (Branch Manager only)
     * POST /api/custody/requests/{requestId}/confirm-receipt
     *
     * Credits the branch custody balance — this is the only endpoint that
     * writes a cash-in CustodyTransaction for a request.
     */
    public function confirmReceipt(string $requestId): JsonResponse
    {
        $user = auth()->user();
        if (! ($user instanceof BranchManager)) {
            return $this->errorResponse('Only branch managers can confirm receipt of custody', 403);
        }

        try {
            $custodyRequest = $this->requestService->confirmReceipt($requestId, $user);

            return $this->successResponse([
                'requestId' => $custodyRequest->id,
                'status' => $this->normalizeStatus($custodyRequest->status),
                'receivedAt' => $custodyRequest->received_at?->toIso8601String(),
            ], 'Custody receipt confirmed');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Custody request not found', 404);
        } catch (\Illuminate\Database\QueryException $e) {
            // QueryException IS a RuntimeException — without this arm the raw
            // SQL (table, columns, ids) travels to the phone as the message.
            report($e);

            return $this->errorResponse('Could not record the receipt. Please try again.', 500);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Reject a custody request (Brand Owner only)
     * POST /api/custody/requests/{requestId}/reject
     */
    public function reject(Request $request, string $requestId): JsonResponse
    {
        $user = auth()->user();
        if (! ($user instanceof BrandOwner)) {
            return $this->errorResponse('Only brand owners can reject custody requests', 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:500',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $custodyRequest = $this->requestService->rejectRequest($requestId, $user, $request->input('reason'));

            return $this->successResponse([
                'requestId' => $custodyRequest->id,
                'status' => $this->normalizeStatus($custodyRequest->status),
            ], 'Custody request rejected');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Send a rejected request back for review (Branch Manager who raised it)
     * POST /api/custody/requests/{requestId}/resubmit
     *
     * BRC v3.4 FUND-05: the SAME request returns to Pending with its history kept; no money moves.
     */
    public function resubmit(string $requestId): JsonResponse
    {
        $user = auth()->user();
        if (! ($user instanceof BranchManager)) {
            return $this->errorResponse('Only the branch manager who raised the request can resubmit it', 403);
        }

        try {
            $custodyRequest = $this->requestService->resubmitRequest($requestId, $user);

            return $this->successResponse([
                'requestId' => $custodyRequest->id,
                'status' => $this->normalizeStatus($custodyRequest->status),
            ], 'Custody request resubmitted for review');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Custody request not found', 404);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return $this->errorResponse($e->getMessage(), 403);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return $this->errorResponse('Could not resubmit the request. Please try again.', 500);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    private function normalizeStatus(?string $status): string
    {
        return strtolower((string) $status);
    }
}
