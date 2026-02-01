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
     * - type (optional): Transaction type filter
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

            $filters = [
                'type' => $request->input('type'),
                'timePeriod' => $timePeriod,
            ];

            $transactions = $this->transactionService->listTransactions(auth()->id(), $filters);

            return $this->successResponse($transactions, 'Transactions retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
