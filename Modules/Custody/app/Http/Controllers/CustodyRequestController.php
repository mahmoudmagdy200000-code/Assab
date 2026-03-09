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
     * - status (optional): Status filter (Pending, Approved, Rejected, Completed, Cancelled)
     *   Note: Spaces in status values can be sent as + or _ in URL (e.g., "Bank Transfer" as "Bank+Transfer" or "Bank_Transfer")
     * - preferredReceiptMethod (optional): Preferred receipt method filter (Cash Handover, Bank Transfer)
     *   Note: Spaces can be sent as + or _ in URL
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

            // Get and normalize status (handle + and _ as spaces)
            $status = $request->input('status');
            $preferredReceiptMethod = null;
            
            if (!empty($status)) {
                // Normalize: replace + and _ with spaces, then trim
                $status = trim(str_replace(['+', '_'], ' ', $status));
                $validStatuses = ['Pending', 'Approved', 'Rejected', 'Completed', 'Cancelled'];
                $validMethods = ['Cash Handover', 'Bank Transfer'];
                
                // Check if it's a status value
                if (in_array($status, $validStatuses)) {
                    // It's a status, keep it as is
                } 
                // Check if it's a preferred receipt method value
                elseif (in_array($status, $validMethods)) {
                    // It's a preferred receipt method, use it for that filter
                    $preferredReceiptMethod = $status;
                    $status = null;
                } else {
                    return $this->errorResponse(
                        'Invalid status. Must be one of: ' . implode(', ', array_merge($validStatuses, $validMethods)),
                        400
                    );
                }
            }

            // Get and normalize preferredReceiptMethod if not already set from status parameter
            // Also check for case variations of the parameter name
            if (empty($preferredReceiptMethod)) {
                $preferredReceiptMethod = $request->input('preferredReceiptMethod') 
                    ?? $request->input('preferred_receipt_method')
                    ?? $request->input('preferredReceipt');
                
                if (!empty($preferredReceiptMethod)) {
                    // Normalize: replace + and _ with spaces, then trim
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

            // Debug: Log the parameters being sent to the service
            // \Log::info('CustodyRequestController::index', [
            //     'status' => $status,
            //     'preferredReceiptMethod' => $preferredReceiptMethod,
            //     'timePeriod' => $timePeriod,
            //     'all_inputs' => $request->all(),
            // ]);

            $requests = $this->requestService->listRequests(
                auth()->id(), 
                $timePeriod, 
                $status, 
                $preferredReceiptMethod
            );

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
     *
     * When reuseRequestId is sent: create a new request using the past request's data (amount, purpose, preferredReceiptMethod).
     * Other fields can still be overridden; if only reuseRequestId is sent, all data is taken from the past request.
     */
    public function store(Request $request): JsonResponse
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
