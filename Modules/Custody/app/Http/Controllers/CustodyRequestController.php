<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Custody\Services\CustodyRequestService;

class CustodyRequestController extends BaseController
{
    public function __construct(
        private CustodyRequestService $requestService
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
            if (!empty($timePeriod) && !in_array($timePeriod, $validTimePeriods)) {
                return $this->errorResponse(
                    'Invalid timePeriod. Must be: last_24_hours, last_7_days, last_30_days, last_90_days, or last_365_days',
                    400
                );
            }

            $status = $request->input('status');
            $preferredReceiptMethod = null;

            if (!empty($status)) {
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
                        'Invalid status. Must be one of: ' . implode(', ', array_merge($validStatuses, $validMethods)),
                        400
                    );
                }
            }

            if (empty($preferredReceiptMethod)) {
                $preferredReceiptMethod = $request->input('preferredReceiptMethod')
                    ?? $request->input('preferred_receipt_method')
                    ?? $request->input('preferredReceipt');

                if (!empty($preferredReceiptMethod)) {
                    $preferredReceiptMethod = trim(str_replace(['+', '_'], ' ', $preferredReceiptMethod));
                    $validMethods = ['Cash Handover', 'Bank Transfer'];
                    if (!in_array($preferredReceiptMethod, $validMethods)) {
                        return $this->errorResponse(
                            'Invalid preferredReceiptMethod. Must be one of: ' . implode(', ', $validMethods),
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
                $isBrandOwner
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
     * - Branch Manager: existing flow (JSON or form).
     * - Brand Owner: Owner Payment Form contract — multipart/form-data:
     *     recipientEmployeeId, amount, preferredReceiptMethod (cash|bank_transfer),
     *     handoverDate (ISO-8601), note, attachments[]
     */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user instanceof BrandOwner) {
            return $this->storeBrandOwnerPayment($request, $user);
        }

        return $this->storeBranchManagerRequest($request);
    }

    private function storeBrandOwnerPayment(Request $request, BrandOwner $brandOwner): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipientEmployeeId'    => 'required|string|exists:branch_managers,id',
            'amount'                 => 'required|numeric|min:1|max:1000000',
            'preferredReceiptMethod' => 'required|in:cash,bank_transfer,Cash Handover,Bank Transfer',
            'handoverDate'           => 'required|date',
            'note'                   => 'required|string|max:1000',
            'attachments'            => 'nullable|array|max:5',
            'attachments.*'          => 'file|mimes:pdf,jpg,jpeg,png,docx|max:5120',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $method = $request->input('preferredReceiptMethod');
            $normalized = match (strtolower($method)) {
                'cash', 'cash handover' => 'Cash Handover',
                'bank_transfer', 'bank transfer' => 'Bank Transfer',
                default => $method,
            };

            $custodyRequest = $this->requestService->createBrandOwnerPayment([
                'brand_owner_id'           => $brandOwner->id,
                'recipient_employee_id'    => $request->input('recipientEmployeeId'),
                'amount'                   => $request->input('amount'),
                'preferred_receipt_method' => $normalized,
                'handover_date'            => $request->input('handoverDate'),
                'note'                     => $request->input('note'),
                'attachments'              => $request->file('attachments', []),
            ]);

            return $this->createdResponse([
                'requestId'   => $custodyRequest->id,
                'status'      => $this->normalizeStatus($custodyRequest->status),
                'submittedAt' => $custodyRequest->created_at?->toIso8601String(),
            ], 'Cash-in request submitted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    private function storeBranchManagerRequest(Request $request): JsonResponse
    {
        $reuseId = $request->input('reuseRequestId');
        $rules = [
            'requestedAmount' => 'required_without:reuseRequestId|numeric|min:1|max:1000000',
            'purpose' => 'required_without:reuseRequestId|string|min:10|max:500',
            'preferredReceiptMethod' => 'required_without:reuseRequestId|in:Cash Handover,Bank Transfer',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,docx|max:5120',
            'additionalNotes' => 'nullable|string|max:1000',
            'reuseRequestId' => 'nullable|exists:custody_requests,id',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $branchManager = auth()->user();

            $data = [
                'branch_manager_id' => $branchManager->id,
                'branch_id' => $branchManager->branch_id,
                'requestedAmount' => $request->input('requestedAmount'),
                'purpose' => $request->input('purpose'),
                'preferredReceiptMethod' => $request->input('preferredReceiptMethod'),
                'additionalNotes' => $request->input('additionalNotes'),
                'attachments' => $request->file('attachments', []),
            ];

            if ($reuseId) {
                $previousRequest = \Modules\Custody\Models\CustodyRequest::find($reuseId);
                if (!$previousRequest || $previousRequest->branch_manager_id !== $branchManager->id) {
                    return $this->errorResponse('Previous request not found or you are not allowed to reuse it.', 403);
                }
                $data['requestedAmount'] = $request->input('requestedAmount', $previousRequest->requested_amount);
                $data['purpose'] = $request->input('purpose', $previousRequest->purpose);
                $data['preferredReceiptMethod'] = $request->input('preferredReceiptMethod', $previousRequest->preferred_receipt_method);
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

    /**
     * Get previous requests for reuse / Owner Payment Form history
     * GET /api/custody/request-cashin/history
     */
    public function getHistory(): JsonResponse
    {
        try {
            $user = auth()->user();
            $history = $user instanceof BrandOwner
                ? $this->requestService->getBrandOwnerRequestHistory($user->id)
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
        if (!($user instanceof BrandOwner)) {
            return $this->errorResponse('Only brand owners can approve custody requests', 403);
        }

        try {
            $request = $this->requestService->approveRequest($requestId, $user);

            return $this->successResponse([
                'requestId' => $request->id,
                'status'    => $this->normalizeStatus($request->status),
            ], 'Custody request approved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Reject a custody request (Brand Owner only)
     * POST /api/custody/requests/{requestId}/reject
     */
    public function reject(Request $request, string $requestId): JsonResponse
    {
        $user = auth()->user();
        if (!($user instanceof BrandOwner)) {
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
                'status'    => $this->normalizeStatus($custodyRequest->status),
            ], 'Custody request rejected');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    private function normalizeStatus(?string $status): string
    {
        return strtolower((string) $status);
    }
}
