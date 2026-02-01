<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
     * Query Parameters:
     * - timePeriod (optional): Time period filter (last_24_hours, last_7_days, last_30_days, last_90_days, last_365_days)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $timePeriod = $request->input('timePeriod');
            
            // Validate timePeriod if provided
            $validTimePeriods = ['last_24_hours', 'last_7_days', 'last_30_days', 'last_90_days', 'last_365_days'];
            if (!empty($timePeriod) && !in_array($timePeriod, $validTimePeriods)) {
                return $this->errorResponse(
                    'Invalid timePeriod. Must be: last_24_hours, last_7_days, last_30_days, last_90_days, or last_365_days',
                    400
                );
            }

            $requests = $this->requestService->listRequests(auth()->id(), $timePeriod);

            return $this->successResponse([
                'requests' => $requests
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
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'requestedAmount' => 'required|numeric|min:1|max:1000000',
            'purpose' => 'required|string|min:10|max:500',
            'preferredReceiptMethod' => 'required|in:Cash Handover,Bank Transfer',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,docx|max:5120',
            'additionalNotes' => 'nullable|string|max:1000',
            'reuseRequestId' => 'nullable|exists:custody_requests,id',
        ]);

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

            // If reusing a previous request, merge its data
            if ($request->input('reuseRequestId')) {
                $previousRequest = \Modules\Custody\Models\CustodyRequest::find($request->input('reuseRequestId'));
                if ($previousRequest && $previousRequest->branch_manager_id === $branchManager->id) {
                    $data['requestedAmount'] = $request->input('requestedAmount', $previousRequest->requested_amount);
                    $data['purpose'] = $request->input('purpose', $previousRequest->purpose);
                    $data['preferredReceiptMethod'] = $request->input('preferredReceiptMethod', $previousRequest->preferred_receipt_method);
                }
            }

            $custodyRequest = $this->requestService->createRequest($data);

            return $this->createdResponse([
                'requestId' => $custodyRequest->id,
                'status' => $custodyRequest->status,
            ], 'Cash-in request submitted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get previous requests for reuse
     * GET /api/custody/request-cashin/history
     */
    public function getHistory(): JsonResponse
    {
        try {
            $history = $this->requestService->getRequestHistory(auth()->id());

            return $this->successResponse([
                'previousRequests' => $history
            ], 'Request history retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
