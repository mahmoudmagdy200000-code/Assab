<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Custody\Services\CustodyTransactionService;

class CustodyTransactionController extends BaseController
{
    public function __construct(
        private CustodyTransactionService $transactionService
    ) {}

    /**
     * List custody transactions
     * GET /api/custody/transactions
     * 
     * Query Parameters:
     * - type (optional): Transaction type filter (Cash Transfer, Cash Handover, Bank Transfer, Expenses Deduction)
     * - status (optional): Alias for type filter (for consistency with requests endpoint)
     *   Note: Spaces in values can be sent as + or _ in URL (e.g., "Bank Transfer" as "Bank+Transfer" or "Bank_Transfer")
     * - timePeriod (optional): Time period filter (last_24_hours, last_7_days, last_30_days, last_90_days, last_365_days)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $timePeriod = $request->input('timePeriod');
            
            // Normalize empty string to null
            if ($timePeriod === '') {
                $timePeriod = null;
            }
            
            // Validate timePeriod if provided
            $validTimePeriods = ['last_24_hours', 'last_7_days', 'last_30_days', 'last_90_days', 'last_365_days'];
            if (!empty($timePeriod) && !in_array($timePeriod, $validTimePeriods)) {
                return $this->errorResponse(
                    'Invalid timePeriod. Must be: last_24_hours, last_7_days, last_30_days, last_90_days, or last_365_days',
                    400
                );
            }

            // Get type from either 'type' or 'status' parameter (status is alias for consistency)
            $type = $request->input('type') ?? $request->input('status');
            
            // Normalize type value (handle + and _ as spaces)
            if (!empty($type)) {
                $type = trim(str_replace(['+', '_'], ' ', $type));
                $validTypes = ['Cash Transfer', 'Cash Handover', 'Bank Transfer', 'Expenses Deduction'];
                if (!in_array($type, $validTypes)) {
                    return $this->errorResponse(
                        'Invalid type/status. Must be one of: ' . implode(', ', $validTypes),
                        400
                    );
                }
            }

            $filters = [
                'type' => $type,
                'timePeriod' => $timePeriod,
            ];

            $transactions = $this->transactionService->listTransactions(auth()->id(), $filters);

            return $this->successResponse($transactions, 'Transactions retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
